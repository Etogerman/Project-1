<?php

namespace App\Services\Messages;

use App\Jobs\CleanupInboundMediaPartialFilesJob;
use App\Models\MediaDownloadStorageLedger;
use App\Models\MediaDownloadTrafficLedger;
use App\Models\MessageAttachment;
use Illuminate\Support\Facades\DB;

class ReapExpiredInboundMediaReservationsAction
{
    public const ERROR_RESERVATION_EXPIRED = 'reservation_expired';

    public function __construct(
        private readonly DeleteInboundMediaPartialFilesAction $deletePartialFiles,
        private readonly InboundMediaQuotaLedger $quotaLedger,
    ) {}

    /**
     * @return array{inspected:int,released:int,cleanup_failed:int,skipped:int,storage_released:int,traffic_released:int,traffic_consumed_bytes:int}
     */
    public function handle(int $limit = 100): array
    {
        $limit = min(max($limit, 1), 500);
        $targets = MediaDownloadStorageLedger::query()
            ->where('status', MediaDownloadStorageLedger::STATUS_RESERVED)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->limit($limit)
            ->get(['message_attachment_id', 'generation'])
            ->map(static fn (MediaDownloadStorageLedger $ledger): array => [
                'attachment_id' => (int) $ledger->message_attachment_id,
                'generation' => max(1, (int) $ledger->generation),
            ])
            ->merge(
                MediaDownloadTrafficLedger::query()
                    ->where('status', MediaDownloadTrafficLedger::STATUS_RESERVED)
                    ->whereNotNull('expires_at')
                    ->where('expires_at', '<=', now())
                    ->orderBy('id')
                    ->limit($limit)
                    ->get(['message_attachment_id', 'generation'])
                    ->map(static fn (MediaDownloadTrafficLedger $ledger): array => [
                        'attachment_id' => (int) $ledger->message_attachment_id,
                        'generation' => max(1, (int) $ledger->generation),
                    ]),
            )
            ->unique(static fn (array $target): string => $target['attachment_id'].':'.$target['generation'])
            ->take($limit)
            ->values();
        $stats = [
            'inspected' => 0,
            'released' => 0,
            'cleanup_failed' => 0,
            'skipped' => 0,
            'storage_released' => 0,
            'traffic_released' => 0,
            'traffic_consumed_bytes' => 0,
        ];

        foreach ($targets as $target) {
            $attachmentId = $target['attachment_id'];
            $generation = $target['generation'];
            $result = $this->reapOne($attachmentId, $generation);
            $stats['inspected']++;
            $stats[$result['status']]++;
            $stats['storage_released'] += $result['storage_released'];
            $stats['traffic_released'] += $result['traffic_released'];
            $stats['traffic_consumed_bytes'] += $result['traffic_consumed_bytes'];

            if ($result['status'] === 'cleanup_failed') {
                CleanupInboundMediaPartialFilesJob::dispatch(
                    $attachmentId,
                    $generation,
                )->afterCommit();
            }
        }

        return $stats;
    }

    /**
     * @return array{status:'released'|'cleanup_failed'|'skipped',storage_released:int,traffic_released:int,traffic_consumed_bytes:int}
     */
    private function reapOne(int $attachmentId, int $generation): array
    {
        return DB::transaction(function () use ($attachmentId, $generation): array {
            $attachment = MessageAttachment::query()
                ->whereKey($attachmentId)
                ->lockForUpdate()
                ->first();

            if (! $attachment instanceof MessageAttachment) {
                return $this->result('skipped');
            }

            $isCurrentGeneration = max(1, (int) $attachment->media_download_generation) === $generation;

            if (
                $isCurrentGeneration
                && (
                    $attachment->download_status === MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING
                    || filled($attachment->local_disk)
                    || filled($attachment->local_path)
                )
            ) {
                return $this->result('skipped');
            }

            if (! $this->deletePartialFiles->handle($attachment, null, $generation)) {
                return $this->result('cleanup_failed');
            }

            $released = $this->quotaLedger->releaseExpiredReservations(
                $attachment,
                self::ERROR_RESERVATION_EXPIRED,
                $generation,
            );

            if ($released['storage_released'] === 0 && $released['traffic_released'] === 0) {
                return $this->result('skipped');
            }

            return [
                'status' => 'released',
                ...$released,
            ];
        }, 3);
    }

    /**
     * @return array{status:'released'|'cleanup_failed'|'skipped',storage_released:int,traffic_released:int,traffic_consumed_bytes:int}
     */
    private function result(string $status): array
    {
        return [
            'status' => $status,
            'storage_released' => 0,
            'traffic_released' => 0,
            'traffic_consumed_bytes' => 0,
        ];
    }
}
