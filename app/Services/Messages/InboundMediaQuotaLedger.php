<?php

namespace App\Services\Messages;

use App\Data\Messages\InboundMediaQuotaDecision;
use App\Models\MediaDownloadStorageLedger;
use App\Models\MediaDownloadTrafficLedger;
use App\Models\MessageAttachment;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

class InboundMediaQuotaLedger
{
    private const STORAGE_SCOPE_GLOBAL = 'global';

    private const STORAGE_SCOPE_CHANNEL = 'channel';

    private int $previewSnapshotDepth = 0;

    /** @var array<string, object> */
    private array $previewStorageBudgets = [];

    /** @var array<string, object> */
    private array $previewTrafficBudgets = [];

    private bool $previewAvailableBytesResolved = false;

    private ?int $previewAvailableBytes = null;

    public function __construct(
        private readonly InboundMediaStorageCapacity $storageCapacity,
    ) {}

    public function withPreviewSnapshot(callable $callback): mixed
    {
        $isRootSnapshot = $this->previewSnapshotDepth === 0;

        if ($isRootSnapshot) {
            $this->resetPreviewSnapshot();
        }

        $this->previewSnapshotDepth++;

        try {
            return $callback();
        } finally {
            $this->previewSnapshotDepth--;

            if ($isRootSnapshot) {
                $this->resetPreviewSnapshot();
            }
        }
    }

    public function previewForAttempt(
        MessageAttachment $attachment,
        ?int $unknownSizeReservationBytes = null,
    ): InboundMediaQuotaDecision {
        if (! $attachment->exists || $attachment->getKey() === null) {
            return new InboundMediaQuotaDecision(allowed: true);
        }

        $globalBudget = $this->previewStorageBudget(self::STORAGE_SCOPE_GLOBAL, 0);
        $channelBudget = $this->previewStorageBudget(
            self::STORAGE_SCOPE_CHANNEL,
            (int) $attachment->channel_id,
        );
        $trafficBudget = $this->previewTrafficBudget(
            (int) $attachment->channel_id,
            now()->toDateString(),
        );
        $storageBytes = $this->requestedBytes($attachment, $unknownSizeReservationBytes);
        $trafficBytes = $this->trafficReservationBytes(
            $attachment,
            null,
            $trafficBudget,
            $unknownSizeReservationBytes,
        );
        $storageReason = $this->storageDenialReason($storageBytes, $globalBudget, $channelBudget);
        $trafficReason = $this->trafficDenialReason($attachment, $trafficBytes, $trafficBudget);

        if ($storageReason !== null && (bool) config('inbound_media.storage.enforce', false)) {
            return new InboundMediaQuotaDecision(allowed: false, reason: $storageReason);
        }

        if ($trafficReason !== null && (bool) config('inbound_media.traffic.enforce', false)) {
            return new InboundMediaQuotaDecision(allowed: false, reason: $trafficReason);
        }

        return new InboundMediaQuotaDecision(
            allowed: true,
            shadowReason: $storageReason ?? $trafficReason,
            storageReservedBytes: $storageBytes,
            trafficReservedBytes: $trafficBytes,
        );
    }

    public function reserveForAttempt(
        MessageAttachment $attachment,
        int $attemptNumber,
    ): InboundMediaQuotaDecision {
        if (! $attachment->exists || $attachment->getKey() === null) {
            throw new LogicException('Media quota reservation requires a persisted attachment.');
        }

        if ($attemptNumber < 1) {
            throw new LogicException('Media quota reservation requires a positive attempt number.');
        }

        return DB::transaction(function () use ($attachment, $attemptNumber): InboundMediaQuotaDecision {
            /** @var MessageAttachment $lockedAttachment */
            $lockedAttachment = MessageAttachment::query()
                ->whereKey($attachment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $generation = max(1, (int) $lockedAttachment->media_download_generation);
            $expiresAt = ($lockedAttachment->media_download_attempt_deadline_at ?? now()->addSeconds(
                max(1, (int) config('inbound_media.attempt_deadline_seconds', 6 * 60 * 60)),
            ))->copy()->addSeconds(
                max(0, (int) config('inbound_media.reservation_ttl_buffer_seconds', 15 * 60)),
            );

            $storageLedger = MediaDownloadStorageLedger::query()
                ->where('message_attachment_id', $lockedAttachment->getKey())
                ->where('generation', $generation)
                ->lockForUpdate()
                ->first();

            $trafficLedger = MediaDownloadTrafficLedger::query()
                ->where('message_attachment_id', $lockedAttachment->getKey())
                ->where('generation', $generation)
                ->where('attempt_number', $attemptNumber)
                ->lockForUpdate()
                ->first();

            if (
                $trafficLedger instanceof MediaDownloadTrafficLedger
                && $trafficLedger->status !== MediaDownloadTrafficLedger::STATUS_RESERVED
            ) {
                throw new LogicException('Media traffic attempt is already finalized.');
            }

            $globalBudget = $this->lockStorageBudget(self::STORAGE_SCOPE_GLOBAL, 0);
            $channelBudget = $this->lockStorageBudget(
                self::STORAGE_SCOPE_CHANNEL,
                (int) $lockedAttachment->channel_id,
            );
            $trafficBudget = $this->lockTrafficBudget((int) $lockedAttachment->channel_id, now()->toDateString());

            $storageBytes = $this->storageReservationBytes($lockedAttachment, $storageLedger);
            $trafficBytes = $this->trafficReservationBytes($lockedAttachment, $trafficLedger, $trafficBudget);
            $storageReason = $this->storageDenialReason(
                $storageBytes,
                $globalBudget,
                $channelBudget,
            );
            $trafficReason = $trafficLedger instanceof MediaDownloadTrafficLedger
                ? null
                : $this->trafficDenialReason($lockedAttachment, $trafficBytes, $trafficBudget);

            $storageEnforced = (bool) config('inbound_media.storage.enforce', false);
            $trafficEnforced = (bool) config('inbound_media.traffic.enforce', false);

            if ($storageReason !== null && $storageEnforced) {
                return new InboundMediaQuotaDecision(
                    allowed: false,
                    reason: $storageReason,
                );
            }

            if ($trafficReason !== null && $trafficEnforced) {
                return new InboundMediaQuotaDecision(
                    allowed: false,
                    reason: $trafficReason,
                );
            }

            if ($storageBytes > 0) {
                $this->reserveStorage(
                    $lockedAttachment,
                    $storageLedger,
                    $globalBudget,
                    $channelBudget,
                    $generation,
                    $storageBytes,
                    $expiresAt,
                );
            }

            if (! $trafficLedger instanceof MediaDownloadTrafficLedger) {
                MediaDownloadTrafficLedger::query()->create([
                    'message_attachment_id' => $lockedAttachment->getKey(),
                    'channel_id' => $lockedAttachment->channel_id,
                    'generation' => $generation,
                    'attempt_number' => $attemptNumber,
                    'period_date' => now()->toDateString(),
                    'status' => MediaDownloadTrafficLedger::STATUS_RESERVED,
                    'reserved_bytes' => $trafficBytes,
                    'consumed_bytes' => 0,
                    'checkpoint_bytes' => 0,
                    'expires_at' => $expiresAt,
                ]);

                if ($trafficBytes > 0) {
                    $this->updateTrafficBudget(
                        $trafficBudget,
                        reservedBytes: (int) $trafficBudget->reserved_bytes + $trafficBytes,
                        consumedBytes: (int) $trafficBudget->consumed_bytes,
                    );
                }
            }

            return new InboundMediaQuotaDecision(
                allowed: true,
                shadowReason: $storageReason ?? $trafficReason,
                storageReservedBytes: $storageBytes,
                trafficReservedBytes: $trafficBytes,
            );
        }, 3);
    }

    public function checkpointTraffic(
        MessageAttachment $attachment,
        int $attemptNumber,
        int $receivedBytes,
    ): void {
        DB::transaction(function () use ($attachment, $attemptNumber, $receivedBytes): void {
            $ledger = $this->lockTrafficLedgerOrNull($attachment, $attemptNumber);

            if (! $ledger instanceof MediaDownloadTrafficLedger) {
                throw new RuntimeException('Media traffic reservation was not found for checkpoint.');
            }
            $receivedBytes = max(0, $receivedBytes);

            $this->assertWithinDownloadLimit($attachment, $receivedBytes);

            if ($receivedBytes > (int) $ledger->reserved_bytes) {
                $trafficBudget = $this->lockTrafficBudget(
                    (int) $ledger->channel_id,
                    $ledger->period_date->toDateString(),
                );
                $reservationGrowth = $receivedBytes - (int) $ledger->reserved_bytes;

                if (
                    (bool) config('inbound_media.traffic.enforce', false)
                    && $this->trafficBudgetWouldExceedLimit($trafficBudget, $reservationGrowth)
                ) {
                    throw new InboundMediaQuotaExceededException(
                        InboundMediaDownloadPolicy::REASON_TRAFFIC_QUOTA_EXCEEDED,
                        $receivedBytes,
                    );
                }

                $this->updateTrafficBudget(
                    $trafficBudget,
                    reservedBytes: (int) $trafficBudget->reserved_bytes + $reservationGrowth,
                    consumedBytes: (int) $trafficBudget->consumed_bytes,
                );
                $ledger->forceFill([
                    'reserved_bytes' => $receivedBytes,
                ]);
            }

            $ledger->forceFill([
                'checkpoint_bytes' => max((int) $ledger->checkpoint_bytes, $receivedBytes),
            ])->save();
        }, 3);
    }

    public function assertCanCompleteAttempt(
        MessageAttachment $attachment,
        int $attemptNumber,
        int $actualBytes,
    ): void {
        DB::transaction(function () use ($attachment, $attemptNumber, $actualBytes): void {
            $actualBytes = max(0, $actualBytes);
            $this->assertWithinDownloadLimit($attachment, $actualBytes);
            $storageLedger = $this->lockStorageLedgerOrNull($attachment);
            $trafficLedger = $this->lockTrafficLedgerOrNull($attachment, $attemptNumber);

            if (! $storageLedger instanceof MediaDownloadStorageLedger || ! $trafficLedger instanceof MediaDownloadTrafficLedger) {
                throw new RuntimeException('Media quota ledgers are incomplete for the current attempt.');
            }

            if ($storageLedger->status === MediaDownloadStorageLedger::STATUS_RESERVED) {
                $globalBudget = $this->lockStorageBudget(self::STORAGE_SCOPE_GLOBAL, 0);
                $channelBudget = $this->lockStorageBudget(
                    self::STORAGE_SCOPE_CHANNEL,
                    (int) $storageLedger->channel_id,
                );
                $reason = $this->storageCompletionDenialReason(
                    $actualBytes,
                    $storageLedger,
                    $globalBudget,
                    $channelBudget,
                    checkPhysicalSpace: true,
                );

                if ($reason !== null && (bool) config('inbound_media.storage.enforce', false)) {
                    throw new InboundMediaQuotaExceededException($reason, $actualBytes);
                }
            }

            if ($trafficLedger->status === MediaDownloadTrafficLedger::STATUS_RESERVED) {
                $trafficBudget = $this->lockTrafficBudget(
                    (int) $trafficLedger->channel_id,
                    $trafficLedger->period_date->toDateString(),
                );
                $reason = $this->trafficCompletionDenialReason($actualBytes, $trafficLedger, $trafficBudget);

                if ($reason !== null && (bool) config('inbound_media.traffic.enforce', false)) {
                    throw new InboundMediaQuotaExceededException($reason, $actualBytes);
                }
            }
        }, 3);
    }

    public function completeAttempt(
        MessageAttachment $attachment,
        int $attemptNumber,
        int $actualBytes,
    ): void {
        DB::transaction(function () use ($attachment, $attemptNumber, $actualBytes): void {
            $actualBytes = max(0, $actualBytes);
            $this->assertWithinDownloadLimit($attachment, $actualBytes);
            $storageLedger = $this->lockStorageLedgerOrNull($attachment);
            $trafficLedger = $this->lockTrafficLedgerOrNull($attachment, $attemptNumber);

            if ($storageLedger === null && $trafficLedger === null) {
                return;
            }

            if (! $storageLedger instanceof MediaDownloadStorageLedger || ! $trafficLedger instanceof MediaDownloadTrafficLedger) {
                throw new RuntimeException('Media quota ledgers are incomplete for the current attempt.');
            }

            if ($storageLedger->status === MediaDownloadStorageLedger::STATUS_RESERVED) {
                $globalBudget = $this->lockStorageBudget(self::STORAGE_SCOPE_GLOBAL, 0);
                $channelBudget = $this->lockStorageBudget(
                    self::STORAGE_SCOPE_CHANNEL,
                    (int) $storageLedger->channel_id,
                );
                $reason = $this->storageCompletionDenialReason(
                    $actualBytes,
                    $storageLedger,
                    $globalBudget,
                    $channelBudget,
                    checkPhysicalSpace: false,
                );

                if ($reason !== null && (bool) config('inbound_media.storage.enforce', false)) {
                    throw new InboundMediaQuotaExceededException($reason, $actualBytes);
                }

                $reservedBytes = (int) $storageLedger->reserved_bytes;

                $this->updateStorageBudget(
                    $globalBudget,
                    reservedBytes: max(0, (int) $globalBudget->reserved_bytes - $reservedBytes),
                    usedBytes: (int) $globalBudget->used_bytes + $actualBytes,
                );
                $this->updateStorageBudget(
                    $channelBudget,
                    reservedBytes: max(0, (int) $channelBudget->reserved_bytes - $reservedBytes),
                    usedBytes: (int) $channelBudget->used_bytes + $actualBytes,
                );
                $storageLedger->forceFill([
                    'status' => MediaDownloadStorageLedger::STATUS_USED,
                    'reserved_bytes' => max($reservedBytes, $actualBytes),
                    'used_bytes' => $actualBytes,
                    'expires_at' => null,
                ])->save();
            }

            if ($trafficLedger->status === MediaDownloadTrafficLedger::STATUS_RESERVED) {
                $trafficBudget = $this->lockTrafficBudget(
                    (int) $trafficLedger->channel_id,
                    $trafficLedger->period_date->toDateString(),
                );
                $reason = $this->trafficCompletionDenialReason($actualBytes, $trafficLedger, $trafficBudget);

                if ($reason !== null && (bool) config('inbound_media.traffic.enforce', false)) {
                    throw new InboundMediaQuotaExceededException($reason, $actualBytes);
                }

                $reservedBytes = (int) $trafficLedger->reserved_bytes;

                $this->updateTrafficBudget(
                    $trafficBudget,
                    reservedBytes: max(0, (int) $trafficBudget->reserved_bytes - $reservedBytes),
                    consumedBytes: (int) $trafficBudget->consumed_bytes + $actualBytes,
                );
                $trafficLedger->forceFill([
                    'status' => MediaDownloadTrafficLedger::STATUS_CONSUMED,
                    'reserved_bytes' => max($reservedBytes, $actualBytes),
                    'consumed_bytes' => $actualBytes,
                    'checkpoint_bytes' => $actualBytes,
                    'expires_at' => null,
                    'released_at' => now(),
                ])->save();
            }
        }, 3);
    }

    public function failAttempt(
        MessageAttachment $attachment,
        int $attemptNumber,
        int $transferredBytes,
        string $reason,
    ): void {
        DB::transaction(function () use ($attachment, $attemptNumber, $transferredBytes, $reason): void {
            $transferredBytes = max(0, $transferredBytes);
            $storageLedger = $this->lockStorageLedgerOrNull($attachment);
            $trafficLedger = $this->lockTrafficLedgerOrNull($attachment, $attemptNumber);

            if ($trafficLedger instanceof MediaDownloadTrafficLedger) {
                $transferredBytes = max($transferredBytes, (int) $trafficLedger->checkpoint_bytes);
            }

            if (
                $storageLedger instanceof MediaDownloadStorageLedger
                && $storageLedger->status === MediaDownloadStorageLedger::STATUS_RESERVED
            ) {
                $this->releaseStorageReservation($storageLedger, $reason);
            }

            if (
                $trafficLedger instanceof MediaDownloadTrafficLedger
                && $trafficLedger->status === MediaDownloadTrafficLedger::STATUS_RESERVED
            ) {
                $this->finalizeTrafficReservation($trafficLedger, $transferredBytes, $reason);
            }
        }, 3);
    }

    /**
     * @return array{storage_released:int,traffic_released:int,traffic_consumed_bytes:int}
     */
    public function releaseExpiredReservations(
        MessageAttachment $attachment,
        string $reason = 'reservation_expired',
        ?int $generation = null,
    ): array {
        return DB::transaction(function () use ($attachment, $reason, $generation): array {
            $now = now();
            $storageLedgers = MediaDownloadStorageLedger::query()
                ->where('message_attachment_id', $attachment->getKey())
                ->when(
                    $generation !== null,
                    static fn ($query) => $query->where('generation', max(1, $generation)),
                )
                ->where('status', MediaDownloadStorageLedger::STATUS_RESERVED)
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', $now)
                ->orderBy('generation')
                ->lockForUpdate()
                ->get();
            $trafficLedgers = MediaDownloadTrafficLedger::query()
                ->where('message_attachment_id', $attachment->getKey())
                ->when(
                    $generation !== null,
                    static fn ($query) => $query->where('generation', max(1, $generation)),
                )
                ->where('status', MediaDownloadTrafficLedger::STATUS_RESERVED)
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', $now)
                ->orderBy('generation')
                ->orderBy('attempt_number')
                ->lockForUpdate()
                ->get();
            $trafficConsumedBytes = 0;

            foreach ($storageLedgers as $storageLedger) {
                $this->releaseStorageReservation($storageLedger, $reason);
            }

            foreach ($trafficLedgers as $trafficLedger) {
                $consumedBytes = (int) $trafficLedger->checkpoint_bytes;
                $this->finalizeTrafficReservation($trafficLedger, $consumedBytes, $reason);
                $trafficConsumedBytes += $consumedBytes;
            }

            return [
                'storage_released' => $storageLedgers->count(),
                'traffic_released' => $trafficLedgers->count(),
                'traffic_consumed_bytes' => $trafficConsumedBytes,
            ];
        }, 3);
    }

    public function releaseUsedStorageAfterDeletion(
        MessageAttachment $attachment,
        string $reason = 'retention_deleted',
    ): void {
        if (filled($attachment->local_disk) || filled($attachment->local_path)) {
            throw new LogicException('Used storage may be released only after the stable object is deleted.');
        }

        DB::transaction(function () use ($attachment, $reason): void {
            $storageLedger = $this->lockStorageLedgerOrNull($attachment);

            if (
                ! $storageLedger instanceof MediaDownloadStorageLedger
            ) {
                return;
            }

            if ($storageLedger->status === MediaDownloadStorageLedger::STATUS_RESERVED) {
                $this->releaseStorageReservation($storageLedger, $reason);

                return;
            }

            if ($storageLedger->status !== MediaDownloadStorageLedger::STATUS_USED) {
                return;
            }

            $globalBudget = $this->lockStorageBudget(self::STORAGE_SCOPE_GLOBAL, 0);
            $channelBudget = $this->lockStorageBudget(
                self::STORAGE_SCOPE_CHANNEL,
                (int) $storageLedger->channel_id,
            );
            $usedBytes = (int) $storageLedger->used_bytes;

            $this->updateStorageBudget(
                $globalBudget,
                reservedBytes: (int) $globalBudget->reserved_bytes,
                usedBytes: max(0, (int) $globalBudget->used_bytes - $usedBytes),
            );
            $this->updateStorageBudget(
                $channelBudget,
                reservedBytes: (int) $channelBudget->reserved_bytes,
                usedBytes: max(0, (int) $channelBudget->used_bytes - $usedBytes),
            );
            $storageLedger->forceFill([
                'status' => MediaDownloadStorageLedger::STATUS_RELEASED,
                'release_reason' => $reason,
                'released_at' => now(),
            ])->save();
        }, 3);
    }

    public function reconcileUsedStorage(
        MessageAttachment $attachment,
        int $actualBytes,
    ): string {
        if (! $attachment->exists || $attachment->getKey() === null) {
            throw new LogicException('Media storage reconciliation requires a persisted attachment.');
        }

        $actualBytes = max(0, $actualBytes);

        return DB::transaction(function () use ($attachment, $actualBytes): string {
            /** @var MessageAttachment $lockedAttachment */
            $lockedAttachment = MessageAttachment::query()
                ->whereKey($attachment->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $generation = max(1, (int) $lockedAttachment->media_download_generation);
            $storageLedger = MediaDownloadStorageLedger::query()
                ->where('message_attachment_id', $lockedAttachment->getKey())
                ->where('generation', $generation)
                ->lockForUpdate()
                ->first();

            if (
                $storageLedger instanceof MediaDownloadStorageLedger
                && $storageLedger->status === MediaDownloadStorageLedger::STATUS_USED
                && (int) $storageLedger->used_bytes === $actualBytes
            ) {
                return 'unchanged';
            }

            $globalBudget = $this->lockStorageBudget(self::STORAGE_SCOPE_GLOBAL, 0);
            $channelBudget = $this->lockStorageBudget(
                self::STORAGE_SCOPE_CHANNEL,
                (int) $lockedAttachment->channel_id,
            );
            $previousReservedBytes = $storageLedger instanceof MediaDownloadStorageLedger
                && $storageLedger->status === MediaDownloadStorageLedger::STATUS_RESERVED
                    ? (int) $storageLedger->reserved_bytes
                    : 0;
            $previousUsedBytes = $storageLedger instanceof MediaDownloadStorageLedger
                && $storageLedger->status === MediaDownloadStorageLedger::STATUS_USED
                    ? (int) $storageLedger->used_bytes
                    : 0;

            $this->updateStorageBudget(
                $globalBudget,
                reservedBytes: max(0, (int) $globalBudget->reserved_bytes - $previousReservedBytes),
                usedBytes: max(0, (int) $globalBudget->used_bytes - $previousUsedBytes) + $actualBytes,
            );
            $this->updateStorageBudget(
                $channelBudget,
                reservedBytes: max(0, (int) $channelBudget->reserved_bytes - $previousReservedBytes),
                usedBytes: max(0, (int) $channelBudget->used_bytes - $previousUsedBytes) + $actualBytes,
            );

            $result = $storageLedger instanceof MediaDownloadStorageLedger ? 'corrected' : 'created';
            $storageLedger ??= new MediaDownloadStorageLedger;
            $storageLedger->forceFill([
                'message_attachment_id' => $lockedAttachment->getKey(),
                'channel_id' => $lockedAttachment->channel_id,
                'generation' => $generation,
                'status' => MediaDownloadStorageLedger::STATUS_USED,
                'reserved_bytes' => max((int) $storageLedger->reserved_bytes, $actualBytes),
                'used_bytes' => $actualBytes,
                'release_reason' => null,
                'expires_at' => null,
                'released_at' => null,
            ])->save();

            return $result;
        }, 3);
    }

    private function storageReservationBytes(
        MessageAttachment $attachment,
        ?MediaDownloadStorageLedger $ledger,
    ): int {
        if ($ledger instanceof MediaDownloadStorageLedger) {
            return match ($ledger->status) {
                MediaDownloadStorageLedger::STATUS_RESERVED => 0,
                MediaDownloadStorageLedger::STATUS_USED => 0,
                default => $this->requestedBytes($attachment),
            };
        }

        return $this->requestedBytes($attachment);
    }

    private function trafficReservationBytes(
        MessageAttachment $attachment,
        ?MediaDownloadTrafficLedger $ledger,
        object $budget,
        ?int $unknownSizeReservationBytes = null,
    ): int {
        if ($ledger instanceof MediaDownloadTrafficLedger) {
            return 0;
        }

        $requestedBytes = $this->requestedBytes($attachment, $unknownSizeReservationBytes);
        $dailyLimit = $this->trafficDailyLimitBytes();

        if ($dailyLimit === null || $attachment->file_size_bytes > 0) {
            return $requestedBytes;
        }

        return min(
            $requestedBytes,
            max(0, $dailyLimit - (int) $budget->reserved_bytes - (int) $budget->consumed_bytes),
        );
    }

    private function requestedBytes(
        MessageAttachment $attachment,
        ?int $unknownSizeReservationBytes = null,
    ): int {
        if (is_int($attachment->file_size_bytes) && $attachment->file_size_bytes > 0) {
            return $attachment->file_size_bytes;
        }

        if ($unknownSizeReservationBytes !== null && $unknownSizeReservationBytes > 0) {
            return $unknownSizeReservationBytes;
        }

        if (is_int($attachment->media_download_max_bytes) && $attachment->media_download_max_bytes > 0) {
            return $attachment->media_download_max_bytes;
        }

        return max(1, (int) config('inbound_media.manual_hard_limit_bytes', 4 * 1024 * 1024 * 1024));
    }

    private function assertWithinDownloadLimit(MessageAttachment $attachment, int $bytes): void
    {
        $limit = $attachment->media_download_max_bytes;

        if (is_int($limit) && $limit > 0 && $bytes > $limit) {
            throw new InboundMediaQuotaExceededException(
                $attachment->manual_download_requested_at !== null
                    ? InboundMediaDownloadPolicy::REASON_MANUAL_HARD_LIMIT
                    : InboundMediaDownloadPolicy::REASON_SIZE_ABOVE_AUTO_LIMIT,
                $bytes,
            );
        }
    }

    private function storageDenialReason(int $bytes, object $globalBudget, object $channelBudget): ?string
    {
        if ($bytes <= 0) {
            return null;
        }

        $globalLimit = max(0, (int) config('inbound_media.storage.global_limit_bytes', 0));
        $channelLimit = max(0, (int) config('inbound_media.storage.channel_limit_bytes', 0));

        if (
            (int) $globalBudget->reserved_bytes + (int) $globalBudget->used_bytes + $bytes > $globalLimit
            || (int) $channelBudget->reserved_bytes + (int) $channelBudget->used_bytes + $bytes > $channelLimit
        ) {
            return InboundMediaDownloadPolicy::REASON_STORAGE_QUOTA_EXCEEDED;
        }

        $physicalAvailableBytes = $this->previewPhysicalAvailableBytes();

        if (
            $physicalAvailableBytes === null
            || (int) $globalBudget->reserved_bytes + $bytes > $physicalAvailableBytes
        ) {
            return InboundMediaDownloadPolicy::REASON_STORAGE_QUOTA_EXCEEDED;
        }

        return null;
    }

    private function trafficDenialReason(
        MessageAttachment $attachment,
        int $bytes,
        object $budget,
    ): ?string {
        $dailyLimit = $this->trafficDailyLimitBytes();

        if ($dailyLimit === null) {
            return null;
        }

        $remainingBytes = max(
            0,
            $dailyLimit - (int) $budget->reserved_bytes - (int) $budget->consumed_bytes,
        );

        if ($bytes <= 0 || (is_int($attachment->file_size_bytes) && $attachment->file_size_bytes > $remainingBytes)) {
            return InboundMediaDownloadPolicy::REASON_TRAFFIC_QUOTA_EXCEEDED;
        }

        return null;
    }

    private function storageCompletionDenialReason(
        int $actualBytes,
        MediaDownloadStorageLedger $ledger,
        object $globalBudget,
        object $channelBudget,
        bool $checkPhysicalSpace,
    ): ?string {
        $reservedBytes = (int) $ledger->reserved_bytes;
        $globalLimit = max(0, (int) config('inbound_media.storage.global_limit_bytes', 0));
        $channelLimit = max(0, (int) config('inbound_media.storage.channel_limit_bytes', 0));
        $globalProjectedBytes = max(0, (int) $globalBudget->reserved_bytes - $reservedBytes)
            + (int) $globalBudget->used_bytes
            + $actualBytes;
        $channelProjectedBytes = max(0, (int) $channelBudget->reserved_bytes - $reservedBytes)
            + (int) $channelBudget->used_bytes
            + $actualBytes;

        if ($globalProjectedBytes > $globalLimit || $channelProjectedBytes > $channelLimit) {
            return InboundMediaDownloadPolicy::REASON_STORAGE_QUOTA_EXCEEDED;
        }

        if ($checkPhysicalSpace) {
            $physicalAvailableBytes = $this->storageCapacity->availableBytes();
            $otherReservedBytes = max(
                0,
                (int) $globalBudget->reserved_bytes - (int) $ledger->reserved_bytes,
            );

            if ($physicalAvailableBytes === null || $otherReservedBytes > $physicalAvailableBytes) {
                return InboundMediaDownloadPolicy::REASON_STORAGE_QUOTA_EXCEEDED;
            }
        }

        return null;
    }

    private function trafficCompletionDenialReason(
        int $actualBytes,
        MediaDownloadTrafficLedger $ledger,
        object $budget,
    ): ?string {
        $dailyLimit = $this->trafficDailyLimitBytes();

        if ($dailyLimit === null) {
            return null;
        }

        $projectedBytes = max(0, (int) $budget->reserved_bytes - (int) $ledger->reserved_bytes)
            + (int) $budget->consumed_bytes
            + $actualBytes;

        return $projectedBytes > $dailyLimit
            ? InboundMediaDownloadPolicy::REASON_TRAFFIC_QUOTA_EXCEEDED
            : null;
    }

    private function trafficBudgetWouldExceedLimit(object $budget, int $reservationGrowth): bool
    {
        $dailyLimit = $this->trafficDailyLimitBytes();

        return $dailyLimit !== null
            && (int) $budget->reserved_bytes
                + (int) $budget->consumed_bytes
                + max(0, $reservationGrowth) > $dailyLimit;
    }

    private function reserveStorage(
        MessageAttachment $attachment,
        ?MediaDownloadStorageLedger $ledger,
        object $globalBudget,
        object $channelBudget,
        int $generation,
        int $bytes,
        mixed $expiresAt,
    ): void {
        $this->updateStorageBudget(
            $globalBudget,
            reservedBytes: (int) $globalBudget->reserved_bytes + $bytes,
            usedBytes: (int) $globalBudget->used_bytes,
        );
        $this->updateStorageBudget(
            $channelBudget,
            reservedBytes: (int) $channelBudget->reserved_bytes + $bytes,
            usedBytes: (int) $channelBudget->used_bytes,
        );

        if (! $ledger instanceof MediaDownloadStorageLedger) {
            $ledger = new MediaDownloadStorageLedger;
        }

        $ledger->forceFill([
            'message_attachment_id' => $attachment->getKey(),
            'channel_id' => $attachment->channel_id,
            'generation' => $generation,
            'status' => MediaDownloadStorageLedger::STATUS_RESERVED,
            'reserved_bytes' => $bytes,
            'used_bytes' => 0,
            'release_reason' => null,
            'expires_at' => $expiresAt,
            'released_at' => null,
        ])->save();
    }

    private function releaseStorageReservation(MediaDownloadStorageLedger $ledger, string $reason): void
    {
        $globalBudget = $this->lockStorageBudget(self::STORAGE_SCOPE_GLOBAL, 0);
        $channelBudget = $this->lockStorageBudget(self::STORAGE_SCOPE_CHANNEL, (int) $ledger->channel_id);
        $reservedBytes = (int) $ledger->reserved_bytes;

        $this->updateStorageBudget(
            $globalBudget,
            reservedBytes: max(0, (int) $globalBudget->reserved_bytes - $reservedBytes),
            usedBytes: (int) $globalBudget->used_bytes,
        );
        $this->updateStorageBudget(
            $channelBudget,
            reservedBytes: max(0, (int) $channelBudget->reserved_bytes - $reservedBytes),
            usedBytes: (int) $channelBudget->used_bytes,
        );
        $ledger->forceFill([
            'status' => MediaDownloadStorageLedger::STATUS_RELEASED,
            'release_reason' => $reason,
            'expires_at' => null,
            'released_at' => now(),
        ])->save();
    }

    private function finalizeTrafficReservation(
        MediaDownloadTrafficLedger $ledger,
        int $consumedBytes,
        string $reason,
    ): void {
        $consumedBytes = max($consumedBytes, (int) $ledger->checkpoint_bytes);

        $trafficBudget = $this->lockTrafficBudget(
            (int) $ledger->channel_id,
            $ledger->period_date->toDateString(),
        );
        $reservedBytes = (int) $ledger->reserved_bytes;

        $this->updateTrafficBudget(
            $trafficBudget,
            reservedBytes: max(0, (int) $trafficBudget->reserved_bytes - $reservedBytes),
            consumedBytes: (int) $trafficBudget->consumed_bytes + $consumedBytes,
        );
        $ledger->forceFill([
            'status' => $consumedBytes > 0
                ? MediaDownloadTrafficLedger::STATUS_CONSUMED
                : MediaDownloadTrafficLedger::STATUS_RELEASED,
            'consumed_bytes' => $consumedBytes,
            'checkpoint_bytes' => max((int) $ledger->checkpoint_bytes, $consumedBytes),
            'release_reason' => $reason,
            'expires_at' => null,
            'released_at' => now(),
        ])->save();
    }

    private function lockStorageLedgerOrNull(MessageAttachment $attachment): ?MediaDownloadStorageLedger
    {
        return MediaDownloadStorageLedger::query()
            ->where('message_attachment_id', $attachment->getKey())
            ->where('generation', max(1, (int) $attachment->media_download_generation))
            ->lockForUpdate()
            ->first();
    }

    private function lockTrafficLedgerOrNull(
        MessageAttachment $attachment,
        int $attemptNumber,
    ): ?MediaDownloadTrafficLedger {
        return MediaDownloadTrafficLedger::query()
            ->where('message_attachment_id', $attachment->getKey())
            ->where('generation', max(1, (int) $attachment->media_download_generation))
            ->where('attempt_number', $attemptNumber)
            ->lockForUpdate()
            ->first();
    }

    private function lockStorageBudget(string $scopeType, int $scopeId): object
    {
        $now = now();

        DB::table('media_download_storage_budgets')->insertOrIgnore([
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'reserved_bytes' => 0,
            'used_bytes' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return DB::table('media_download_storage_budgets')
            ->where('scope_type', $scopeType)
            ->where('scope_id', $scopeId)
            ->lockForUpdate()
            ->first() ?? throw new RuntimeException('Media storage budget could not be locked.');
    }

    private function lockTrafficBudget(int $channelId, string $periodDate): object
    {
        $now = now();

        DB::table('media_download_traffic_budgets')->insertOrIgnore([
            'channel_id' => $channelId,
            'period_date' => $periodDate,
            'reserved_bytes' => 0,
            'consumed_bytes' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return DB::table('media_download_traffic_budgets')
            ->where('channel_id', $channelId)
            ->where('period_date', $periodDate)
            ->lockForUpdate()
            ->first() ?? throw new RuntimeException('Media traffic budget could not be locked.');
    }

    private function updateStorageBudget(
        object $budget,
        int $reservedBytes,
        int $usedBytes,
    ): void {
        $this->budgetQuery('media_download_storage_budgets', (int) $budget->id)->update([
            'reserved_bytes' => $reservedBytes,
            'used_bytes' => $usedBytes,
            'updated_at' => now(),
        ]);
        $budget->reserved_bytes = $reservedBytes;
        $budget->used_bytes = $usedBytes;
    }

    private function updateTrafficBudget(
        object $budget,
        int $reservedBytes,
        int $consumedBytes,
    ): void {
        $this->budgetQuery('media_download_traffic_budgets', (int) $budget->id)->update([
            'reserved_bytes' => $reservedBytes,
            'consumed_bytes' => $consumedBytes,
            'updated_at' => now(),
        ]);
        $budget->reserved_bytes = $reservedBytes;
        $budget->consumed_bytes = $consumedBytes;
    }

    private function budgetQuery(string $table, int $id): Builder
    {
        return DB::table($table)->where('id', $id);
    }

    private function trafficDailyLimitBytes(): ?int
    {
        $configured = config('inbound_media.traffic.channel_daily_limit_bytes');

        if ($configured === null || $configured === '' || ! is_numeric($configured)) {
            return null;
        }

        return max(0, (int) $configured);
    }

    private function emptyStorageBudget(): object
    {
        return (object) [
            'reserved_bytes' => 0,
            'used_bytes' => 0,
        ];
    }

    private function emptyTrafficBudget(): object
    {
        return (object) [
            'reserved_bytes' => 0,
            'consumed_bytes' => 0,
        ];
    }

    private function previewStorageBudget(string $scopeType, int $scopeId): object
    {
        $cacheKey = $scopeType.':'.$scopeId;

        if ($this->previewSnapshotDepth > 0 && isset($this->previewStorageBudgets[$cacheKey])) {
            return $this->previewStorageBudgets[$cacheKey];
        }

        $budget = DB::table('media_download_storage_budgets')
            ->where('scope_type', $scopeType)
            ->where('scope_id', $scopeId)
            ->first() ?? $this->emptyStorageBudget();

        if ($this->previewSnapshotDepth > 0) {
            $this->previewStorageBudgets[$cacheKey] = $budget;
        }

        return $budget;
    }

    private function previewTrafficBudget(int $channelId, string $periodDate): object
    {
        $cacheKey = $channelId.':'.$periodDate;

        if ($this->previewSnapshotDepth > 0 && isset($this->previewTrafficBudgets[$cacheKey])) {
            return $this->previewTrafficBudgets[$cacheKey];
        }

        $budget = DB::table('media_download_traffic_budgets')
            ->where('channel_id', $channelId)
            ->where('period_date', $periodDate)
            ->first() ?? $this->emptyTrafficBudget();

        if ($this->previewSnapshotDepth > 0) {
            $this->previewTrafficBudgets[$cacheKey] = $budget;
        }

        return $budget;
    }

    private function previewPhysicalAvailableBytes(): ?int
    {
        if ($this->previewSnapshotDepth <= 0) {
            return $this->storageCapacity->availableBytes();
        }

        if (! $this->previewAvailableBytesResolved) {
            $this->previewAvailableBytes = $this->storageCapacity->availableBytes();
            $this->previewAvailableBytesResolved = true;
        }

        return $this->previewAvailableBytes;
    }

    private function resetPreviewSnapshot(): void
    {
        $this->previewStorageBudgets = [];
        $this->previewTrafficBudgets = [];
        $this->previewAvailableBytesResolved = false;
        $this->previewAvailableBytes = null;
    }
}
