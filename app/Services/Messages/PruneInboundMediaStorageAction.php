<?php

namespace App\Services\Messages;

use App\Models\MediaDownloadStorageLedger;
use App\Models\MessageAttachment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PruneInboundMediaStorageAction
{
    public const REASON_RETENTION_DELETED = 'retention_deleted';

    public function __construct(
        private readonly InboundMediaQuotaLedger $quotaLedger,
    ) {}

    /**
     * @return array{inspected:int,deleted:int,failed:int,skipped:int,disabled:int}
     */
    public function handle(int $limit = 100): array
    {
        $retentionDays = max(0, (int) config('inbound_media.retention_days', 90));
        $stats = [
            'inspected' => 0,
            'deleted' => 0,
            'failed' => 0,
            'skipped' => 0,
            'disabled' => 0,
        ];

        if ($retentionDays === 0) {
            $stats['disabled'] = 1;

            return $stats;
        }

        $limit = min(max($limit, 1), 500);
        $cutoff = now()->subDays($retentionDays);
        $ids = MediaDownloadStorageLedger::query()
            ->where('status', MediaDownloadStorageLedger::STATUS_USED)
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('message_attachment_id');

        foreach ($ids as $id) {
            try {
                $status = $this->pruneOne((int) $id, $cutoff);
            } catch (Throwable $exception) {
                report($exception);
                $status = 'failed';
            }

            $stats['inspected']++;
            $stats[$status]++;
        }

        return $stats;
    }

    private function pruneOne(int $attachmentId, mixed $cutoff): string
    {
        $reference = DB::transaction(function () use ($attachmentId, $cutoff): ?array {
            $attachment = MessageAttachment::query()
                ->whereKey($attachmentId)
                ->lockForUpdate()
                ->first();

            if (! $attachment instanceof MessageAttachment) {
                return null;
            }

            $storageLedger = MediaDownloadStorageLedger::query()
                ->where('message_attachment_id', $attachment->getKey())
                ->where('generation', max(1, (int) $attachment->media_download_generation))
                ->where('status', MediaDownloadStorageLedger::STATUS_USED)
                ->where('updated_at', '<=', $cutoff)
                ->lockForUpdate()
                ->first();

            if (
                ! $storageLedger instanceof MediaDownloadStorageLedger
                || $attachment->download_status !== MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED
                || ! $this->hasSafeStableReference($attachment)
            ) {
                return null;
            }

            return [
                'disk' => (string) $attachment->local_disk,
                'path' => (string) $attachment->local_path,
                'generation' => max(1, (int) $attachment->media_download_generation),
            ];
        }, 3);

        if (! is_array($reference)) {
            return 'skipped';
        }

        $storage = Storage::disk($reference['disk']);

        if ($storage->exists($reference['path'])) {
            $storage->delete($reference['path']);
        }

        if ($storage->exists($reference['path'])) {
            return 'failed';
        }

        return DB::transaction(function () use ($attachmentId, $cutoff, $reference): string {
            $attachment = MessageAttachment::query()
                ->whereKey($attachmentId)
                ->lockForUpdate()
                ->first();

            if (
                ! $attachment instanceof MessageAttachment
                || $attachment->download_status !== MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED
                || $attachment->local_disk !== $reference['disk']
                || $attachment->local_path !== $reference['path']
                || max(1, (int) $attachment->media_download_generation) !== $reference['generation']
            ) {
                return 'skipped';
            }

            $storageLedger = MediaDownloadStorageLedger::query()
                ->where('message_attachment_id', $attachment->getKey())
                ->where('generation', $reference['generation'])
                ->where('status', MediaDownloadStorageLedger::STATUS_USED)
                ->where('updated_at', '<=', $cutoff)
                ->lockForUpdate()
                ->first();

            if (! $storageLedger instanceof MediaDownloadStorageLedger) {
                return 'skipped';
            }

            $attachment->forceFill([
                'download_status' => MessageAttachment::DOWNLOAD_STATUS_DELETED_LOCAL,
                'manual_download_requested_at' => null,
                'manual_download_requested_by_user_id' => null,
                'media_download_claim_token' => null,
                'media_download_upload_size_bytes' => null,
                'media_download_next_retry_at' => null,
                'media_download_claimed_at' => null,
                'media_download_heartbeat_at' => null,
                'media_download_attempt_deadline_at' => null,
                'local_disk' => null,
                'local_path' => null,
                'safe_error_code' => self::REASON_RETENTION_DELETED,
                'safe_error_message' => 'Локальная копия удалена по сроку хранения.',
            ])->save();

            $this->quotaLedger->releaseUsedStorageAfterDeletion(
                $attachment,
                self::REASON_RETENTION_DELETED,
            );

            return 'deleted';
        }, 3);
    }

    private function hasSafeStableReference(MessageAttachment $attachment): bool
    {
        return is_string($attachment->local_disk)
            && in_array($attachment->local_disk, MessageAttachment::readableStorageDiskNames(), true)
            && MessageAttachment::isSafeLocalPath($attachment->local_path);
    }
}
