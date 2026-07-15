<?php

namespace App\Services\Bots;

use App\Data\Bots\MaxVideoAttachmentDownloadData;
use App\Data\Messages\DownloadedMediaStreamData;
use App\Data\Messages\InboundMediaDownloadFailureDecision;
use App\Jobs\DownloadBotMessageAttachmentJob;
use App\Models\Channel;
use App\Models\Dialog;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Services\Dialogs\DialogAutomationGate;
use App\Services\Messages\InboundMediaAdmissionDeniedException;
use App\Services\Messages\InboundMediaAdmissionGate;
use App\Services\Messages\InboundMediaDownloadFailureClassifier;
use App\Services\Messages\InboundMediaDownloadPolicy;
use App\Services\Messages\InboundMediaQuotaExceededException;
use App\Services\Messages\InboundMediaQuotaLedger;
use App\Services\Messages\InboundMediaRetrySchedule;
use App\Services\Messages\MediaDownloadLeaseLostException;
use App\Services\Messages\ResolvePinnedHttpsUrlAction;
use App\Services\Messages\StoreMessageAttachmentLocalFileAction;
use App\Services\Messages\StreamHttpResponseToTemporaryFileAction;
use App\Services\Messages\ValidateInboundMediaIntegrityAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class DownloadBotMessageAttachmentsAction
{
    public function __construct(
        private readonly TelegramBotApiService $telegramBotApiService,
        private readonly MaxBotApiService $maxBotApiService,
        private readonly StoreMessageAttachmentLocalFileAction $storeMessageAttachmentLocalFileAction,
        private readonly DialogAutomationGate $dialogAutomationGate,
        private readonly InboundMediaDownloadPolicy $mediaDownloadPolicy,
        private readonly InboundMediaDownloadFailureClassifier $failureClassifier,
        private readonly InboundMediaQuotaLedger $quotaLedger,
        private readonly InboundMediaRetrySchedule $retrySchedule,
        private readonly InboundMediaAdmissionGate $admissionGate,
        private readonly ResolvePinnedHttpsUrlAction $resolvePinnedHttpsUrlAction,
        private readonly StreamHttpResponseToTemporaryFileAction $streamHttpResponseToTemporaryFileAction,
        private readonly ValidateInboundMediaIntegrityAction $validateInboundMediaIntegrityAction,
    ) {}

    /**
     * @param  list<int>|null  $attachmentIds
     */
    public function handle(Message $message, ?array $attachmentIds = null, bool $manual = false): void
    {
        $message->load(['channel', 'attachments', 'dialog.dialogStage']);

        if (! $manual && ! $this->dialogAutomationGate->acceptsMessage($message)) {
            $this->markBlacklistedAttachmentsMetadataOnly($message);

            return;
        }

        $channel = $message->channel;

        if (! $channel instanceof Channel) {
            return;
        }

        $attachmentIds = $attachmentIds === null
            ? null
            : array_values(array_unique(array_map('intval', $attachmentIds)));

        foreach ($message->attachments as $attachment) {
            if (
                ! $attachment instanceof MessageAttachment
                || ($attachmentIds !== null && ! in_array((int) $attachment->id, $attachmentIds, true))
                || ! $this->shouldDownload($attachment, $manual)
            ) {
                continue;
            }

            $this->download($channel, $message, $attachment, $manual);
        }

        $message->unsetRelation('attachments');
    }

    private function markBlacklistedAttachmentsMetadataOnly(Message $message): void
    {
        DB::transaction(function () use ($message): void {
            $attachments = MessageAttachment::query()
                ->where('message_id', $message->id)
                ->whereIn('provider', [
                    MessageAttachment::PROVIDER_TELEGRAM_BOT,
                    MessageAttachment::PROVIDER_MAX_BOT,
                ])
                ->whereIn('download_status', [
                    MessageAttachment::DOWNLOAD_STATUS_METADATA_ONLY,
                    MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD,
                ])
                ->whereNull('manual_download_requested_at')
                ->lockForUpdate()
                ->get();

            foreach ($attachments as $attachment) {
                $attachment->forceFill([
                    'download_status' => MessageAttachment::DOWNLOAD_STATUS_METADATA_ONLY,
                    'local_disk' => null,
                    'local_path' => null,
                    'safe_error_code' => InboundMediaDownloadPolicy::REASON_BLACKLIST_STAGE,
                    'safe_error_message' => 'Media download skipped because the dialog stage is blacklisted.',
                ])->save();
            }
        });

        $message->unsetRelation('attachments');
    }

    private function shouldDownload(MessageAttachment $attachment, bool $manual): bool
    {
        if (! $this->mediaDownloadPolicy->supports(
            (string) $attachment->provider,
            (string) $attachment->media_kind,
        )) {
            return false;
        }

        if ($attachment->download_status !== MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD) {
            return false;
        }

        if (
            $attachment->media_download_next_retry_at !== null
            && $attachment->media_download_next_retry_at->isFuture()
        ) {
            return false;
        }

        return $manual
            ? $attachment->manual_download_requested_at !== null
            : $attachment->manual_download_requested_at === null;
    }

    private function download(
        Channel $channel,
        Message $message,
        MessageAttachment $attachment,
        bool $manual,
    ): ?MessageAttachment {
        $previousFailureReason = $attachment->safe_error_code;

        try {
            $claimedAttachment = $this->claimDownload($channel, $attachment, $manual);
        } catch (InboundMediaAdmissionDeniedException $exception) {
            DownloadBotMessageAttachmentJob::dispatch((int) $attachment->id, $manual)
                ->delay(now()->addSeconds($exception->retryAfterSeconds));

            return $attachment->fresh();
        }

        if (! $claimedAttachment instanceof MessageAttachment) {
            return $attachment->fresh();
        }

        $attachment = $claimedAttachment;

        $downloaded = null;

        try {
            $downloaded = match ($attachment->provider) {
                MessageAttachment::PROVIDER_TELEGRAM_BOT => $this->downloadTelegramBotFile($channel, $message, $attachment),
                MessageAttachment::PROVIDER_MAX_BOT => $this->downloadMaxBotMedia($channel, $message, $attachment),
                default => null,
            };

            if (! $downloaded instanceof DownloadedMediaStreamData) {
                return $this->markFailed(
                    $attachment,
                    new InboundMediaDownloadFailureDecision(
                        reason: 'bot_media_download_unsupported_provider',
                        retryable: false,
                    ),
                );
            }

            return $this->storeDownloadedAttachment($message, $attachment, $downloaded);
        } catch (MediaDownloadLeaseLostException) {
            return $attachment->fresh();
        } catch (InboundMediaQuotaExceededException $exception) {
            return $this->markQuotaBlocked($attachment, $exception);
        } catch (Throwable $throwable) {
            return $this->markFailed(
                $attachment,
                $this->failureClassifier->classify(
                    $throwable,
                    $previousFailureReason,
                ),
            );
        } finally {
            if ($downloaded instanceof DownloadedMediaStreamData) {
                $downloaded->close();
            }
        }
    }

    private function downloadTelegramBotFile(
        Channel $channel,
        Message $message,
        MessageAttachment $attachment,
    ): DownloadedMediaStreamData {
        $fileId = $this->resolveTelegramBotDownloadFileId($message, $attachment);

        if (! filled($fileId)) {
            throw new InvalidArgumentException('Telegram Bot media download requires provider_file_id.');
        }

        $downloaded = $this->telegramBotApiService->downloadFileToStream(
            $channel,
            (string) $fileId,
            $this->maxBytes($attachment),
            fn (int $receivedBytes) => $this->checkpointDownloadProgress($attachment, $receivedBytes),
        );

        if ($fileId === $this->normalizeScalar($attachment->provider_file_id)) {
            return $downloaded;
        }

        return $downloaded->withMetadata([
            ...$downloaded->metadata,
            'telegram_preview_source' => 'thumbnail',
            'telegram_preview_file_id' => $fileId,
            'telegram_original_file_id' => $this->normalizeScalar($attachment->provider_file_id),
            'width' => $this->normalizeNonNegativeInteger(data_get($attachment->raw_payload_excerpt, 'thumbnail_width')),
            'height' => $this->normalizeNonNegativeInteger(data_get($attachment->raw_payload_excerpt, 'thumbnail_height')),
        ]);
    }

    private function downloadMaxBotMedia(
        Channel $channel,
        Message $message,
        MessageAttachment $attachment,
    ): DownloadedMediaStreamData {
        $downloadData = $this->resolveMaxMediaDownloadData($channel, $message, $attachment);
        $url = $downloadData?->downloadUrl;

        if ($url === null) {
            throw new InvalidArgumentException('MAX media URL is missing from raw payload.');
        }

        $trustedHosts = array_values(array_filter(
            (array) config('bots.max.trusted_media_hosts', config('bots.max.trusted_avatar_hosts', ['max.ru'])),
            static fn (mixed $value): bool => is_string($value) && trim($value) !== '',
        ));
        $pinnedUrl = $this->resolvePinnedHttpsUrlAction->handle(
            $url,
            $trustedHosts,
            (array) config('bots.max.pinned_media_ips', []),
        );

        $response = Http::withOptions([
            'stream' => true,
            'allow_redirects' => false,
            'connect_timeout' => 10,
            'read_timeout' => $this->streamReadTimeoutSeconds(),
            'timeout' => max(30, (int) config('inbound_media.attempt_deadline_seconds', 6 * 60 * 60)),
            'curl' => $pinnedUrl->curlOptions,
        ])
            ->withoutRedirecting()
            ->get($pinnedUrl->url)
            ->throw();

        return $this->streamHttpResponseToTemporaryFileAction->handle(
            response: $response,
            maxBytes: $this->maxBytes($attachment),
            filenameHint: $this->filenameHintFromUrl($pinnedUrl->url),
            metadata: $downloadData->metadata(),
            onProgress: fn (int $receivedBytes) => $this->checkpointDownloadProgress($attachment, $receivedBytes),
            tooLargeMessage: 'MAX media file is larger than the local download limit.',
            emptyMessage: 'MAX media download returned an empty body.',
        );
    }

    private function storeDownloadedAttachment(
        Message $message,
        MessageAttachment $attachment,
        DownloadedMediaStreamData $downloaded,
    ): MessageAttachment {
        $this->checkpointDownloadProgress($attachment, $downloaded->sizeBytes);

        return DB::transaction(function () use ($message, $attachment, $downloaded): MessageAttachment {
            $dialogId = Message::query()
                ->whereKey($message->id)
                ->value('dialog_id');
            $lockedDialog = $dialogId === null
                ? null
                : Dialog::query()
                    ->with('dialogStage')
                    ->whereKey($dialogId)
                    ->lockForUpdate()
                    ->first();
            $lockedMessage = Message::query()
                ->whereKey($message->id)
                ->lockForUpdate()
                ->first();
            $lockedAttachment = MessageAttachment::query()
                ->whereKey($attachment->id)
                ->lockForUpdate()
                ->first();

            if (
                ($dialogId !== null && ! $lockedDialog instanceof Dialog)
                || ! $lockedMessage instanceof Message
                || ! $lockedAttachment instanceof MessageAttachment
                || (int) $lockedMessage->dialog_id !== (int) $dialogId
                || (int) $lockedAttachment->message_id !== (int) $lockedMessage->id
                || $lockedAttachment->download_status !== MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING
                || blank($attachment->media_download_claim_token)
                || blank($lockedAttachment->media_download_claim_token)
                || ! hash_equals(
                    (string) $lockedAttachment->media_download_claim_token,
                    (string) $attachment->media_download_claim_token,
                )
            ) {
                throw new MediaDownloadLeaseLostException;
            }

            if (
                $lockedAttachment->manual_download_requested_at === null
                && ! $this->dialogAutomationGate->accepts($lockedDialog)
            ) {
                return $this->markLockedMetadataOnlyBecauseBlacklisted(
                    $lockedAttachment,
                    $downloaded->sizeBytes,
                );
            }

            return $this->storeDownloadedAttachmentUnderLock($lockedAttachment, $downloaded);
        }, 3);
    }

    private function storeDownloadedAttachmentUnderLock(
        MessageAttachment $attachment,
        DownloadedMediaStreamData $downloaded,
    ): MessageAttachment {
        $attemptNumber = $attachment->mediaDownloadLedgerAttemptNumber();
        $this->quotaLedger->assertCanCompleteAttempt(
            $attachment,
            $attemptNumber,
            $downloaded->sizeBytes,
        );

        $filename = $this->filenameFromHint($downloaded->filenameHint);
        $headerMimeType = $this->normalizeMimeType($downloaded->contentType);
        $attachmentExtension = MessageAttachment::sanitizeExtension($attachment->extension);
        $providerMetadata = $this->mergedProviderMetadata($attachment, $downloaded->metadata);
        $isTelegramStickerThumbnail = $this->isTelegramStickerThumbnailDownload($attachment, $providerMetadata);
        $providerSizeBytes = $isTelegramStickerThumbnail
            ? null
            : $this->normalizeNonNegativeInteger(data_get($downloaded->metadata, 'provider_declared_size_bytes'));
        $detectedMimeType = $this->validateInboundMediaIntegrityAction->inspectStream(
            attachment: $attachment,
            stream: $downloaded->stream,
            actualSizeBytes: $downloaded->sizeBytes,
            providerSizeBytes: $providerSizeBytes,
            declaredMimeType: $headerMimeType ?? $attachment->mime_type,
        );
        $extension = $this->extensionFromMimeType($headerMimeType)
            ?? ($isTelegramStickerThumbnail ? $this->extensionFromFilename($filename) : null)
            ?? ($attachmentExtension !== '' ? $attachmentExtension : null)
            ?? $this->extensionFromFilename($filename);
        $mimeType = $headerMimeType
            ?? ($isTelegramStickerThumbnail ? $this->mimeTypeFromExtension($extension) : null)
            ?? $attachment->mime_type
            ?? $this->mimeTypeFromExtension($extension)
            ?? $detectedMimeType;

        if ($extension === null && $mimeType !== null) {
            $extension = $this->extensionFromMimeType($mimeType);
        }

        $values = [
            'mime_type' => $mimeType,
            'extension' => $extension,
            'original_filename' => $isTelegramStickerThumbnail
                ? 'sticker-preview.'.($extension ?: 'bin')
                : ($attachment->original_filename ?? $filename),
            'file_size_bytes' => $downloaded->sizeBytes,
        ];

        $isMaxVideoNote = $this->shouldTreatMaxVideoAsVideoNote($attachment, $providerMetadata);

        if ($isMaxVideoNote) {
            $values['media_kind'] = MessageAttachment::MEDIA_KIND_VIDEO_NOTE;
            $providerMetadata['is_video_note'] = true;
        }

        if ($providerMetadata !== []) {
            $values['provider_metadata'] = $providerMetadata;
        }

        $rawPayloadExcerpt = $this->mergedRawPayloadExcerpt($attachment, $providerMetadata, $isMaxVideoNote);

        if ($rawPayloadExcerpt !== []) {
            $values['raw_payload_excerpt'] = $rawPayloadExcerpt;
        }

        rewind($downloaded->stream);

        $stored = $this->storeMessageAttachmentLocalFileAction->handleStream(
            $attachment,
            $downloaded->stream,
            $downloaded->sizeBytes,
            $extension,
            function (MessageAttachment $storedAttachment) use ($attemptNumber, $downloaded): void {
                $this->quotaLedger->completeAttempt(
                    $storedAttachment,
                    $attemptNumber,
                    $downloaded->sizeBytes,
                );
            },
            expectedClaimToken: (string) $attachment->media_download_claim_token,
            attachmentValues: $values,
        );

        return $stored;
    }

    private function markLockedMetadataOnlyBecauseBlacklisted(
        MessageAttachment $attachment,
        int $transferredBytes,
    ): MessageAttachment {
        $this->quotaLedger->failAttempt(
            $attachment,
            $attachment->mediaDownloadLedgerAttemptNumber(),
            $transferredBytes,
            InboundMediaDownloadPolicy::REASON_BLACKLIST_STAGE,
        );

        $attachment->forceFill([
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_METADATA_ONLY,
            'local_disk' => null,
            'local_path' => null,
            'manual_download_requested_at' => null,
            'manual_download_requested_by_user_id' => null,
            'media_download_claim_token' => null,
            'media_download_attempts' => max(0, (int) $attachment->media_download_attempts - 1),
            'media_download_upload_size_bytes' => null,
            'media_download_next_retry_at' => null,
            'media_download_claimed_at' => null,
            'media_download_heartbeat_at' => null,
            'media_download_attempt_deadline_at' => null,
            'safe_error_code' => InboundMediaDownloadPolicy::REASON_BLACKLIST_STAGE,
            'safe_error_message' => 'Media download skipped because the dialog stage is blacklisted.',
        ])->save();

        return $attachment->refresh();
    }

    private function checkpointDownloadProgress(MessageAttachment $attachment, int $receivedBytes): void
    {
        $active = DB::transaction(function () use ($attachment, $receivedBytes): bool {
            $dialogId = MessageAttachment::query()
                ->whereKey($attachment->id)
                ->join('messages', 'messages.id', '=', 'message_attachments.message_id')
                ->value('messages.dialog_id');
            $lockedDialog = $dialogId === null
                ? null
                : Dialog::query()
                    ->with('dialogStage')
                    ->whereKey($dialogId)
                    ->lockForUpdate()
                    ->first();
            $lockedMessage = Message::query()
                ->whereKey($attachment->message_id)
                ->lockForUpdate()
                ->first();
            $lockedAttachment = MessageAttachment::query()
                ->whereKey($attachment->id)
                ->lockForUpdate()
                ->first();

            if (
                ! $lockedMessage instanceof Message
                || ! $lockedAttachment instanceof MessageAttachment
                || (int) $lockedAttachment->message_id !== (int) $lockedMessage->id
                || $lockedAttachment->download_status !== MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING
                || blank($attachment->media_download_claim_token)
                || blank($lockedAttachment->media_download_claim_token)
                || ! hash_equals(
                    (string) $lockedAttachment->media_download_claim_token,
                    (string) $attachment->media_download_claim_token,
                )
                || $lockedAttachment->media_download_attempt_deadline_at === null
                || $lockedAttachment->media_download_attempt_deadline_at->isPast()
            ) {
                return false;
            }

            if ($lockedMessage->removed_at !== null) {
                $this->markLockedSourceUnavailable($lockedAttachment, $receivedBytes);

                return false;
            }

            if (
                $lockedAttachment->manual_download_requested_at === null
                && ! $this->dialogAutomationGate->accepts($lockedDialog)
            ) {
                $this->markLockedMetadataOnlyBecauseBlacklisted($lockedAttachment, $receivedBytes);

                return false;
            }

            $lockedAttachment->forceFill([
                'media_download_heartbeat_at' => now(),
            ])->save();

            $this->quotaLedger->checkpointTraffic(
                $lockedAttachment,
                $lockedAttachment->mediaDownloadLedgerAttemptNumber(),
                $receivedBytes,
            );

            return true;
        }, 3);

        if (! $active) {
            throw new MediaDownloadLeaseLostException;
        }
    }

    private function markLockedSourceUnavailable(
        MessageAttachment $attachment,
        int $transferredBytes,
    ): void {
        $this->quotaLedger->failAttempt(
            $attachment,
            $attachment->mediaDownloadLedgerAttemptNumber(),
            $transferredBytes,
            InboundMediaDownloadPolicy::REASON_SOURCE_UNAVAILABLE,
        );

        $attachment->forceFill([
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED,
            'manual_download_requested_at' => null,
            'manual_download_requested_by_user_id' => null,
            'media_download_claim_token' => null,
            'media_download_upload_size_bytes' => null,
            'media_download_next_retry_at' => null,
            'media_download_claimed_at' => null,
            'media_download_heartbeat_at' => null,
            'media_download_attempt_deadline_at' => null,
            'safe_error_code' => InboundMediaDownloadPolicy::REASON_SOURCE_UNAVAILABLE,
            'safe_error_message' => 'Источник больше недоступен.',
        ])->save();
    }

    private function resolveTelegramBotDownloadFileId(Message $message, MessageAttachment $attachment): ?string
    {
        if ($this->shouldUseTelegramStickerThumbnail($attachment)) {
            return $this->resolveTelegramStickerThumbnailFileId($message, $attachment)
                ?? $this->normalizeScalar($attachment->provider_file_id);
        }

        return $this->normalizeScalar($attachment->provider_file_id);
    }

    private function shouldUseTelegramStickerThumbnail(MessageAttachment $attachment): bool
    {
        if (
            $attachment->provider !== MessageAttachment::PROVIDER_TELEGRAM_BOT
            || $attachment->media_kind !== MessageAttachment::MEDIA_KIND_STICKER
        ) {
            return false;
        }

        return data_get($attachment->raw_payload_excerpt, 'is_animated') === true
            || MessageAttachment::sanitizeExtension($attachment->extension) === 'tgs'
            || $attachment->downloadMimeType() === 'application/x-tgsticker';
    }

    private function resolveTelegramStickerThumbnailFileId(Message $message, MessageAttachment $attachment): ?string
    {
        $excerptThumbnailFileId = $this->normalizeScalar(data_get($attachment->raw_payload_excerpt, 'thumbnail_file_id'));

        if ($excerptThumbnailFileId !== null) {
            return $excerptThumbnailFileId;
        }

        $payload = is_array($message->raw_payload) ? $message->raw_payload : [];
        $sticker = data_get($payload, 'message.sticker');

        if (! is_array($sticker)) {
            return null;
        }

        $stickerUniqueId = $this->normalizeScalar(data_get($sticker, 'file_unique_id'));
        $attachmentUniqueId = $this->normalizeScalar($attachment->provider_file_unique_id)
            ?? $this->normalizeScalar($attachment->provider_attachment_key);

        if ($stickerUniqueId !== null && $attachmentUniqueId !== null && $stickerUniqueId !== $attachmentUniqueId) {
            return null;
        }

        return $this->normalizeScalar(data_get($sticker, 'thumbnail.file_id'))
            ?? $this->normalizeScalar(data_get($sticker, 'thumb.file_id'));
    }

    /**
     * @param  array<string, mixed>  $providerMetadata
     */
    private function isTelegramStickerThumbnailDownload(MessageAttachment $attachment, array $providerMetadata): bool
    {
        return $attachment->provider === MessageAttachment::PROVIDER_TELEGRAM_BOT
            && $attachment->media_kind === MessageAttachment::MEDIA_KIND_STICKER
            && data_get($providerMetadata, 'telegram_preview_source') === 'thumbnail';
    }

    private function claimDownload(
        Channel $channel,
        MessageAttachment $attachment,
        bool $manual,
    ): ?MessageAttachment {
        return DB::transaction(function () use ($channel, $attachment, $manual): ?MessageAttachment {
            $this->admissionGate->lock();

            $locked = MessageAttachment::query()
                ->whereKey($attachment->id)
                ->lockForUpdate()
                ->first();

            if (
                ! $locked instanceof MessageAttachment
                || ! $this->shouldDownload($locked, $manual)
                || $this->hasLocalFile($locked)
            ) {
                return null;
            }

            $this->admissionGate->assertCanClaim($channel, $manual, (int) $locked->id);

            $attemptNumber = max(0, (int) $locked->media_download_attempts) + 1;
            $leaseSequence = max(
                0,
                (int) $locked->media_download_lease_sequence,
                (int) $locked->media_download_attempts,
            ) + 1;
            $quotaDecision = $this->quotaLedger->reserveForAttempt($locked, $leaseSequence);

            if (! $quotaDecision->allowed) {
                $locked->forceFill([
                    'download_status' => MessageAttachment::DOWNLOAD_STATUS_AVAILABLE_ON_DEMAND,
                    'manual_download_requested_at' => null,
                    'manual_download_requested_by_user_id' => null,
                    'media_download_claim_token' => null,
                    'media_download_claimed_at' => null,
                    'media_download_heartbeat_at' => null,
                    'media_download_attempt_deadline_at' => null,
                    'safe_error_code' => $quotaDecision->reason,
                    'safe_error_message' => $this->quotaErrorMessage($quotaDecision->reason),
                ])->save();

                return null;
            }

            $claimedAt = now();

            $locked->forceFill([
                'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING,
                'media_download_claim_token' => Str::uuid()->toString(),
                'media_download_attempts' => $attemptNumber,
                'media_download_lease_sequence' => $leaseSequence,
                'media_download_trigger' => $manual ? 'manual' : 'auto',
                'media_download_claimed_at' => $claimedAt,
                'media_download_heartbeat_at' => $claimedAt,
                'media_download_attempt_deadline_at' => $claimedAt->copy()->addSeconds(
                    max(1, (int) config('inbound_media.attempt_deadline_seconds', 6 * 60 * 60)),
                ),
                'safe_error_code' => null,
                'safe_error_message' => null,
            ])->save();

            $this->admissionGate->recordClaim($channel, $manual);

            return $locked->fresh();
        });
    }

    private function markFailed(
        MessageAttachment $attachment,
        InboundMediaDownloadFailureDecision $decision,
    ): MessageAttachment {
        $failedAttachment = DB::transaction(function () use ($attachment, $decision): MessageAttachment {
            $locked = MessageAttachment::query()
                ->whereKey($attachment->id)
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof MessageAttachment) {
                return $attachment;
            }

            if (
                $locked->download_status === MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED
                || $this->hasLocalFile($locked)
                || $locked->download_status !== MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING
                || blank($attachment->media_download_claim_token)
                || blank($locked->media_download_claim_token)
                || ! hash_equals(
                    (string) $locked->media_download_claim_token,
                    (string) $attachment->media_download_claim_token,
                )
            ) {
                return $locked;
            }

            $attemptNumber = max(1, (int) $locked->media_download_attempts);
            $willRetry = $decision->retryable && $this->retrySchedule->willRetry($attemptNumber);
            $errorCode = $willRetry || ! $decision->retryable
                ? $decision->reason
                : $this->retrySchedule->terminalErrorCode($decision->reason);
            $nextRetryAt = $willRetry
                ? $this->retrySchedule->nextRetryAt($attemptNumber, $decision->retryAfterSeconds)
                : null;

            $this->quotaLedger->failAttempt(
                $locked,
                $locked->mediaDownloadLedgerAttemptNumber(),
                0,
                $this->normalizeErrorCode($errorCode),
            );

            $locked->forceFill([
                'download_status' => $willRetry
                    ? MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD
                    : MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED,
                'local_disk' => null,
                'local_path' => null,
                'media_download_claim_token' => null,
                'media_download_next_retry_at' => $nextRetryAt,
                'media_download_claimed_at' => null,
                'media_download_heartbeat_at' => null,
                'media_download_attempt_deadline_at' => null,
                'safe_error_code' => $this->normalizeErrorCode($errorCode),
                'safe_error_message' => $this->resolveSafeErrorMessage($locked, $willRetry),
            ])->save();

            return $locked->refresh();
        });

        if (
            $failedAttachment->download_status === MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD
            && $failedAttachment->manual_download_requested_at !== null
            && $failedAttachment->media_download_next_retry_at !== null
        ) {
            DownloadBotMessageAttachmentJob::dispatch((int) $failedAttachment->id)
                ->delay($failedAttachment->media_download_next_retry_at);
        }

        return $failedAttachment;
    }

    private function markQuotaBlocked(
        MessageAttachment $attachment,
        InboundMediaQuotaExceededException $exception,
    ): MessageAttachment {
        return DB::transaction(function () use ($attachment, $exception): MessageAttachment {
            /** @var MessageAttachment $locked */
            $locked = MessageAttachment::query()
                ->whereKey($attachment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $locked->download_status !== MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING
                || blank($attachment->media_download_claim_token)
                || blank($locked->media_download_claim_token)
                || ! hash_equals(
                    (string) $locked->media_download_claim_token,
                    (string) $attachment->media_download_claim_token,
                )
            ) {
                return $locked;
            }

            $this->quotaLedger->failAttempt(
                $locked,
                $locked->mediaDownloadLedgerAttemptNumber(),
                $exception->transferredBytes,
                $exception->reason,
            );

            $locked->forceFill([
                'download_status' => MessageAttachment::DOWNLOAD_STATUS_AVAILABLE_ON_DEMAND,
                'manual_download_requested_at' => null,
                'manual_download_requested_by_user_id' => null,
                'local_disk' => null,
                'local_path' => null,
                'media_download_claim_token' => null,
                'media_download_attempts' => $exception->reason === InboundMediaDownloadPolicy::REASON_MANUAL_HARD_LIMIT
                    ? (int) $locked->media_download_attempts
                    : max(0, (int) $locked->media_download_attempts - 1),
                'media_download_next_retry_at' => null,
                'media_download_claimed_at' => null,
                'media_download_heartbeat_at' => null,
                'media_download_attempt_deadline_at' => null,
                'safe_error_code' => $exception->reason,
                'safe_error_message' => $this->quotaErrorMessage($exception->reason),
            ])->save();

            return $locked->fresh();
        }, 3);
    }

    private function hasLocalFile(MessageAttachment $attachment): bool
    {
        return filled($attachment->local_disk) && filled($attachment->local_path);
    }

    private function quotaErrorMessage(?string $reason): ?string
    {
        return match ($reason) {
            InboundMediaDownloadPolicy::REASON_SIZE_ABOVE_AUTO_LIMIT => null,
            InboundMediaDownloadPolicy::REASON_TRAFFIC_QUOTA_EXCEEDED => 'Дневной лимит загрузки медиа для канала исчерпан.',
            default => 'Недостаточно доступного места для загрузки медиа.',
        };
    }

    private function streamReadTimeoutSeconds(): int
    {
        return max(
            1,
            min(
                90,
                max(1, (int) config('inbound_media.lease_stale_seconds', 120)) - 30,
            ),
        );
    }

    private function resolveMaxMediaDownloadData(
        Channel $channel,
        Message $message,
        MessageAttachment $attachment,
    ): ?MaxVideoAttachmentDownloadData {
        $reference = $this->normalizeScalar($attachment->provider_file_reference)
            ?? $this->normalizeScalar($attachment->provider_attachment_key);

        if ($reference === null) {
            return null;
        }

        foreach ($this->maxAttachmentCandidates($message) as $index => $candidate) {
            if ($this->resolveMaxAttachmentReference($candidate, $index) !== $reference) {
                continue;
            }

            // payload.url у MAX одноразовый/короткоживущий (живой QA 04.07: 400 при
            // повторном GET) — для видео, кружков, аудио и голосовых берём свежий URL
            // через videos-API (он универсален для всей медиа-фермы okcdn, включая audio).
            if (in_array($attachment->media_kind, [
                MessageAttachment::MEDIA_KIND_VIDEO,
                MessageAttachment::MEDIA_KIND_VIDEO_NOTE,
                MessageAttachment::MEDIA_KIND_AUDIO,
                MessageAttachment::MEDIA_KIND_VOICE,
            ], true)) {
                $mediaToken = $this->resolveMaxAttachmentToken($candidate);

                if ($mediaToken !== null) {
                    return $this->maxBotApiService->fetchVideoAttachmentDownloadData($channel, $mediaToken);
                }

                // Токена нет — падаем обратно на прямой URL из payload (лучше попытка,
                // чем гарантированный отказ).
            }

            if ($attachment->media_kind === MessageAttachment::MEDIA_KIND_STICKER) {
                return $this->resolveMaxStickerDownloadData($channel, $message, $reference, $candidate);
            }

            $url = $this->resolveMaxAttachmentUrl($candidate);

            if ($url !== null) {
                return new MaxVideoAttachmentDownloadData(downloadUrl: $url);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $webhookCandidate
     */
    private function resolveMaxStickerDownloadData(
        Channel $channel,
        Message $message,
        string $reference,
        array $webhookCandidate,
    ): ?MaxVideoAttachmentDownloadData {
        $webhookUrl = $this->resolveMaxAttachmentUrl($webhookCandidate);

        if ($webhookUrl !== null && ! $this->isMaxStickerStubUrl($webhookUrl)) {
            return $this->maxAttachmentDownloadData($webhookUrl, $webhookCandidate);
        }

        $messageId = $this->normalizeScalar($message->external_message_id)
            ?? $this->normalizeScalar($message->provider_event_key);

        if ($messageId === null) {
            return null;
        }

        $messagePayload = $this->maxBotApiService->fetchMessage($channel, $messageId);

        foreach ($this->maxAttachmentCandidatesFromPayload($messagePayload) as $index => $candidate) {
            if ($this->resolveMaxAttachmentReference($candidate, $index) !== $reference) {
                continue;
            }

            $url = $this->resolveMaxAttachmentUrl($candidate);

            if ($url !== null && ! $this->isMaxStickerStubUrl($url)) {
                return $this->maxAttachmentDownloadData($url, $candidate);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $attachment
     */
    private function maxAttachmentDownloadData(string $url, array $attachment): MaxVideoAttachmentDownloadData
    {
        return new MaxVideoAttachmentDownloadData(
            downloadUrl: $url,
            width: $this->normalizeNonNegativeInteger(
                data_get($attachment, 'payload.width')
                    ?? data_get($attachment, 'width')
            ),
            height: $this->normalizeNonNegativeInteger(
                data_get($attachment, 'payload.height')
                    ?? data_get($attachment, 'height')
            ),
            duration: $this->normalizeNonNegativeInteger(
                data_get($attachment, 'payload.duration')
                    ?? data_get($attachment, 'duration')
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $downloadMetadata
     * @return array<string, mixed>
     */
    private function mergedProviderMetadata(MessageAttachment $attachment, array $downloadMetadata): array
    {
        $metadata = is_array($attachment->provider_metadata) ? $attachment->provider_metadata : [];

        foreach ([
            'width',
            'height',
            'duration',
            'telegram_preview_source',
            'telegram_preview_file_id',
            'telegram_original_file_id',
        ] as $key) {
            $value = $this->normalizeNonNegativeInteger(data_get($downloadMetadata, $key));

            if (str_starts_with($key, 'telegram_')) {
                $value = $this->normalizeScalar(data_get($downloadMetadata, $key));
            }

            if ($value !== null) {
                $metadata[$key] = $value;
            }
        }

        return array_filter($metadata, static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function shouldTreatMaxVideoAsVideoNote(MessageAttachment $attachment, array $metadata): bool
    {
        if (
            $attachment->provider !== MessageAttachment::PROVIDER_MAX_BOT
            || ! in_array($attachment->media_kind, [MessageAttachment::MEDIA_KIND_VIDEO, MessageAttachment::MEDIA_KIND_VIDEO_NOTE], true)
        ) {
            return false;
        }

        $width = $this->normalizeNonNegativeInteger(data_get($metadata, 'width'));
        $height = $this->normalizeNonNegativeInteger(data_get($metadata, 'height'));

        if ($width === null || $height === null || $width === 0 || $height === 0) {
            return false;
        }

        $maxSide = max($width, $height);
        $minSide = min($width, $height);
        $duration = $this->normalizeNonNegativeInteger(data_get($metadata, 'duration'));

        return (($maxSide - $minSide) / $maxSide) <= 0.03
            && $maxSide <= 720
            && ($duration === null || $duration <= 60);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function mergedRawPayloadExcerpt(
        MessageAttachment $attachment,
        array $metadata,
        bool $isVideoNote,
    ): array {
        $excerpt = is_array($attachment->raw_payload_excerpt) ? $attachment->raw_payload_excerpt : [];

        foreach (['width', 'height', 'duration'] as $key) {
            $value = $this->normalizeNonNegativeInteger(data_get($metadata, $key));

            if ($value !== null) {
                $excerpt[$key] = $value;
            }
        }

        if ($isVideoNote) {
            $excerpt['media_kind'] = MessageAttachment::MEDIA_KIND_VIDEO_NOTE;
            $excerpt['is_video_note'] = true;
        }

        return array_filter($excerpt, static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $attachment
     */
    private function resolveMaxAttachmentReference(array $attachment, int $index): ?string
    {
        $type = $this->normalizeScalar(data_get($attachment, 'type'));

        if ($type === 'image') {
            return $this->normalizeScalar(
                data_get($attachment, 'payload.photo_id')
                    ?? data_get($attachment, 'photo_id')
            ) ?? $this->hashSensitiveReference(data_get($attachment, 'payload.token') ?? data_get($attachment, 'token'), 'token');
        }

        if ($type === 'sticker') {
            $stickerCode = $this->normalizeScalar(
                data_get($attachment, 'payload.code')
                    ?? data_get($attachment, 'code')
            );

            if ($stickerCode !== null) {
                return $stickerCode;
            }
        }

        $tokenReference = $this->hashSensitiveReference(
            data_get($attachment, 'payload.token')
                ?? data_get($attachment, 'token'),
            'token',
        );

        if ($tokenReference !== null) {
            return $tokenReference;
        }

        $fileId = $this->normalizeScalar(
            data_get($attachment, 'payload.file_id')
                ?? data_get($attachment, 'file_id')
                ?? data_get($attachment, 'payload.id')
                ?? data_get($attachment, 'id')
        );

        if ($fileId !== null) {
            return $fileId;
        }

        $urlReference = $this->hashSensitiveReference(
            data_get($attachment, 'payload.url')
                ?? data_get($attachment, 'url'),
            'url',
        );

        return $urlReference ?? ($type !== null ? "{$index}:{$type}" : null);
    }

    private function hashSensitiveReference(mixed $value, string $prefix): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $prefix.':'.sha1($normalized) : null;
    }

    /**
     * @param  array<string, mixed>  $attachment
     */
    private function resolveMaxAttachmentUrl(array $attachment): ?string
    {
        return $this->normalizeScalar(
            data_get($attachment, 'payload.url')
                ?? data_get($attachment, 'url')
        );
    }

    /**
     * @param  array<string, mixed>  $attachment
     */
    private function resolveMaxAttachmentToken(array $attachment): ?string
    {
        return $this->normalizeScalar(
            data_get($attachment, 'payload.token')
                ?? data_get($attachment, 'token')
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function maxAttachmentCandidates(Message $message): array
    {
        if (! is_array($message->raw_payload)) {
            return [];
        }

        return $this->maxAttachmentCandidatesFromPayload($message->raw_payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function maxAttachmentCandidatesFromPayload(array $payload): array
    {
        $candidates = [];

        // Здесь link.message.* читается НАМЕРЕННО без forward-гейта (в отличие от
        // BotIncomingMessageNormalizer): это lookup-стог для матчинга по уже
        // сохранённому provider_file_reference, свои вложения идут в стоге раньше
        // link-овских, а строки MessageAttachment, созданные из reply-цитат до
        // f207b891, должны оставаться скачиваемыми (грандфазеринг).
        foreach ([
            data_get($payload, 'attachments'),
            data_get($payload, 'body.attachments'),
            data_get($payload, 'message.attachments'),
            data_get($payload, 'message.body.attachments'),
            data_get($payload, 'message.link.message.body.attachments'),
            data_get($payload, 'message.link.message.attachments'),
        ] as $attachments) {
            if (! is_array($attachments)) {
                continue;
            }

            foreach ($attachments as $attachment) {
                if (is_array($attachment)) {
                    $candidates[] = $attachment;
                }
            }
        }

        return $candidates;
    }

    private function isMaxStickerStubUrl(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path)
            && str_contains($path, '/static/messages/res/images/stub/sticker_');
    }

    private function filenameHintFromUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) && trim($path) !== '' ? $path : null;
    }

    private function filenameFromHint(?string $filenameHint): ?string
    {
        if (! filled($filenameHint)) {
            return null;
        }

        $basename = basename(str_replace('\\', '/', (string) $filenameHint));
        $basename = trim(str_replace("\0", '', $basename), " \t\n\r\0\x0B.");

        return $basename !== '' ? mb_substr($basename, 0, 180) : null;
    }

    private function extensionFromFilename(?string $filename): ?string
    {
        if (! filled($filename)) {
            return null;
        }

        $extension = MessageAttachment::sanitizeExtension(pathinfo((string) $filename, PATHINFO_EXTENSION));

        return $extension !== '' ? $extension : null;
    }

    /**
     * @param  resource  $stream
     */
    private function mimeTypeFromStream(mixed $stream): ?string
    {
        if (! is_resource($stream)) {
            return null;
        }

        rewind($stream);
        $sample = fread($stream, 64 * 1024);
        rewind($stream);

        return is_string($sample) ? $this->mimeTypeFromContents($sample) : null;
    }

    private function mimeTypeFromContents(string $contents): ?string
    {
        if ($contents === '') {
            return null;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            return null;
        }

        try {
            $detected = finfo_buffer($finfo, $contents);
        } finally {
            finfo_close($finfo);
        }

        return $this->normalizeMimeType(is_string($detected) ? $detected : null);
    }

    private function extensionFromMimeType(?string $mimeType): ?string
    {
        return match ($mimeType) {
            'application/json' => 'json',
            'application/msword' => 'doc',
            'application/pdf' => 'pdf',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.ms-powerpoint' => 'ppt',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/x-tgsticker' => 'tgs',
            'application/zip' => 'zip',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'audio/mpeg' => 'mp3',
            'audio/mp4' => 'm4a',
            'audio/aac' => 'aac',
            'audio/wav', 'audio/x-wav' => 'wav',
            'audio/ogg' => 'ogg',
            'audio/webm' => 'webm',
            'text/csv' => 'csv',
            'text/plain' => 'txt',
            'video/mp4' => 'mp4',
            'video/ogg' => 'ogv',
            'video/quicktime' => 'mov',
            'video/webm' => 'webm',
            default => null,
        };
    }

    private function mimeTypeFromExtension(mixed $extension): ?string
    {
        return match (MessageAttachment::sanitizeExtension($extension)) {
            'csv' => 'text/csv',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'json' => 'application/json',
            'pdf' => 'application/pdf',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'txt' => 'text/plain',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'tgs' => 'application/x-tgsticker',
            'zip' => 'application/zip',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'mp3' => 'audio/mpeg',
            'm4a' => 'audio/mp4',
            'aac' => 'audio/aac',
            'wav' => 'audio/wav',
            'oga', 'ogg', 'opus' => 'audio/ogg',
            'weba' => 'audio/webm',
            'm4v', 'mp4' => 'video/mp4',
            'mov' => 'video/quicktime',
            'ogv' => 'video/ogg',
            'webm' => 'video/webm',
            default => null,
        };
    }

    private function normalizeMimeType(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $mimeType = mb_strtolower(trim(explode(';', $value)[0] ?? ''));

        return in_array($mimeType, [
            'application/json',
            'application/msword',
            'application/pdf',
            'application/vnd.ms-excel',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/x-tgsticker',
            'application/zip',
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'audio/mpeg',
            'audio/mp4',
            'audio/aac',
            'audio/wav',
            'audio/x-wav',
            'audio/ogg',
            'audio/webm',
            'text/csv',
            'text/plain',
            'video/mp4',
            'video/ogg',
            'video/quicktime',
            'video/webm',
        ], true) ? $mimeType : null;
    }

    private function normalizeScalar(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }

    private function normalizeNonNegativeInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (is_float($value)) {
            return $value >= 0 ? (int) $value : null;
        }

        if (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        return null;
    }

    private function normalizeErrorCode(string $value): string
    {
        $normalized = mb_strtolower($value);
        $normalized = preg_replace('/[^a-z0-9_:-]+/', '_', $normalized) ?? '';
        $normalized = trim($normalized, '_:-');

        return $normalized !== '' ? mb_substr($normalized, 0, 64) : 'bot_media_download_failed';
    }

    private function resolveSafeErrorMessage(MessageAttachment $attachment, bool $willRetry): string
    {
        if ($willRetry) {
            return 'Временная ошибка загрузки. Повторим автоматически.';
        }

        return match ($attachment->provider) {
            MessageAttachment::PROVIDER_TELEGRAM_BOT => 'Не удалось скачать медиафайл из Telegram Bot.',
            MessageAttachment::PROVIDER_MAX_BOT => 'Не удалось скачать медиафайл из MAX.',
            default => 'Не удалось скачать медиафайл.',
        };
    }

    private function maxBytes(MessageAttachment $attachment): int
    {
        $attachmentLimit = $attachment->media_download_max_bytes;

        if (is_int($attachmentLimit) && $attachmentLimit > 0) {
            return $attachmentLimit;
        }

        return max(1, (int) config(
            'bots.media.download_max_bytes',
            InboundMediaDownloadPolicy::DEFAULT_AUTO_DOWNLOAD_MAX_BYTES,
        ));
    }
}
