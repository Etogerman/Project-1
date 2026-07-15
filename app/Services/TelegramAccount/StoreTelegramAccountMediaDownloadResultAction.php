<?php

namespace App\Services\TelegramAccount;

use App\Models\Channel;
use App\Models\Dialog;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Services\Dialogs\DialogAutomationGate;
use App\Services\Messages\DeleteRolledBackInboundMediaFileAction;
use App\Services\Messages\InboundMediaDownloadPolicy;
use App\Services\Messages\InboundMediaQuotaExceededException;
use App\Services\Messages\InboundMediaQuotaLedger;
use App\Services\Messages\InboundMediaRetrySchedule;
use App\Services\Messages\StoreMessageAttachmentLocalFileAction;
use App\Services\Messages\ValidateInboundMediaIntegrityAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class StoreTelegramAccountMediaDownloadResultAction
{
    public const ERROR_GATEWAY_DOWNLOAD_FAILED = 'gateway_download_failed';

    private const DIALOG_RELATION_LOCK_ATTEMPTS = 3;

    public function __construct(
        private readonly StoreMessageAttachmentLocalFileAction $storeMessageAttachmentLocalFileAction,
        private readonly DialogAutomationGate $dialogAutomationGate,
        private readonly InboundMediaQuotaLedger $quotaLedger,
        private readonly InboundMediaRetrySchedule $retrySchedule,
        private readonly ValidateInboundMediaIntegrityAction $validateInboundMediaIntegrityAction,
        private readonly DeleteRolledBackInboundMediaFileAction $deleteRolledBackInboundMediaFileAction,
    ) {}

    public function acknowledgeHandledResult(
        Channel $channel,
        MessageAttachment $attachment,
    ): ?MessageAttachment {
        $this->assertAttachmentBelongsToChannel($channel, $attachment);

        return $this->withLockedAttachment($attachment, function (?Dialog $dialog, MessageAttachment $locked): ?MessageAttachment {
            if (filled($locked->media_download_claim_token)) {
                return null;
            }

            if (! in_array($locked->download_status, [
                MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED,
                MessageAttachment::DOWNLOAD_STATUS_AVAILABLE_ON_DEMAND,
                MessageAttachment::DOWNLOAD_STATUS_METADATA_ONLY,
                MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED,
            ], true)) {
                return null;
            }

            return $locked->fresh();
        });
    }

    public function acknowledgeHandledDirectUploadResult(
        Channel $channel,
        MessageAttachment $attachment,
        string $claimToken,
    ): ?MessageAttachment {
        $handled = $this->acknowledgeHandledResult($channel, $attachment);

        if (
            $handled instanceof MessageAttachment
            && $handled->download_status === MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED
        ) {
            $this->deleteTemporaryUploadAfterFinalization($handled, $claimToken);
        }

        return $handled;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function markDownloaded(
        Channel $channel,
        MessageAttachment $attachment,
        string $contents,
        ?string $claimToken,
        array $metadata = [],
    ): MessageAttachment {
        $stream = fopen('php://temp', 'w+b');

        if ($stream === false) {
            throw new InvalidArgumentException('Failed to open temporary attachment stream.');
        }

        try {
            $writtenBytes = fwrite($stream, $contents);

            if ($writtenBytes === false || $writtenBytes !== strlen($contents)) {
                throw new InvalidArgumentException('Failed to write attachment contents to temporary stream.');
            }

            rewind($stream);

            return $this->markDownloadedFromStream($channel, $attachment, $stream, $claimToken, [
                ...$metadata,
                'file_size_bytes' => strlen($contents),
                'detected_contents' => $contents,
            ]);
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param  resource  $stream
     * @param  array<string, mixed>  $metadata
     */
    public function markDownloadedFromStream(
        Channel $channel,
        MessageAttachment $attachment,
        mixed $stream,
        ?string $claimToken,
        array $metadata = [],
    ): MessageAttachment {
        $this->assertAttachmentBelongsToChannel($channel, $attachment);

        if (! is_resource($stream)) {
            throw new InvalidArgumentException('Telegram Account media result must provide a stream.');
        }

        try {
            return $this->withLockedAttachment($attachment, function (?Dialog $lockedDialog, MessageAttachment $locked) use ($stream, $claimToken, $metadata): MessageAttachment {
                $this->assertClaimToken($locked, $claimToken);

                if ($locked->download_status === MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED) {
                    return $locked;
                }

                $this->assertResultIsExpected($locked);
                $this->assertClaimLeaseActive($locked);

                if ($this->shouldSuppressAutomaticDownload($locked, $lockedDialog)) {
                    return $this->markLockedMetadataOnlyBecauseBlacklisted($locked, $claimToken);
                }

                $originalFilename = $this->normalizeNullableScalar($metadata['original_filename'] ?? null) ?? $locked->original_filename;
                $detectedContents = is_string($metadata['detected_contents'] ?? null)
                    ? $metadata['detected_contents']
                    : $this->readDetectionSample($stream);
                $fileSizeBytes = $this->normalizeFileSize($metadata['file_size_bytes'] ?? null)
                    ?? $locked->file_size_bytes;
                $providerSizeBytes = $this->normalizeFileSize($metadata['provider_file_size_bytes'] ?? null);
                $detectedMimeType = $this->validateInboundMediaIntegrityAction->inspectContents(
                    attachment: $locked,
                    sample: $detectedContents,
                    actualSizeBytes: max(0, (int) $fileSizeBytes),
                    providerSizeBytes: $providerSizeBytes,
                    declaredMimeType: $this->normalizeNullableScalar($metadata['mime_type'] ?? null) ?? $locked->mime_type,
                );
                $mimeType = $this->resolveDownloadedMimeType(
                    $locked,
                    $detectedContents,
                    $metadata['mime_type'] ?? null,
                    $detectedMimeType,
                );
                $extension = $this->resolveDownloadedExtension($locked, $mimeType, $originalFilename);
                $attemptNumber = $locked->mediaDownloadLedgerAttemptNumber();

                $this->quotaLedger->assertCanCompleteAttempt(
                    $locked,
                    $attemptNumber,
                    max(0, (int) $fileSizeBytes),
                );

                $locked->forceFill([
                    'mime_type' => $mimeType ?? $locked->mime_type,
                    'extension' => $extension ?? $locked->extension,
                    'original_filename' => $originalFilename,
                    'file_size_bytes' => $fileSizeBytes,
                ])->save();

                $stored = $this->storeMessageAttachmentLocalFileAction->handleStream(
                    $locked,
                    $stream,
                    $fileSizeBytes,
                    $extension,
                    function (MessageAttachment $storedAttachment) use ($attemptNumber, $fileSizeBytes): void {
                        $this->quotaLedger->completeAttempt(
                            $storedAttachment,
                            $attemptNumber,
                            max(0, (int) $fileSizeBytes),
                        );
                    },
                    expectedClaimToken: filled($claimToken) ? (string) $claimToken : null,
                );

                return $stored;
            });
        } catch (InboundMediaQuotaExceededException $exception) {
            return $this->markQuotaBlocked($channel, $attachment, $claimToken, $exception);
        }
    }

    public function markAvailableOnDemand(
        Channel $channel,
        MessageAttachment $attachment,
        ?string $claimToken,
    ): MessageAttachment {
        $this->assertAttachmentBelongsToChannel($channel, $attachment);

        return $this->withLockedAttachment($attachment, function (?Dialog $lockedDialog, MessageAttachment $locked) use ($claimToken): MessageAttachment {
            if (
                $locked->download_status === MessageAttachment::DOWNLOAD_STATUS_AVAILABLE_ON_DEMAND
                && blank($locked->media_download_claim_token)
                && filled($claimToken)
            ) {
                return $locked;
            }

            $this->assertClaimToken($locked, $claimToken);

            if ($locked->download_status === MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED) {
                return $locked;
            }

            $this->assertResultIsExpected($locked);
            $this->assertClaimLeaseActive($locked);

            if ($this->shouldSuppressAutomaticDownload($locked, $lockedDialog)) {
                return $this->markLockedMetadataOnlyBecauseBlacklisted($locked, $claimToken);
            }

            $this->deleteDirectUploadOrFail($locked, $claimToken);
            $this->quotaLedger->failAttempt(
                $locked,
                $locked->mediaDownloadLedgerAttemptNumber(),
                0,
                'available_on_demand',
            );

            $locked->forceFill([
                'download_status' => MessageAttachment::DOWNLOAD_STATUS_AVAILABLE_ON_DEMAND,
                'media_download_claim_token' => null,
                'media_download_attempts' => max(0, (int) $locked->media_download_attempts - 1),
                'local_disk' => null,
                'local_path' => null,
                'media_download_upload_size_bytes' => null,
                'media_download_next_retry_at' => null,
                'media_download_claimed_at' => null,
                'media_download_heartbeat_at' => null,
                'media_download_attempt_deadline_at' => null,
                'safe_error_code' => TelegramAccountMediaDownloadPolicy::ERROR_AUTO_DOWNLOAD_LIMIT_EXCEEDED,
                'safe_error_message' => null,
            ])->save();

            return $locked->fresh();
        });
    }

    public function directUploadSize(Channel $channel, MessageAttachment $attachment, string $claimToken): int
    {
        $this->assertAttachmentBelongsToChannel($channel, $attachment);

        if ($attachment->download_status === MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED) {
            return max(0, (int) $attachment->file_size_bytes);
        }

        $this->assertClaimToken($attachment, $claimToken);

        $this->assertResultIsExpected($attachment);
        $this->assertClaimLeaseActive($attachment);

        $disk = MessageAttachment::storageDiskName();
        $path = $this->storeMessageAttachmentLocalFileAction->buildDirectUploadPath($attachment, $claimToken);

        if (! Storage::disk($disk)->exists($path)) {
            throw new InvalidArgumentException('Direct Telegram Account media upload was not found.');
        }

        $storedSize = (int) Storage::disk($disk)->size($path);
        $expectedSize = $attachment->media_download_upload_size_bytes;

        if ($expectedSize !== null && (int) $expectedSize !== $storedSize) {
            throw new InvalidArgumentException('Direct media upload is incomplete.');
        }

        return $storedSize;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function markDownloadedFromDirectUpload(
        Channel $channel,
        MessageAttachment $attachment,
        string $claimToken,
        array $metadata = [],
    ): MessageAttachment {
        $this->assertAttachmentBelongsToChannel($channel, $attachment);

        $disk = MessageAttachment::storageDiskName();
        $uploadedPath = $this->storeMessageAttachmentLocalFileAction->buildDirectUploadPath($attachment, $claimToken);
        $stablePath = null;
        $stableCandidatePath = null;
        $stableCopyCreated = false;

        try {
            $stored = $this->withLockedAttachment($attachment, function (?Dialog $lockedDialog, MessageAttachment $locked) use (
                $claimToken,
                $metadata,
                $disk,
                $uploadedPath,
                &$stablePath,
                &$stableCandidatePath,
                &$stableCopyCreated,
            ): MessageAttachment {
                if ($locked->download_status === MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED) {
                    return $locked;
                }

                $this->assertClaimToken($locked, $claimToken);
                $this->assertResultIsExpected($locked);
                $this->assertClaimLeaseActive($locked);

                if ($this->shouldSuppressAutomaticDownload($locked, $lockedDialog)) {
                    return $this->markLockedMetadataOnlyBecauseBlacklisted($locked, $claimToken);
                }

                if (! Storage::disk($disk)->exists($uploadedPath)) {
                    throw new InvalidArgumentException('Direct Telegram Account media upload was not found.');
                }

                $stream = Storage::disk($disk)->readStream($uploadedPath);

                if (! is_resource($stream)) {
                    throw new InvalidArgumentException('Direct Telegram Account media upload cannot be read.');
                }

                try {
                    $detectedContents = $this->readDetectionSample($stream);
                } finally {
                    fclose($stream);
                }

                $fileSizeBytes = (int) Storage::disk($disk)->size($uploadedPath);
                $reportedFileSizeBytes = $this->normalizeFileSize($metadata['file_size_bytes'] ?? null);
                $providerSizeBytes = $this->normalizeFileSize($metadata['provider_file_size_bytes'] ?? null);
                $expectedUploadSizeBytes = $locked->media_download_upload_size_bytes;

                if ($reportedFileSizeBytes === null || $reportedFileSizeBytes !== $fileSizeBytes) {
                    throw new InvalidArgumentException('Direct media upload size does not match reported file size.');
                }

                if ($expectedUploadSizeBytes !== null && (int) $expectedUploadSizeBytes !== $fileSizeBytes) {
                    throw new InvalidArgumentException('Direct media upload is incomplete.');
                }

                $attemptNumber = $locked->mediaDownloadLedgerAttemptNumber();
                $this->quotaLedger->assertCanCompleteAttempt($locked, $attemptNumber, $fileSizeBytes);

                $originalFilename = $this->normalizeNullableScalar($metadata['original_filename'] ?? null) ?? $locked->original_filename;
                $detectedMimeType = $this->validateInboundMediaIntegrityAction->inspectContents(
                    attachment: $locked,
                    sample: $detectedContents,
                    actualSizeBytes: $fileSizeBytes,
                    providerSizeBytes: $providerSizeBytes,
                    declaredMimeType: $this->normalizeNullableScalar($metadata['mime_type'] ?? null) ?? $locked->mime_type,
                );
                $mimeType = $this->resolveDownloadedMimeType(
                    $locked,
                    $detectedContents,
                    $metadata['mime_type'] ?? null,
                    $detectedMimeType,
                );
                $extension = $this->resolveDownloadedExtension($locked, $mimeType, $originalFilename);
                $stablePath = $this->storeMessageAttachmentLocalFileAction->buildClaimedPath(
                    $locked,
                    $extension,
                    $claimToken,
                );
                $stableCandidatePath = $stablePath.'.commit.'.Str::uuid()->toString();
                $this->publishDirectUploadAtomically(
                    $disk,
                    $uploadedPath,
                    $stableCandidatePath,
                    $stablePath,
                );

                $stableCopyCreated = true;

                $locked->forceFill([
                    'mime_type' => $mimeType ?? $locked->mime_type,
                    'extension' => $extension ?? $locked->extension,
                    'original_filename' => $originalFilename,
                    'file_size_bytes' => $fileSizeBytes,
                    'media_download_claim_token' => null,
                    'media_download_upload_size_bytes' => null,
                    'media_download_next_retry_at' => null,
                    'media_download_claimed_at' => null,
                    'media_download_heartbeat_at' => null,
                    'media_download_attempt_deadline_at' => null,
                    'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED,
                    'local_disk' => $disk,
                    'local_path' => $stablePath,
                    'safe_error_code' => null,
                    'safe_error_message' => null,
                ])->save();

                $this->quotaLedger->completeAttempt(
                    $locked,
                    $attemptNumber,
                    $fileSizeBytes,
                );

                return $locked->fresh();
            });
        } catch (InboundMediaQuotaExceededException $exception) {
            $this->deleteStableCandidateAfterFailedTransaction(
                $disk,
                $stableCandidatePath,
                (int) $attachment->id,
            );

            if ($stableCopyCreated && filled($stablePath)) {
                $this->deleteRolledBackInboundMediaFileAction->handle(
                    (int) $attachment->id,
                    $disk,
                    $stablePath,
                );
            }

            return $this->markQuotaBlocked($channel, $attachment, $claimToken, $exception);
        } catch (Throwable $exception) {
            $this->deleteStableCandidateAfterFailedTransaction(
                $disk,
                $stableCandidatePath,
                (int) $attachment->id,
            );

            if ($stableCopyCreated && filled($stablePath)) {
                $this->deleteRolledBackInboundMediaFileAction->handle(
                    (int) $attachment->id,
                    $disk,
                    $stablePath,
                );
            }

            throw $exception;
        }

        $this->deleteTemporaryUploadAfterFinalization($stored, $claimToken);

        return $stored;
    }

    private function publishDirectUploadAtomically(
        string $disk,
        string $uploadedPath,
        string $candidatePath,
        string $stablePath,
    ): void {
        $storage = Storage::disk($disk);

        if (! $storage->copy($uploadedPath, $candidatePath)) {
            throw new RuntimeException('Direct media upload could not be prepared for finalization.');
        }

        if ($storage->exists($stablePath) && ! $storage->delete($stablePath)) {
            throw new RuntimeException('Previous direct media finalization could not be removed.');
        }

        if (! $storage->move($candidatePath, $stablePath)) {
            throw new RuntimeException('Direct media upload could not be finalized atomically.');
        }
    }

    private function deleteStableCandidateAfterFailedTransaction(
        string $disk,
        ?string $path,
        int $attachmentId,
    ): void {
        if (blank($path)) {
            return;
        }

        $this->deleteRolledBackInboundMediaFileAction->handle(
            $attachmentId,
            $disk,
            $path,
        );
    }

    public function markQuotaBlocked(
        Channel $channel,
        MessageAttachment $attachment,
        ?string $claimToken,
        InboundMediaQuotaExceededException $exception,
    ): MessageAttachment {
        $this->assertAttachmentBelongsToChannel($channel, $attachment);

        return $this->withLockedAttachment($attachment, function (?Dialog $dialog, MessageAttachment $locked) use ($claimToken, $exception): MessageAttachment {
            $this->assertClaimToken($locked, $claimToken);
            $this->assertResultIsExpected($locked);
            $this->deleteDirectUploadOrFail($locked, $claimToken);
            $reason = $exception->reason === InboundMediaDownloadPolicy::REASON_SIZE_ABOVE_AUTO_LIMIT
                ? TelegramAccountMediaDownloadPolicy::ERROR_AUTO_DOWNLOAD_LIMIT_EXCEEDED
                : $exception->reason;

            $this->quotaLedger->failAttempt(
                $locked,
                $locked->mediaDownloadLedgerAttemptNumber(),
                $exception->transferredBytes,
                $reason,
            );

            $locked->forceFill([
                'download_status' => MessageAttachment::DOWNLOAD_STATUS_AVAILABLE_ON_DEMAND,
                'manual_download_requested_at' => null,
                'manual_download_requested_by_user_id' => null,
                'media_download_claim_token' => null,
                'media_download_attempts' => $reason === InboundMediaDownloadPolicy::REASON_MANUAL_HARD_LIMIT
                    ? (int) $locked->media_download_attempts
                    : max(0, (int) $locked->media_download_attempts - 1),
                'local_disk' => null,
                'local_path' => null,
                'media_download_upload_size_bytes' => null,
                'media_download_next_retry_at' => null,
                'media_download_claimed_at' => null,
                'media_download_heartbeat_at' => null,
                'media_download_attempt_deadline_at' => null,
                'safe_error_code' => $reason,
                'safe_error_message' => $this->quotaErrorMessage($reason),
            ])->save();

            return $locked->fresh();
        });
    }

    private function quotaErrorMessage(?string $reason): ?string
    {
        return match ($reason) {
            TelegramAccountMediaDownloadPolicy::ERROR_AUTO_DOWNLOAD_LIMIT_EXCEEDED => null,
            InboundMediaDownloadPolicy::REASON_MANUAL_HARD_LIMIT => 'Файл превышает предельный размер ручной загрузки.',
            InboundMediaDownloadPolicy::REASON_TRAFFIC_QUOTA_EXCEEDED => 'Дневной лимит загрузки медиа для канала исчерпан.',
            default => 'Недостаточно доступного места для загрузки медиа.',
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function markFailed(
        Channel $channel,
        MessageAttachment $attachment,
        ?string $claimToken,
        array $payload,
    ): MessageAttachment {
        $this->assertAttachmentBelongsToChannel($channel, $attachment);

        return $this->withLockedAttachment($attachment, function (?Dialog $lockedDialog, MessageAttachment $locked) use ($claimToken, $payload): MessageAttachment {
            $errorCode = $this->normalizeErrorCode($payload['error_code'] ?? null) ?? self::ERROR_GATEWAY_DOWNLOAD_FAILED;

            if (
                blank($locked->media_download_claim_token)
                && filled($claimToken)
                && in_array($locked->download_status, [
                    MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD,
                    MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED,
                ], true)
                && $locked->safe_error_code === $errorCode
            ) {
                return $locked;
            }

            $this->assertClaimToken($locked, $claimToken);

            if ($locked->download_status === MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED) {
                return $locked;
            }

            $this->assertResultIsExpected($locked);

            if ($this->shouldSuppressAutomaticDownload($locked, $lockedDialog)) {
                return $this->markLockedMetadataOnlyBecauseBlacklisted($locked, $claimToken);
            }

            $attemptNumber = max(1, (int) $locked->media_download_attempts);
            $retryable = (bool) ($payload['retryable'] ?? false);
            $willRetry = $retryable && $this->retrySchedule->willRetry($attemptNumber);
            $finalErrorCode = $retryable && ! $willRetry
                ? $this->retrySchedule->terminalErrorCode($errorCode)
                : $errorCode;
            $errorMessage = $this->normalizeSafeErrorMessage($payload['error_message'] ?? null)
                ?? 'Telegram Account Gateway failed to download media file.';

            $this->deleteDirectUploadOrFail($locked, $claimToken);

            $transferredBytes = $this->normalizeFileSize($payload['received_bytes'] ?? null) ?? 0;
            $this->quotaLedger->failAttempt(
                $locked,
                $locked->mediaDownloadLedgerAttemptNumber(),
                $transferredBytes,
                $finalErrorCode,
            );

            if (
                $errorCode === ClaimTelegramAccountMediaDownloadAction::ERROR_FILE_TOO_LARGE
                && $locked->manual_download_requested_at === null
            ) {
                $locked->forceFill([
                    'download_status' => MessageAttachment::DOWNLOAD_STATUS_AVAILABLE_ON_DEMAND,
                    'media_download_claim_token' => null,
                    'media_download_attempts' => max(0, (int) $locked->media_download_attempts - 1),
                    'local_disk' => null,
                    'local_path' => null,
                    'media_download_upload_size_bytes' => null,
                    'media_download_next_retry_at' => null,
                    'media_download_claimed_at' => null,
                    'media_download_heartbeat_at' => null,
                    'media_download_attempt_deadline_at' => null,
                    'safe_error_code' => TelegramAccountMediaDownloadPolicy::ERROR_AUTO_DOWNLOAD_LIMIT_EXCEEDED,
                    'safe_error_message' => null,
                ])->save();

                return $locked->fresh();
            }

            $locked->forceFill([
                'download_status' => $willRetry
                    ? MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD
                    : MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED,
                'media_download_claim_token' => null,
                'local_disk' => null,
                'local_path' => null,
                'media_download_upload_size_bytes' => null,
                'media_download_next_retry_at' => $willRetry
                    ? $this->retrySchedule->nextRetryAt(
                        $attemptNumber,
                        $this->normalizeFileSize($payload['retry_after_seconds'] ?? null),
                    )
                    : null,
                'media_download_claimed_at' => null,
                'media_download_heartbeat_at' => null,
                'media_download_attempt_deadline_at' => null,
                'safe_error_code' => $finalErrorCode,
                'safe_error_message' => $errorMessage,
            ])->save();

            return $locked->fresh();
        });
    }

    private function assertAttachmentBelongsToChannel(Channel $channel, MessageAttachment $attachment): void
    {
        if (
            (int) $attachment->channel_id !== (int) $channel->id
            || $attachment->provider !== MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT
        ) {
            throw new InvalidArgumentException('Message attachment does not belong to Telegram Account route channel.');
        }
    }

    private function assertResultIsExpected(MessageAttachment $attachment): void
    {
        if ($attachment->download_status !== MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING) {
            throw new InvalidArgumentException('Media download result is not expected for current attachment state.');
        }
    }

    private function assertClaimLeaseActive(MessageAttachment $attachment): void
    {
        if (
            $attachment->media_download_attempt_deadline_at === null
            || $attachment->media_download_attempt_deadline_at->lte(now())
        ) {
            throw new InvalidArgumentException('Media download claim has expired.');
        }
    }

    private function assertClaimToken(MessageAttachment $attachment, ?string $claimToken): void
    {
        $expected = trim((string) $attachment->media_download_claim_token);
        $provided = trim((string) $claimToken);

        if ($expected === '' && $provided === '') {
            return;
        }

        if ($expected === '' || $provided === '' || ! hash_equals($expected, $provided)) {
            throw new InvalidArgumentException('Media download claim token is no longer current.');
        }
    }

    private function shouldSuppressAutomaticDownload(MessageAttachment $attachment, ?Dialog $dialog): bool
    {
        if ($attachment->manual_download_requested_at !== null) {
            return false;
        }

        return ! $this->dialogAutomationGate->accepts($dialog);
    }

    /**
     * @template TResult
     *
     * @param  callable(?Dialog, MessageAttachment): TResult  $callback
     * @return TResult
     */
    private function withLockedAttachment(MessageAttachment $attachment, callable $callback): mixed
    {
        for ($attempt = 1; $attempt <= self::DIALOG_RELATION_LOCK_ATTEMPTS; $attempt++) {
            try {
                return DB::transaction(function () use ($attachment, $callback): mixed {
                    [$lockedDialog, $lockedAttachment] = $this->lockDialogAndAttachment($attachment);

                    return $callback($lockedDialog, $lockedAttachment);
                });
            } catch (TelegramAccountMediaDialogChangedDuringLock $exception) {
                if ($attempt === self::DIALOG_RELATION_LOCK_ATTEMPTS) {
                    throw new RuntimeException(
                        'Message dialog changed repeatedly while storing Telegram Account media result.',
                        previous: $exception,
                    );
                }
            }
        }

        throw new RuntimeException('Telegram Account media result lock could not be acquired.');
    }

    /**
     * @return array{0:?Dialog,1:MessageAttachment}
     */
    private function lockDialogAndAttachment(MessageAttachment $attachment): array
    {
        $messageId = MessageAttachment::query()
            ->whereKey($attachment->id)
            ->value('message_id');

        if ($messageId === null) {
            return [null, $this->lockAttachment($attachment)];
        }

        $dialogId = Message::query()
            ->whereKey($messageId)
            ->value('dialog_id');

        $lockedDialog = $dialogId === null
            ? null
            : Dialog::query()
                ->with('dialogStage')
                ->whereKey($dialogId)
                ->lockForUpdate()
                ->first();

        if ($dialogId !== null && ! $lockedDialog instanceof Dialog) {
            throw new TelegramAccountMediaDialogChangedDuringLock;
        }

        /** @var Message $lockedMessage */
        $lockedMessage = Message::query()
            ->whereKey($messageId)
            ->lockForUpdate()
            ->firstOrFail();

        if ($this->normalizeNullableId($lockedMessage->dialog_id) !== $this->normalizeNullableId($dialogId)) {
            throw new TelegramAccountMediaDialogChangedDuringLock;
        }

        $lockedAttachment = $this->lockAttachment($attachment);

        if ($this->normalizeNullableId($lockedAttachment->message_id) !== (int) $messageId) {
            throw new TelegramAccountMediaDialogChangedDuringLock;
        }

        return [$lockedDialog, $lockedAttachment];
    }

    private function lockAttachment(MessageAttachment $attachment): MessageAttachment
    {
        /** @var MessageAttachment */
        return MessageAttachment::query()
            ->whereKey($attachment->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function normalizeNullableId(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private function markLockedMetadataOnlyBecauseBlacklisted(
        MessageAttachment $attachment,
        ?string $claimToken,
    ): MessageAttachment {
        $this->deleteDirectUploadOrFail($attachment, $claimToken);
        $this->quotaLedger->failAttempt(
            $attachment,
            $attachment->mediaDownloadLedgerAttemptNumber(),
            0,
            DialogAutomationGate::REASON_BLACKLIST_STAGE,
        );

        $attachment->forceFill([
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_METADATA_ONLY,
            'media_download_claim_token' => null,
            'media_download_attempts' => max(0, (int) $attachment->media_download_attempts - 1),
            'local_disk' => null,
            'local_path' => null,
            'media_download_upload_size_bytes' => null,
            'media_download_next_retry_at' => null,
            'media_download_claimed_at' => null,
            'media_download_heartbeat_at' => null,
            'media_download_attempt_deadline_at' => null,
            'safe_error_code' => DialogAutomationGate::REASON_BLACKLIST_STAGE,
            'safe_error_message' => 'Media download skipped because the dialog stage is blacklisted.',
        ])->save();

        return $attachment->fresh();
    }

    private function normalizeNullableScalar(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function resolveDownloadedMimeType(
        MessageAttachment $attachment,
        ?string $contents,
        mixed $mimeType,
        ?string $detectedMimeType = null,
    ): ?string {
        $normalized = $this->normalizeNullableScalar($mimeType);

        if (! MessageAttachment::isGenericMimeType($normalized)) {
            return $normalized;
        }

        if (! in_array($attachment->media_kind, [
            MessageAttachment::MEDIA_KIND_IMAGE,
            MessageAttachment::MEDIA_KIND_STICKER,
        ], true)) {
            return $normalized;
        }

        if ($contents !== null) {
            $previewMimeType = MessageAttachment::previewMimeTypeFromContents($contents);

            if ($previewMimeType !== null) {
                return $previewMimeType;
            }
        }

        return $detectedMimeType ?? $normalized;
    }

    private function normalizeFileSize(mixed $value): ?int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @param  resource  $stream
     */
    private function readDetectionSample(mixed $stream): ?string
    {
        $position = ftell($stream);

        if ($position === false || fseek($stream, 0) !== 0) {
            return null;
        }

        $sample = fread($stream, 64 * 1024);

        if (fseek($stream, $position) !== 0) {
            throw new InvalidArgumentException('Failed to restore Telegram Account media stream position.');
        }

        return is_string($sample) && $sample !== '' ? $sample : null;
    }

    private function deleteDirectUploadOrFail(MessageAttachment $attachment, ?string $claimToken): void
    {
        if (! filled($claimToken)) {
            return;
        }

        $disk = MessageAttachment::storageDiskName();
        $path = $this->storeMessageAttachmentLocalFileAction->buildDirectUploadPath($attachment, (string) $claimToken);

        if (Storage::disk($disk)->exists($path) && ! Storage::disk($disk)->delete($path)) {
            throw new InvalidArgumentException('Temporary media upload could not be removed.');
        }
    }

    private function deleteTemporaryUploadAfterFinalization(
        MessageAttachment $attachment,
        string $claimToken,
    ): void {
        $disk = MessageAttachment::storageDiskName();
        $path = $this->storeMessageAttachmentLocalFileAction->buildDirectUploadPath($attachment, $claimToken);

        $this->deleteRolledBackInboundMediaFileAction->handle(
            (int) $attachment->id,
            $disk,
            $path,
        );
    }

    private function resolveDownloadedExtension(
        MessageAttachment $attachment,
        ?string $mimeType,
        ?string $originalFilename,
    ): ?string {
        $currentExtension = MessageAttachment::sanitizeExtension($attachment->extension);

        if ($currentExtension !== '') {
            return $currentExtension;
        }

        $filenameExtension = MessageAttachment::sanitizeExtension(pathinfo((string) $originalFilename, PATHINFO_EXTENSION));

        if ($filenameExtension !== '') {
            return $filenameExtension;
        }

        if (! in_array($attachment->media_kind, [
            MessageAttachment::MEDIA_KIND_IMAGE,
            MessageAttachment::MEDIA_KIND_STICKER,
        ], true)) {
            return null;
        }

        return MessageAttachment::previewExtensionForMimeType($mimeType);
    }

    private function normalizeErrorCode(mixed $value): ?string
    {
        $normalized = $this->normalizeNullableScalar($value);

        if ($normalized === null) {
            return null;
        }

        $normalized = mb_strtolower($normalized);
        $normalized = preg_replace('/[^a-z0-9_:-]+/', '_', $normalized) ?? '';
        $normalized = trim($normalized, '_:-');

        return $normalized !== '' ? mb_substr($normalized, 0, 64) : null;
    }

    private function normalizeSafeErrorMessage(mixed $value): ?string
    {
        $normalized = $this->normalizeNullableScalar($value);

        if ($normalized === null) {
            return null;
        }

        $normalized = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $normalized) ?? '';
        $normalized = preg_replace(
            '~(["\'])(/(?!/)[^"\'\r\n]*)\1~u',
            '$1[redacted-path]$1',
            $normalized,
        ) ?? '';
        $normalized = preg_replace(
            '~(["\'])([A-Za-z]:\\\\[^"\'\r\n]*)\1~u',
            '$1[redacted-path]$1',
            $normalized,
        ) ?? '';
        $normalized = preg_replace(
            '~(?<![A-Za-z0-9:/])/(?!/)[^\s"\'<>()[\]{};,!?]+~u',
            '[redacted-path]',
            $normalized,
        ) ?? '';
        $normalized = preg_replace(
            '~\b[A-Za-z]:\\\\[^\s"\'<>()[\]{};,!?]+~u',
            '[redacted-path]',
            $normalized,
        ) ?? '';

        return mb_substr(trim($normalized), 0, 1000) ?: null;
    }
}

final class TelegramAccountMediaDialogChangedDuringLock extends RuntimeException {}
