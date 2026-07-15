<?php

namespace App\Console\Commands;

use App\Models\MessageAttachment;
use App\Services\Messages\InboundMediaQuotaLedger;
use App\Services\Messages\ReconcileInboundMediaStorageAction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class AuditMessageAttachmentStorageCommand extends Command
{
    protected $signature = 'message-attachments:audit-storage
        {--repair : Mark missing local files as deleted and release their storage quota}
        {--limit=500 : Maximum downloaded attachments to inspect}
        {--attachment=* : Limit audit to one or more attachment IDs}';

    protected $description = 'Audit downloaded message attachments and optionally repair stale database pointers.';

    public function __construct(
        private readonly InboundMediaQuotaLedger $quotaLedger,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $repair = (bool) $this->option('repair');
        $limit = min(max((int) $this->option('limit'), 1), 5000);
        $attachmentIds = $this->attachmentIds();

        $query = MessageAttachment::query()
            ->where('download_status', MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED)
            ->whereNotNull('local_disk')
            ->whereNotNull('local_path')
            ->when($attachmentIds !== [], fn ($builder) => $builder->whereIn('id', $attachmentIds))
            ->orderBy('id');

        $matchingCount = (clone $query)->count();
        $attachments = (clone $query)
            ->limit($limit)
            ->get([
                'id',
                'message_id',
                'channel_id',
                'provider',
                'media_kind',
                'download_status',
                'local_disk',
                'local_path',
            ]);

        try {
            $missing = $attachments
                ->filter(fn (MessageAttachment $attachment): bool => ! $this->storedFileExists($attachment))
                ->values();
        } catch (Throwable $exception) {
            $this->error('Message attachment storage audit aborted: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->line($repair
            ? 'Message attachment storage repair started.'
            : 'Message attachment storage audit dry-run.');

        $this->table(
            ['Metric', 'Count'],
            [
                ['matching_downloaded_attachments', (string) $matchingCount],
                ['inspected_attachments', (string) $attachments->count()],
                ['missing_files', (string) $missing->count()],
            ],
        );

        if ($missing->isEmpty()) {
            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Message', 'Provider', 'Kind', 'Repair target'],
            $missing
                ->map(fn (MessageAttachment $attachment): array => [
                    (string) $attachment->id,
                    (string) $attachment->message_id,
                    (string) $attachment->provider,
                    (string) $attachment->media_kind,
                    MessageAttachment::DOWNLOAD_STATUS_DELETED_LOCAL,
                ])
                ->all(),
        );

        if (! $repair) {
            return self::SUCCESS;
        }

        try {
            $repaired = $missing
                ->map(fn (MessageAttachment $attachment): ?string => $this->repairMissingAttachment((int) $attachment->id))
                ->filter()
                ->count();
        } catch (Throwable $exception) {
            $this->error('Message attachment storage repair aborted: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['Result', 'Count'],
            [
                ['repaired_attachments', (string) $repaired],
                ['unchanged_after_recheck', (string) max(0, $missing->count() - $repaired)],
            ],
        );

        return self::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function attachmentIds(): array
    {
        return collect((array) $this->option('attachment'))
            ->filter(fn ($id): bool => is_numeric($id))
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    private function storedFileExists(MessageAttachment $attachment): bool
    {
        $disk = is_string($attachment->local_disk) ? trim($attachment->local_disk) : '';
        $path = is_string($attachment->local_path) ? trim($attachment->local_path) : '';

        if (
            $disk === ''
            || ! in_array($disk, MessageAttachment::readableStorageDiskNames(), true)
            || ! MessageAttachment::isSafeLocalPath($path)
        ) {
            return false;
        }

        try {
            return Storage::disk($disk)->exists($path);
        } catch (Throwable $exception) {
            throw new \RuntimeException(sprintf(
                'Could not inspect attachment [%d] on disk [%s]: %s',
                $attachment->id,
                $disk,
                $exception->getMessage(),
            ), previous: $exception);
        }
    }

    private function repairMissingAttachment(int $attachmentId): ?string
    {
        return DB::transaction(function () use ($attachmentId): ?string {
            $attachment = MessageAttachment::query()
                ->whereKey($attachmentId)
                ->lockForUpdate()
                ->first();

            if (
                ! $attachment instanceof MessageAttachment
                || $attachment->download_status !== MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED
                || $this->storedFileExists($attachment)
            ) {
                return null;
            }

            $attachment->forceFill([
                'download_status' => MessageAttachment::DOWNLOAD_STATUS_DELETED_LOCAL,
                'local_disk' => null,
                'local_path' => null,
                'safe_error_code' => ReconcileInboundMediaStorageAction::REASON_LOCAL_FILE_MISSING,
                'safe_error_message' => 'Локальная копия файла отсутствует.',
            ])->save();

            $this->quotaLedger->releaseUsedStorageAfterDeletion(
                $attachment,
                ReconcileInboundMediaStorageAction::REASON_LOCAL_FILE_MISSING,
            );

            return MessageAttachment::DOWNLOAD_STATUS_DELETED_LOCAL;
        });
    }
}
