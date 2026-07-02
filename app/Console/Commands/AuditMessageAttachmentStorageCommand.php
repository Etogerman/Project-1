<?php

namespace App\Console\Commands;

use App\Models\MessageAttachment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class AuditMessageAttachmentStorageCommand extends Command
{
    protected $signature = 'message-attachments:audit-storage
        {--repair : Reset missing downloaded attachments to a retryable state}
        {--limit=500 : Maximum downloaded attachments to inspect}
        {--attachment=* : Limit audit to one or more attachment IDs}';

    protected $description = 'Audit downloaded message attachments and optionally repair stale database pointers.';

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
                'provider_file_id',
                'provider_file_reference',
                'provider_attachment_key',
            ]);

        $missing = $attachments
            ->filter(fn (MessageAttachment $attachment): bool => ! $this->storedFileExists($attachment))
            ->values();

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
            ['ID', 'Message', 'Provider', 'Kind', 'Disk', 'Path', 'Repair target'],
            $missing
                ->map(fn (MessageAttachment $attachment): array => [
                    (string) $attachment->id,
                    (string) $attachment->message_id,
                    (string) $attachment->provider,
                    (string) $attachment->media_kind,
                    (string) $attachment->local_disk,
                    (string) $attachment->local_path,
                    $this->canRetryDownload($attachment)
                        ? MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD
                        : MessageAttachment::DOWNLOAD_STATUS_METADATA_ONLY,
                ])
                ->all(),
        );

        if (! $repair) {
            return self::SUCCESS;
        }

        $repaired = $missing
            ->map(fn (MessageAttachment $attachment): ?string => $this->repairMissingAttachment((int) $attachment->id))
            ->filter()
            ->count();

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
        } catch (Throwable) {
            return false;
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

            $targetStatus = $this->canRetryDownload($attachment)
                ? MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD
                : MessageAttachment::DOWNLOAD_STATUS_METADATA_ONLY;

            $attachment->forceFill([
                'download_status' => $targetStatus,
                'local_disk' => null,
                'local_path' => null,
                'safe_error_code' => 'local_file_missing',
                'safe_error_message' => $targetStatus === MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD
                    ? 'Stored media file is missing; attachment was queued for re-download.'
                    : 'Stored media file is missing and there is not enough provider data for automatic re-download.',
            ])->save();

            return $targetStatus;
        });
    }

    private function canRetryDownload(MessageAttachment $attachment): bool
    {
        return match ($attachment->provider) {
            MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT => $this->isSupportedTelegramAccountAttachment($attachment)
                && filled($attachment->provider_file_id),
            MessageAttachment::PROVIDER_TELEGRAM_BOT => $this->isSupportedTelegramBotAttachment($attachment)
                && filled($attachment->provider_file_id),
            MessageAttachment::PROVIDER_MAX_BOT => $this->isSupportedMaxBotAttachment($attachment)
                && (filled($attachment->provider_file_reference) || filled($attachment->provider_attachment_key)),
            default => false,
        };
    }

    private function isSupportedTelegramAccountAttachment(MessageAttachment $attachment): bool
    {
        return in_array($attachment->media_kind, [
            MessageAttachment::MEDIA_KIND_IMAGE,
            MessageAttachment::MEDIA_KIND_DOCUMENT,
            MessageAttachment::MEDIA_KIND_VIDEO,
            MessageAttachment::MEDIA_KIND_VIDEO_NOTE,
            MessageAttachment::MEDIA_KIND_AUDIO,
            MessageAttachment::MEDIA_KIND_VOICE,
            MessageAttachment::MEDIA_KIND_STICKER,
        ], true);
    }

    private function isSupportedTelegramBotAttachment(MessageAttachment $attachment): bool
    {
        return in_array($attachment->media_kind, [
            MessageAttachment::MEDIA_KIND_IMAGE,
            MessageAttachment::MEDIA_KIND_DOCUMENT,
            MessageAttachment::MEDIA_KIND_VIDEO,
            MessageAttachment::MEDIA_KIND_VIDEO_NOTE,
            MessageAttachment::MEDIA_KIND_AUDIO,
            MessageAttachment::MEDIA_KIND_VOICE,
            MessageAttachment::MEDIA_KIND_STICKER,
        ], true);
    }

    private function isSupportedMaxBotAttachment(MessageAttachment $attachment): bool
    {
        return in_array($attachment->media_kind, [
            MessageAttachment::MEDIA_KIND_IMAGE,
            MessageAttachment::MEDIA_KIND_DOCUMENT,
            MessageAttachment::MEDIA_KIND_VIDEO,
            MessageAttachment::MEDIA_KIND_VIDEO_NOTE,
            MessageAttachment::MEDIA_KIND_AUDIO,
            MessageAttachment::MEDIA_KIND_STICKER,
        ], true);
    }
}
