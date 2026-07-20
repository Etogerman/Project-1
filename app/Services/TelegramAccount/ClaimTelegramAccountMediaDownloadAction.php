<?php

namespace App\Services\TelegramAccount;

use App\Models\Channel;
use App\Models\MessageAttachment;
use App\Services\Dialogs\DialogAutomationGate;
use App\Services\Dialogs\DialogStageCatalog;
use App\Services\Messages\InboundMediaAdmissionDeniedException;
use App\Services\Messages\InboundMediaAdmissionGate;
use App\Services\Messages\InboundMediaDownloadPolicy;
use App\Services\Messages\InboundMediaQuotaLedger;
use App\Services\Messages\InboundMediaRetrySchedule;
use App\Services\Messages\StoreMessageAttachmentLocalFileAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ClaimTelegramAccountMediaDownloadAction
{
    public const ERROR_FILE_TOO_LARGE = 'file_too_large';

    public const ERROR_MISSING_PROVIDER_FILE_ID = 'missing_provider_file_id';

    public const ERROR_UNSUPPORTED_MEDIA_KIND = 'unsupported_media_kind';

    private const LEGACY_AUTOMATIC_PROCESSING_TIMEOUT_MINUTES = 10;

    public const TOKEN_AWARE_PROCESSING_TIMEOUT_MINUTES = 130;

    private const STALE_UPLOAD_CLEANUP_RETRY_MINUTES = 5;

    public function __construct(
        private readonly DialogStageCatalog $dialogStageCatalog,
        private readonly StoreMessageAttachmentLocalFileAction $storeMessageAttachmentLocalFileAction,
        private readonly TelegramAccountMediaDownloadPolicy $mediaDownloadPolicy,
        private readonly InboundMediaQuotaLedger $quotaLedger,
        private readonly InboundMediaAdmissionGate $admissionGate,
        private readonly InboundMediaRetrySchedule $retrySchedule,
    ) {}

    /**
     * @return list<string>
     */
    public static function supportedMediaKinds(): array
    {
        return [
            MessageAttachment::MEDIA_KIND_IMAGE,
            MessageAttachment::MEDIA_KIND_DOCUMENT,
            MessageAttachment::MEDIA_KIND_VIDEO,
            MessageAttachment::MEDIA_KIND_VIDEO_NOTE,
            MessageAttachment::MEDIA_KIND_AUDIO,
            MessageAttachment::MEDIA_KIND_VOICE,
            MessageAttachment::MEDIA_KIND_STICKER,
        ];
    }

    public function handle(Channel $channel): ?MessageAttachment
    {
        return DB::transaction(function () use ($channel): ?MessageAttachment {
            $onDemandEnabled = $this->mediaDownloadPolicy->onDemandEnabled($channel);

            $this->admissionGate->lock();

            $this->releaseStaleDownloads($channel);
            $this->markBlacklistedDownloadsMetadataOnly($channel);
            $this->failNonClaimableDownloads($channel);

            $preferAutomatic = $this->admissionGate->shouldPreferAutomatic($channel);

            $attachment = MessageAttachment::query()
                ->where('channel_id', $channel->id)
                ->where('provider', MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT)
                ->where('download_status', MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD)
                ->where(function (Builder $query): void {
                    $query
                        ->whereNull('media_download_next_retry_at')
                        ->orWhere('media_download_next_retry_at', '<=', now());
                })
                ->whereIn('media_kind', self::supportedMediaKinds())
                ->where(function (Builder $query): void {
                    $query
                        ->where(function (Builder $fileIdQuery): void {
                            $fileIdQuery
                                ->whereNotNull('provider_file_id')
                                ->where('provider_file_id', '!=', '');
                        })
                        ->orWhere(function (Builder $referenceQuery): void {
                            $referenceQuery
                                ->whereNotNull('provider_file_reference')
                                ->where('provider_file_reference', '!=', '');
                        });
                })
                ->where(function (Builder $query): void {
                    $query
                        ->whereNotNull('manual_download_requested_at')
                        ->orWhereHas('message.dialog', function (Builder $dialogQuery): void {
                            $this->dialogStageCatalog->applyNotBlacklistStageFilter($dialogQuery);
                        });
                })
                ->when(
                    ! $onDemandEnabled,
                    static fn (Builder $query): Builder => $query->whereNull('manual_download_requested_at'),
                )
                ->orderByRaw($preferAutomatic
                    ? 'CASE WHEN manual_download_requested_at IS NULL THEN 0 ELSE 1 END'
                    : 'CASE WHEN manual_download_requested_at IS NULL THEN 1 ELSE 0 END')
                ->orderByRaw('CASE WHEN safe_error_code IS NULL THEN 0 ELSE 1 END')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (! $attachment instanceof MessageAttachment) {
                return null;
            }

            $manual = $attachment->manual_download_requested_at !== null;

            try {
                $this->admissionGate->assertCanClaim($channel, $manual, (int) $attachment->id);
            } catch (InboundMediaAdmissionDeniedException) {
                return null;
            }

            $attemptNumber = max(0, (int) $attachment->media_download_attempts) + 1;
            $leaseSequence = max(
                0,
                (int) $attachment->media_download_lease_sequence,
                (int) $attachment->media_download_attempts,
            ) + 1;
            $quotaDecision = $this->quotaLedger->reserveForAttempt($attachment, $leaseSequence);

            if (! $quotaDecision->allowed) {
                $attachment->forceFill([
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

            $attachment->forceFill([
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
                'media_download_upload_size_bytes' => null,
                'media_download_next_retry_at' => null,
                'media_download_max_bytes' => $manual
                    ? $this->mediaDownloadPolicy->manualRequestMaxBytes($attachment)
                    : ($attachment->media_download_max_bytes
                        ?? $this->mediaDownloadPolicy->automaticMaxBytes($channel)),
                'safe_error_code' => null,
                'safe_error_message' => null,
            ])->save();

            $this->admissionGate->recordClaim($channel, $manual);

            return $attachment->fresh(['message']);
        });
    }

    public function releaseAfterUploadTargetFailure(
        Channel $channel,
        MessageAttachment $attachment,
        string $claimToken,
    ): void {
        DB::transaction(function () use ($channel, $attachment, $claimToken): void {
            /** @var MessageAttachment|null $locked */
            $locked = MessageAttachment::query()
                ->whereKey($attachment->id)
                ->lockForUpdate()
                ->first();

            if (
                ! $locked instanceof MessageAttachment
                || (int) $locked->channel_id !== (int) $channel->id
                || $locked->provider !== MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT
                || $locked->download_status !== MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING
                || ! hash_equals((string) $locked->media_download_claim_token, $claimToken)
            ) {
                return;
            }

            if (! $this->deleteDirectUploadIfPresent($locked)) {
                return;
            }

            $this->quotaLedger->failAttempt(
                $locked,
                $locked->mediaDownloadLedgerAttemptNumber(),
                0,
                'upload_target_unavailable',
            );

            $attemptNumber = max(1, (int) $locked->media_download_attempts);
            $willRetry = $this->retrySchedule->willRetry($attemptNumber);

            $locked->forceFill([
                'download_status' => $willRetry
                    ? MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD
                    : MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED,
                'media_download_claim_token' => null,
                'media_download_upload_size_bytes' => null,
                'media_download_next_retry_at' => $willRetry
                    ? $this->retrySchedule->nextRetryAt($attemptNumber)
                    : null,
                'media_download_claimed_at' => null,
                'media_download_heartbeat_at' => null,
                'media_download_attempt_deadline_at' => null,
                'safe_error_code' => $willRetry
                    ? 'upload_target_unavailable'
                    : $this->retrySchedule->terminalErrorCode('upload_target_unavailable'),
                'safe_error_message' => $willRetry
                    ? 'Не удалось подготовить загрузку. Повторим автоматически.'
                    : 'Не удалось подготовить загрузку после нескольких попыток.',
                'updated_at' => now(),
            ])->save();
        });
    }

    private function releaseStaleDownloads(Channel $channel): void
    {
        $baseQuery = MessageAttachment::query()
            ->where('channel_id', $channel->id)
            ->where('provider', MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT)
            ->where('download_status', MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING);

        $this->releaseStaleDownloadsBefore(
            (clone $baseQuery)
                ->whereNull('manual_download_requested_at')
                ->whereNull('media_download_claim_token')
                ->where('updated_at', '<=', now()->subMinutes(self::LEGACY_AUTOMATIC_PROCESSING_TIMEOUT_MINUTES)),
        );

        $this->releaseStaleDownloadsBefore(
            (clone $baseQuery)
                ->whereNull('manual_download_requested_at')
                ->whereNotNull('media_download_claim_token')
                ->where('updated_at', '<=', now()->subMinutes(self::TOKEN_AWARE_PROCESSING_TIMEOUT_MINUTES)),
        );

        $this->releaseStaleDownloadsBefore(
            (clone $baseQuery)
                ->whereNotNull('manual_download_requested_at')
                ->where('updated_at', '<=', now()->subMinutes(self::TOKEN_AWARE_PROCESSING_TIMEOUT_MINUTES)),
        );
    }

    /**
     * @param  Builder<MessageAttachment>  $query
     */
    private function releaseStaleDownloadsBefore(Builder $query): void
    {
        $attachments = $query->lockForUpdate()->get();

        foreach ($attachments as $attachment) {
            if (! $this->deleteDirectUploadIfPresent($attachment)) {
                $attachment->timestamps = false;
                $attachment->forceFill([
                    'safe_error_code' => 'stale_upload_cleanup_failed',
                    'safe_error_message' => 'Temporary media upload cleanup will be retried.',
                    'updated_at' => now()->subMinutes(
                        self::TOKEN_AWARE_PROCESSING_TIMEOUT_MINUTES
                            - self::STALE_UPLOAD_CLEANUP_RETRY_MINUTES,
                    ),
                ])->save();
                $attachment->timestamps = true;

                continue;
            }

            $attachment->forceFill([
                'download_status' => MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD,
                'media_download_claim_token' => null,
                'media_download_upload_size_bytes' => null,
                'media_download_next_retry_at' => null,
                'media_download_claimed_at' => null,
                'media_download_heartbeat_at' => null,
                'media_download_attempt_deadline_at' => null,
                'safe_error_code' => 'download_claim_timeout',
                'safe_error_message' => 'Gateway did not report media download result before processing timeout.',
                'updated_at' => now(),
            ])->save();

            $this->quotaLedger->failAttempt(
                $attachment,
                $attachment->mediaDownloadLedgerAttemptNumber(),
                0,
                'download_claim_timeout',
            );
        }
    }

    private function deleteDirectUploadIfPresent(MessageAttachment $attachment): bool
    {
        if (! filled($attachment->media_download_claim_token)) {
            return true;
        }

        try {
            $disk = MessageAttachment::storageDiskName();
            $path = $this->storeMessageAttachmentLocalFileAction->buildDirectUploadPath($attachment);

            if (! Storage::disk($disk)->exists($path)) {
                return true;
            }

            if (! Storage::disk($disk)->delete($path)) {
                Log::warning('telegram_account_media.direct_upload_cleanup_failed', [
                    'attachment_id' => $attachment->id,
                    'error_type' => 'delete_returned_false',
                ]);

                return false;
            }

            return true;
        } catch (Throwable $exception) {
            Log::warning('telegram_account_media.direct_upload_cleanup_failed', [
                'attachment_id' => $attachment->id,
                'error_type' => $exception::class,
            ]);

            return false;
        }
    }

    private function markBlacklistedDownloadsMetadataOnly(Channel $channel): void
    {
        $this->updateLockedAttachments(
            MessageAttachment::query()
                ->where('channel_id', $channel->id)
                ->where('provider', MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT)
                ->where('download_status', MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD)
                ->whereNull('manual_download_requested_at')
                ->whereHas('message.dialog', function (Builder $query): void {
                    $this->dialogStageCatalog->applyBlacklistStageFilter($query);
                }),
            [
                'download_status' => MessageAttachment::DOWNLOAD_STATUS_METADATA_ONLY,
                'media_download_upload_size_bytes' => null,
                'media_download_next_retry_at' => null,
                'safe_error_code' => DialogAutomationGate::REASON_BLACKLIST_STAGE,
                'safe_error_message' => 'Media download skipped because the dialog stage is blacklisted.',
                'updated_at' => now(),
            ],
        );
    }

    private function failNonClaimableDownloads(Channel $channel): void
    {
        $this->updateLockedAttachments(
            MessageAttachment::query()
                ->where('channel_id', $channel->id)
                ->where('provider', MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT)
                ->where('download_status', MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD)
                ->whereNotIn('media_kind', self::supportedMediaKinds()),
            [
                'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED,
                'media_download_upload_size_bytes' => null,
                'media_download_next_retry_at' => null,
                'safe_error_code' => self::ERROR_UNSUPPORTED_MEDIA_KIND,
                'safe_error_message' => 'Media kind is not supported by Telegram Account media download.',
                'updated_at' => now(),
            ],
        );

        $this->updateLockedAttachments(
            MessageAttachment::query()
                ->where('channel_id', $channel->id)
                ->where('provider', MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT)
                ->where('download_status', MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD)
                ->where(function (Builder $query): void {
                    $query
                        ->where(function (Builder $fileIdQuery): void {
                            $fileIdQuery
                                ->whereNull('provider_file_id')
                                ->orWhere('provider_file_id', '');
                        })
                        ->where(function (Builder $referenceQuery): void {
                            $referenceQuery
                                ->whereNull('provider_file_reference')
                                ->orWhere('provider_file_reference', '');
                        });
                }),
            [
                'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED,
                'media_download_upload_size_bytes' => null,
                'media_download_next_retry_at' => null,
                'safe_error_code' => self::ERROR_MISSING_PROVIDER_FILE_ID,
                'safe_error_message' => 'Telegram Account media download requires provider_file_id.',
                'updated_at' => now(),
            ],
        );
    }

    /**
     * @param  Builder<MessageAttachment>  $query
     * @param  array<string, mixed>  $attributes
     */
    private function updateLockedAttachments(Builder $query, array $attributes): void
    {
        foreach ($query->lockForUpdate()->get() as $attachment) {
            $attachment->forceFill($attributes)->save();
        }
    }

    private function quotaErrorMessage(?string $reason): string
    {
        return match ($reason) {
            InboundMediaDownloadPolicy::REASON_TRAFFIC_QUOTA_EXCEEDED => 'Дневной лимит загрузки медиа для канала исчерпан.',
            default => 'Недостаточно доступного места для загрузки медиа.',
        };
    }
}
