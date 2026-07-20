<?php

namespace App\Services\Messages;

use App\Jobs\CleanupInboundMediaPartialFilesJob;
use App\Jobs\DownloadBotMessageAttachmentJob;
use App\Models\MessageAttachment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReapStaleInboundMediaDownloadsAction
{
    public const ERROR_LEASE_EXPIRED = 'lease_expired';

    public const ERROR_PARTIAL_CLEANUP_FAILED = 'partial_cleanup_failed';

    public function __construct(
        private readonly DeleteInboundMediaPartialFilesAction $deletePartialFiles,
        private readonly InboundMediaQuotaLedger $quotaLedger,
        private readonly InboundMediaRetrySchedule $retrySchedule,
    ) {}

    /**
     * @return array{inspected:int,retried:int,failed:int,cleanup_failed:int,skipped:int}
     */
    public function handle(int $limit = 100): array
    {
        $limit = min(max($limit, 1), 500);
        $ids = $this->staleCandidatesQuery()
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');
        $stats = [
            'inspected' => 0,
            'retried' => 0,
            'failed' => 0,
            'cleanup_failed' => 0,
            'skipped' => 0,
        ];

        foreach ($ids as $id) {
            $result = $this->reapOne((int) $id);
            $stats['inspected']++;
            $stats[$result['status']]++;

            if ($result['dispatch_manual_bot_retry']) {
                DownloadBotMessageAttachmentJob::dispatch((int) $id)
                    ->delay($result['next_retry_at'])
                    ->afterCommit();
            }

            if ($result['status'] === 'cleanup_failed') {
                CleanupInboundMediaPartialFilesJob::dispatch(
                    (int) $id,
                    $result['cleanup_generation'],
                    $result['cleanup_claim_token'],
                )->afterCommit();
            }
        }

        return $stats;
    }

    /**
     * @return array{status:'retried'|'failed'|'cleanup_failed'|'skipped',dispatch_manual_bot_retry:bool,next_retry_at:mixed,cleanup_generation:?int,cleanup_claim_token:?string}
     */
    private function reapOne(int $attachmentId): array
    {
        return DB::transaction(function () use ($attachmentId): array {
            $attachment = MessageAttachment::query()
                ->whereKey($attachmentId)
                ->lockForUpdate()
                ->first();

            if (
                ! $attachment instanceof MessageAttachment
                || ! $this->isReapable($attachment)
                || ! $this->isStale($attachment)
                || $this->hasStableFileReference($attachment)
            ) {
                return $this->result('skipped');
            }

            $cleanupGeneration = max(1, (int) $attachment->media_download_generation);
            $cleanupClaimToken = $this->cleanupClaimToken($attachment);
            $transferredBytes = $this->deletePartialFiles->scopedBytes(
                $attachment,
                $cleanupClaimToken,
                $cleanupGeneration,
            );

            if (
                $transferredBytes === null
                || ! $this->deletePartialFiles->handle($attachment, $cleanupClaimToken, $cleanupGeneration)
            ) {
                $attachment->forceFill([
                    'media_download_claim_token' => $cleanupClaimToken !== null
                        ? 'revoked-'.$cleanupClaimToken
                        : 'revoked-'.Str::uuid()->toString(),
                    'safe_error_code' => self::ERROR_PARTIAL_CLEANUP_FAILED,
                    'safe_error_message' => 'Очистка временного файла будет повторена автоматически.',
                ])->save();

                return $this->result(
                    'cleanup_failed',
                    cleanupGeneration: $cleanupGeneration,
                    cleanupClaimToken: $cleanupClaimToken,
                );
            }

            $attemptNumber = max(1, (int) $attachment->media_download_attempts);
            $this->quotaLedger->failAttempt(
                $attachment,
                $attachment->mediaDownloadLedgerAttemptNumber(),
                $transferredBytes,
                self::ERROR_LEASE_EXPIRED,
            );

            $willRetry = $this->retrySchedule->willRetry($attemptNumber);
            $nextRetryAt = $willRetry
                ? $this->retrySchedule->nextRetryAt($attemptNumber)
                : null;
            $errorCode = $willRetry
                ? self::ERROR_LEASE_EXPIRED
                : $this->retrySchedule->terminalErrorCode(self::ERROR_LEASE_EXPIRED);

            $attachment->forceFill([
                'download_status' => $willRetry
                    ? MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD
                    : MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED,
                'media_download_claim_token' => null,
                'media_download_upload_size_bytes' => null,
                'media_download_next_retry_at' => $nextRetryAt,
                'media_download_claimed_at' => null,
                'media_download_heartbeat_at' => null,
                'media_download_attempt_deadline_at' => null,
                'safe_error_code' => $errorCode,
                'safe_error_message' => $willRetry
                    ? 'Загрузка прервалась. Повторим автоматически.'
                    : 'Не удалось загрузить файл после нескольких попыток.',
            ])->save();

            $dispatchManualBotRetry = $willRetry
                && $attachment->manual_download_requested_at !== null
                && in_array($attachment->provider, [
                    MessageAttachment::PROVIDER_TELEGRAM_BOT,
                    MessageAttachment::PROVIDER_MAX_BOT,
                ], true);

            return $this->result(
                $willRetry ? 'retried' : 'failed',
                $dispatchManualBotRetry,
                $nextRetryAt,
            );
        }, 3);
    }

    /**
     * @return Builder<MessageAttachment>
     */
    private function staleCandidatesQuery(): Builder
    {
        $cutoff = now()->subSeconds($this->leaseStaleSeconds());

        return MessageAttachment::query()
            ->where('download_status', MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING)
            ->whereIn('provider', [
                MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT,
                MessageAttachment::PROVIDER_TELEGRAM_BOT,
                MessageAttachment::PROVIDER_MAX_BOT,
            ])
            ->where(function (Builder $query): void {
                $query
                    ->where('provider', '!=', MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT)
                    ->orWhereNotNull('media_download_claim_token');
            })
            ->where(function (Builder $query) use ($cutoff): void {
                $query
                    ->where('media_download_attempt_deadline_at', '<=', now())
                    ->orWhere('media_download_heartbeat_at', '<=', $cutoff)
                    ->orWhere(function (Builder $query) use ($cutoff): void {
                        $query
                            ->whereNull('media_download_heartbeat_at')
                            ->where('media_download_claimed_at', '<=', $cutoff);
                    })
                    ->orWhere(function (Builder $query) use ($cutoff): void {
                        $query
                            ->whereNull('media_download_heartbeat_at')
                            ->whereNull('media_download_claimed_at')
                            ->where('updated_at', '<=', $cutoff);
                    });
            });
    }

    private function isReapable(MessageAttachment $attachment): bool
    {
        if (
            $attachment->download_status !== MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING
            || ! in_array($attachment->provider, [
                MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT,
                MessageAttachment::PROVIDER_TELEGRAM_BOT,
                MessageAttachment::PROVIDER_MAX_BOT,
            ], true)
        ) {
            return false;
        }

        return $attachment->provider !== MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT
            || filled($attachment->media_download_claim_token);
    }

    private function isStale(MessageAttachment $attachment): bool
    {
        if (
            $attachment->media_download_attempt_deadline_at !== null
            && $attachment->media_download_attempt_deadline_at->isPast()
        ) {
            return true;
        }

        $activityAt = $attachment->media_download_heartbeat_at
            ?? $attachment->media_download_claimed_at
            ?? $attachment->updated_at;

        return $activityAt !== null
            && $activityAt->lte(now()->subSeconds($this->leaseStaleSeconds()));
    }

    private function hasStableFileReference(MessageAttachment $attachment): bool
    {
        return filled($attachment->local_disk) || filled($attachment->local_path);
    }

    private function leaseStaleSeconds(): int
    {
        return max(1, (int) config('inbound_media.lease_stale_seconds', 120));
    }

    private function cleanupClaimToken(MessageAttachment $attachment): ?string
    {
        $claimToken = trim((string) $attachment->media_download_claim_token);

        if ($claimToken === '') {
            return null;
        }

        return str_starts_with($claimToken, 'revoked-')
            ? substr($claimToken, strlen('revoked-'))
            : $claimToken;
    }

    /**
     * @return array{status:'retried'|'failed'|'cleanup_failed'|'skipped',dispatch_manual_bot_retry:bool,next_retry_at:mixed,cleanup_generation:?int,cleanup_claim_token:?string}
     */
    private function result(
        string $status,
        bool $dispatchManualBotRetry = false,
        mixed $nextRetryAt = null,
        ?int $cleanupGeneration = null,
        ?string $cleanupClaimToken = null,
    ): array {
        return [
            'status' => $status,
            'dispatch_manual_bot_retry' => $dispatchManualBotRetry,
            'next_retry_at' => $nextRetryAt,
            'cleanup_generation' => $cleanupGeneration,
            'cleanup_claim_token' => $cleanupClaimToken,
        ];
    }
}
