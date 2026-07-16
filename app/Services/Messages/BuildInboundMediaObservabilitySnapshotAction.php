<?php

namespace App\Services\Messages;

use App\Jobs\CleanupInboundMediaPartialFilesJob;
use App\Jobs\DeleteRolledBackInboundMediaFileJob;
use App\Models\MediaDownloadStorageLedger;
use App\Models\MessageAttachment;
use App\Services\TelegramAccount\StoreTelegramAccountMediaDownloadResultAction;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class BuildInboundMediaObservabilitySnapshotAction
{
    public const SCHEMA_VERSION = 1;

    /** @var list<string> */
    private const PROVIDER_ERROR_REASONS = [
        'network_error',
        'rate_limited',
        'provider_unavailable',
        'provider_timeout',
        'provider_authorization_failed',
        'source_unavailable',
        'provider_request_failed',
        'bot_media_download_invalid_payload',
        StoreTelegramAccountMediaDownloadResultAction::ERROR_GATEWAY_DOWNLOAD_FAILED,
        'temporary_failure',
        'unexpected_failure',
    ];

    private const TERMINAL_RETRY_REASON = 'retries_exhausted';

    /** @var list<string> */
    private const TELEGRAM_ACCOUNT_NON_PROVIDER_ERROR_REASONS = [
        self::TERMINAL_RETRY_REASON,
        'integrity_mismatch',
        'lease_expired',
        'upload_target_unavailable',
        InboundMediaDownloadPolicy::REASON_STORAGE_QUOTA_EXCEEDED,
        InboundMediaDownloadPolicy::REASON_TRAFFIC_QUOTA_EXCEEDED,
    ];

    /** @var list<string> */
    private const QUOTA_DENIAL_REASONS = [
        InboundMediaDownloadPolicy::REASON_STORAGE_QUOTA_EXCEEDED,
        InboundMediaDownloadPolicy::REASON_TRAFFIC_QUOTA_EXCEEDED,
    ];

    public function __construct(
        private readonly InspectInboundMediaStorageReadOnlyAction $storageInspector,
        private readonly InspectInboundMediaQuotaDriftAction $quotaDriftInspector,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(int $windowMinutes, ?int $orphanScanLimit = null): array
    {
        if ($windowMinutes < 1) {
            throw new InvalidArgumentException('Observability window must be a positive integer.');
        }

        $connection = DB::connection();

        if ($connection->getDriverName() === 'pgsql' && $connection->transactionLevel() === 0) {
            return $connection->transaction(function () use ($windowMinutes, $orphanScanLimit): array {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
                DB::table('media_download_storage_budgets')->count();

                return $this->buildSnapshot($windowMinutes, $orphanScanLimit);
            });
        }

        return $this->buildSnapshot($windowMinutes, $orphanScanLimit);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSnapshot(int $windowMinutes, ?int $orphanScanLimit): array
    {
        $snapshotAt = CarbonImmutable::instance(now());
        $windowStartedAt = $snapshotAt->subMinutes($windowMinutes);
        $orphanScanLimit ??= (int) config('inbound_media.observability.orphan_scan_limit', 5000);

        $storage = $this->storageInspector->handle($snapshotAt, $orphanScanLimit);
        $quotaDrift = $this->quotaDriftInspector->handle();
        $current = $this->currentMetrics($snapshotAt, $storage, $quotaDrift);
        $window = $this->windowMetrics($windowStartedAt, $snapshotAt);
        $incompleteReasons = array_values(array_unique($storage['incomplete_reasons']));
        $blockingAnomalies = $this->blockingAnomalies($current, $storage['orphan_observed']);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'snapshot_at' => $snapshotAt->toIso8601String(),
            'window_minutes' => $windowMinutes,
            'complete' => $incompleteReasons === [],
            'incomplete_reasons' => $incompleteReasons,
            'current' => $current,
            'window' => $window,
            'blocking_anomalies' => $blockingAnomalies,
        ];
    }

    /**
     * @param  array{
     *     storage_ledger_drift:int,
     *     storage_scan_complete:bool,
     *     orphan_count:int|null,
     *     orphan_observed:bool,
     *     orphan_scan_truncated:bool,
     *     incomplete_reasons:list<string>
     * }  $storage
     * @param  array{storage_drift_rows:int,traffic_drift_rows:int}  $quotaDrift
     * @return array<string, mixed>
     */
    private function currentMetrics(
        CarbonImmutable $snapshotAt,
        array $storage,
        array $quotaDrift,
    ): array {
        return [
            'queue_age_seconds_max' => $this->queueAgeSecondsMax($snapshotAt),
            'active_leases' => MessageAttachment::query()
                ->where('download_status', MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING)
                ->count(),
            'stale_leases' => $this->staleLeasesQuery($snapshotAt)->count(),
            'unresolved_cleanup_failure_count' => $this->cleanupFailureCount(),
            'orphan_count' => $storage['orphan_count'],
            'orphan_scan_truncated' => $storage['orphan_scan_truncated'],
            'storage_ledger_drift' => $storage['storage_ledger_drift']
                + $quotaDrift['storage_drift_rows'],
            'traffic_ledger_drift' => $quotaDrift['traffic_drift_rows'],
            'traffic_channels' => $this->trafficChannels($snapshotAt),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function windowMetrics(
        CarbonImmutable $windowStartedAt,
        CarbonImmutable $snapshotAt,
    ): array {
        $providerReasonPlaceholders = implode(',', array_fill(0, count(self::PROVIDER_ERROR_REASONS), '?'));
        $providerTerminalLedgerReasons = [
            ...self::PROVIDER_ERROR_REASONS,
            self::TERMINAL_RETRY_REASON,
        ];
        $providerTerminalLedgerReasonPlaceholders = implode(
            ',',
            array_fill(0, count($providerTerminalLedgerReasons), '?'),
        );
        $telegramAccountNonProviderReasonPlaceholders = implode(
            ',',
            array_fill(0, count(self::TELEGRAM_ACCOUNT_NON_PROVIDER_ERROR_REASONS), '?'),
        );
        $quotaReasonPlaceholders = implode(',', array_fill(0, count(self::QUOTA_DENIAL_REASONS), '?'));
        $transitions = DB::table('media_download_state_transitions as transitions')
            ->whereBetween('created_at', [$windowStartedAt, $snapshotAt])
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN action = ? THEN COALESCE(actual_bytes, 0) ELSE 0 END), 0) AS throughput_bytes',
                ['download_succeeded'],
            )
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN action = ? THEN 1 ELSE 0 END), 0) AS successful_downloads',
                ['download_succeeded'],
            )
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN old_status = ? AND new_status = ? THEN 1 ELSE 0 END), 0) AS retry_count',
                [
                    MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING,
                    MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD,
                ],
            )
            ->selectRaw(
                sprintf(
                    <<<'SQL'
                        COALESCE(SUM(CASE
                            WHEN (
                                safe_reason IN (%1$s)
                                OR (
                                    transport = ?
                                    AND safe_reason IS NOT NULL
                                    AND safe_reason NOT IN (%2$s)
                                )
                                OR (
                                    safe_reason = ?
                                    AND (
                                        SELECT final_attempt.release_reason
                                        FROM media_download_traffic_ledgers AS final_attempt
                                        WHERE final_attempt.message_attachment_id = transitions.message_attachment_id
                                            AND final_attempt.generation = transitions.generation
                                            AND final_attempt.released_at IS NOT NULL
                                            AND final_attempt.released_at <= transitions.created_at
                                        ORDER BY final_attempt.attempt_number DESC, final_attempt.id DESC
                                        LIMIT 1
                                    ) IN (%3$s)
                                )
                            )
                            AND old_status = ?
                            AND new_status IN (?, ?)
                            THEN 1
                            ELSE 0
                        END), 0) AS provider_error_count
                    SQL,
                    $providerReasonPlaceholders,
                    $telegramAccountNonProviderReasonPlaceholders,
                    $providerTerminalLedgerReasonPlaceholders,
                ),
                [
                    ...self::PROVIDER_ERROR_REASONS,
                    MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT,
                    ...self::TELEGRAM_ACCOUNT_NON_PROVIDER_ERROR_REASONS,
                    self::TERMINAL_RETRY_REASON,
                    ...$providerTerminalLedgerReasons,
                    MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING,
                    MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD,
                    MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED,
                ],
            )
            ->selectRaw(
                sprintf(
                    'COALESCE(SUM(CASE WHEN safe_reason IN (%s) THEN 1 ELSE 0 END), 0) AS quota_denial_count',
                    $quotaReasonPlaceholders,
                ),
                self::QUOTA_DENIAL_REASONS,
            )
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN safe_reason = ? THEN 1 ELSE 0 END), 0) AS integrity_mismatch_count',
                ['integrity_mismatch'],
            )
            ->first();
        $storageReserved = DB::table('media_download_storage_ledgers')
            ->whereBetween('created_at', [$windowStartedAt, $snapshotAt])
            ->sum('reserved_bytes');
        $storageReleased = DB::table('media_download_storage_ledgers')
            ->where('status', MediaDownloadStorageLedger::STATUS_RELEASED)
            ->whereBetween('released_at', [$windowStartedAt, $snapshotAt])
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN used_bytes > 0 THEN used_bytes ELSE reserved_bytes END), 0) AS released_bytes',
            )
            ->value('released_bytes');
        $trafficReserved = DB::table('media_download_traffic_ledgers')
            ->whereBetween('created_at', [$windowStartedAt, $snapshotAt])
            ->sum('reserved_bytes');
        $trafficFinalized = DB::table('media_download_traffic_ledgers')
            ->whereBetween('released_at', [$windowStartedAt, $snapshotAt])
            ->selectRaw('COALESCE(SUM(consumed_bytes), 0) AS consumed_bytes')
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN reserved_bytes > consumed_bytes THEN reserved_bytes - consumed_bytes ELSE 0 END), 0) AS released_bytes',
            )
            ->first();

        return [
            'throughput_bytes' => (int) ($transitions->throughput_bytes ?? 0),
            'successful_downloads' => (int) ($transitions->successful_downloads ?? 0),
            'retry_count' => (int) ($transitions->retry_count ?? 0),
            'provider_error_count' => (int) ($transitions->provider_error_count ?? 0),
            'quota_denial_count' => (int) ($transitions->quota_denial_count ?? 0),
            'integrity_mismatch_count' => (int) ($transitions->integrity_mismatch_count ?? 0),
            'cleanup_failure_count' => $this->cleanupFailureCount($windowStartedAt, $snapshotAt),
            'storage_bytes' => [
                'reserved' => (int) $storageReserved,
                'used' => (int) ($transitions->throughput_bytes ?? 0),
                'released' => (int) $storageReleased,
            ],
            'traffic_bytes' => [
                'reserved' => (int) $trafficReserved,
                'consumed' => (int) ($trafficFinalized->consumed_bytes ?? 0),
                'released' => (int) ($trafficFinalized->released_bytes ?? 0),
            ],
        ];
    }

    private function queueAgeSecondsMax(CarbonImmutable $snapshotAt): int
    {
        $latestQueuedTransitions = DB::table('media_download_state_transitions')
            ->selectRaw('message_attachment_id, MAX(created_at) AS queued_at')
            ->where('new_status', MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD)
            ->groupBy('message_attachment_id');
        $oldestQueuedAt = DB::table('message_attachments as attachments')
            ->leftJoinSub(
                $latestQueuedTransitions,
                'queued_transitions',
                'queued_transitions.message_attachment_id',
                '=',
                'attachments.id',
            )
            ->where('attachments.download_status', MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD)
            ->where(function (QueryBuilder $query) use ($snapshotAt): void {
                $query
                    ->whereNull('attachments.media_download_next_retry_at')
                    ->orWhere('attachments.media_download_next_retry_at', '<=', $snapshotAt);
            })
            ->selectRaw('MIN(COALESCE(queued_transitions.queued_at, attachments.updated_at)) AS oldest_queued_at')
            ->value('oldest_queued_at');

        if ($oldestQueuedAt instanceof DateTimeInterface) {
            $oldestQueuedAt = CarbonImmutable::instance($oldestQueuedAt);
        } elseif (is_string($oldestQueuedAt) && $oldestQueuedAt !== '') {
            $oldestQueuedAt = CarbonImmutable::parse($oldestQueuedAt);
        } else {
            return 0;
        }

        return max(
            0,
            (int) $oldestQueuedAt->diffInSeconds($snapshotAt, true),
        );
    }

    /**
     * @return EloquentBuilder<MessageAttachment>
     */
    private function staleLeasesQuery(CarbonImmutable $snapshotAt): EloquentBuilder
    {
        $cutoff = $snapshotAt->subSeconds(
            max(1, (int) config('inbound_media.lease_stale_seconds', 120)),
        );

        return MessageAttachment::query()
            ->where('download_status', MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING)
            ->whereIn('provider', [
                MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT,
                MessageAttachment::PROVIDER_TELEGRAM_BOT,
                MessageAttachment::PROVIDER_MAX_BOT,
            ])
            ->where(function (EloquentBuilder $query): void {
                $query
                    ->where('provider', '!=', MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT)
                    ->orWhereNotNull('media_download_claim_token');
            })
            ->where(function (EloquentBuilder $query) use ($snapshotAt, $cutoff): void {
                $query
                    ->where('media_download_attempt_deadline_at', '<=', $snapshotAt)
                    ->orWhere('media_download_heartbeat_at', '<=', $cutoff)
                    ->orWhere(function (EloquentBuilder $query) use ($cutoff): void {
                        $query
                            ->whereNull('media_download_heartbeat_at')
                            ->where('media_download_claimed_at', '<=', $cutoff);
                    })
                    ->orWhere(function (EloquentBuilder $query) use ($cutoff): void {
                        $query
                            ->whereNull('media_download_heartbeat_at')
                            ->whereNull('media_download_claimed_at')
                            ->where('updated_at', '<=', $cutoff);
                    });
            });
    }

    private function cleanupFailureCount(
        ?CarbonImmutable $windowStartedAt = null,
        ?CarbonImmutable $snapshotAt = null,
    ): int {
        $rows = DB::table('failed_jobs')
            ->where(function (QueryBuilder $query): void {
                $query
                    ->where('payload', 'like', '%'.class_basename(CleanupInboundMediaPartialFilesJob::class).'%')
                    ->orWhere('payload', 'like', '%'.class_basename(DeleteRolledBackInboundMediaFileJob::class).'%');
            })
            ->when(
                $windowStartedAt instanceof CarbonImmutable && $snapshotAt instanceof CarbonImmutable,
                fn (QueryBuilder $query): QueryBuilder => $query->whereBetween(
                    'failed_at',
                    [$windowStartedAt, $snapshotAt],
                ),
            )
            ->orderBy('id')
            ->pluck('payload');

        $cleanupClasses = [
            CleanupInboundMediaPartialFilesJob::class,
            DeleteRolledBackInboundMediaFileJob::class,
        ];

        return $rows->filter(function (mixed $payload) use ($cleanupClasses): bool {
            if (! is_string($payload)) {
                return false;
            }

            $decoded = json_decode($payload, true);

            if (! is_array($decoded)) {
                return false;
            }

            return in_array(data_get($decoded, 'displayName'), $cleanupClasses, true)
                || in_array(data_get($decoded, 'data.commandName'), $cleanupClasses, true);
        })->count();
    }

    /**
     * @return list<array<string, int|float|string|bool|null>>
     */
    private function trafficChannels(CarbonImmutable $snapshotAt): array
    {
        $periodDate = $snapshotAt->toDateString();
        $dailyLimit = $this->trafficDailyLimitBytes();
        $budgets = DB::table('media_download_traffic_budgets')
            ->where('period_date', $periodDate)
            ->get(['channel_id', 'reserved_bytes', 'consumed_bytes'])
            ->keyBy('channel_id');

        return DB::table('channels')
            ->orderBy('id')
            ->pluck('id')
            ->map(function (mixed $channelId) use ($periodDate, $dailyLimit, $budgets): array {
                $channelId = (int) $channelId;
                $budget = $budgets->get($channelId);
                $reservedBytes = is_object($budget) ? (int) $budget->reserved_bytes : 0;
                $consumedBytes = is_object($budget) ? (int) $budget->consumed_bytes : 0;
                $usage = $reservedBytes + $consumedBytes;
                $utilization = $dailyLimit !== null && $dailyLimit > 0
                    ? round(($usage / $dailyLimit) * 100, 2)
                    : null;

                return [
                    'channel_id' => $channelId,
                    'period_date' => $periodDate,
                    'daily_limit_bytes' => $dailyLimit,
                    'reserved_bytes' => $reservedBytes,
                    'consumed_bytes' => $consumedBytes,
                    'utilization_percent' => $utilization,
                    'warning_80_reached' => $dailyLimit === null
                        ? null
                        : ($dailyLimit === 0 ? $usage > 0 : $utilization >= 80),
                    'over_limit' => $dailyLimit === null
                        ? null
                        : ($dailyLimit === 0 ? $usage > 0 : $usage > $dailyLimit),
                ];
            })
            ->all();
    }

    private function trafficDailyLimitBytes(): ?int
    {
        $configured = config('inbound_media.traffic.channel_daily_limit_bytes');

        if ($configured === null || $configured === '' || ! is_numeric($configured)) {
            return null;
        }

        return max(0, (int) $configured);
    }

    /**
     * @param  array<string, mixed>  $current
     * @return list<string>
     */
    private function blockingAnomalies(array $current, bool $orphanObserved): array
    {
        $blocking = [];

        if ($current['stale_leases'] > 0) {
            $blocking[] = 'stale_lease';
        }

        if ($current['unresolved_cleanup_failure_count'] > 0) {
            $blocking[] = 'unresolved_cleanup_failure';
        }

        if (($current['orphan_count'] ?? 0) > 0 || $orphanObserved) {
            $blocking[] = 'orphan_object';
        }

        if ($current['storage_ledger_drift'] > 0) {
            $blocking[] = 'storage_ledger_drift';
        }

        if ($current['traffic_ledger_drift'] > 0) {
            $blocking[] = 'traffic_ledger_drift';
        }

        return $blocking;
    }
}
