<?php

namespace App\Services\TelegramAccount;

use App\Models\Channel;
use App\Models\MessageAttachment;
use App\Services\Dialogs\DialogAutomationGate;
use App\Services\Dialogs\DialogStageCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ClaimTelegramAccountMediaDownloadAction
{
    public const ERROR_FILE_TOO_LARGE = 'file_too_large';

    public const ERROR_MISSING_PROVIDER_FILE_ID = 'missing_provider_file_id';

    public const ERROR_UNSUPPORTED_MEDIA_KIND = 'unsupported_media_kind';

    private const PROCESSING_TIMEOUT_MINUTES = 10;

    public function __construct(
        private readonly DialogStageCatalog $dialogStageCatalog,
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

    public static function maxBytes(): int
    {
        return max(1, (int) config('bots.telegram_account.media_download_max_bytes', 20 * 1024 * 1024));
    }

    public function handle(Channel $channel): ?MessageAttachment
    {
        return DB::transaction(function () use ($channel): ?MessageAttachment {
            $this->releaseStaleDownloads($channel);
            $this->markBlacklistedDownloadsMetadataOnly($channel);
            $this->failNonClaimableDownloads($channel);

            $attachment = MessageAttachment::query()
                ->where('channel_id', $channel->id)
                ->where('provider', MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT)
                ->where('download_status', MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD)
                ->whereIn('media_kind', self::supportedMediaKinds())
                ->whereNotNull('provider_file_id')
                ->where('provider_file_id', '!=', '')
                ->whereHas('message.dialog', function (Builder $query): void {
                    $this->dialogStageCatalog->applyNotBlacklistStageFilter($query);
                })
                ->where(function ($query): void {
                    $query
                        ->whereNull('file_size_bytes')
                        ->orWhere('file_size_bytes', '<=', self::maxBytes());
                })
                ->orderByRaw('CASE WHEN safe_error_code IS NULL THEN 0 ELSE 1 END')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (! $attachment instanceof MessageAttachment) {
                return null;
            }

            $attachment->forceFill([
                'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING,
                'safe_error_code' => null,
                'safe_error_message' => null,
            ])->save();

            return $attachment->fresh(['message']);
        });
    }

    private function releaseStaleDownloads(Channel $channel): void
    {
        MessageAttachment::query()
            ->where('channel_id', $channel->id)
            ->where('provider', MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT)
            ->where('download_status', MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING)
            ->where('updated_at', '<=', now()->subMinutes(self::PROCESSING_TIMEOUT_MINUTES))
            ->lockForUpdate()
            ->update([
                'download_status' => MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD,
                'safe_error_code' => 'download_claim_timeout',
                'safe_error_message' => 'Gateway did not report media download result before processing timeout.',
                'updated_at' => now(),
            ]);
    }

    private function markBlacklistedDownloadsMetadataOnly(Channel $channel): void
    {
        MessageAttachment::query()
            ->where('channel_id', $channel->id)
            ->where('provider', MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT)
            ->where('download_status', MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD)
            ->whereHas('message.dialog', function (Builder $query): void {
                $this->dialogStageCatalog->applyBlacklistStageFilter($query);
            })
            ->lockForUpdate()
            ->update([
                'download_status' => MessageAttachment::DOWNLOAD_STATUS_METADATA_ONLY,
                'safe_error_code' => DialogAutomationGate::REASON_BLACKLIST_STAGE,
                'safe_error_message' => 'Media download skipped because the dialog stage is blacklisted.',
                'updated_at' => now(),
            ]);
    }

    private function failNonClaimableDownloads(Channel $channel): void
    {
        MessageAttachment::query()
            ->where('channel_id', $channel->id)
            ->where('provider', MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT)
            ->where('download_status', MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD)
            ->whereNotIn('media_kind', self::supportedMediaKinds())
            ->lockForUpdate()
            ->update([
                'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED,
                'safe_error_code' => self::ERROR_UNSUPPORTED_MEDIA_KIND,
                'safe_error_message' => 'Media kind is not supported by Telegram Account media download.',
                'updated_at' => now(),
            ]);

        MessageAttachment::query()
            ->where('channel_id', $channel->id)
            ->where('provider', MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT)
            ->where('download_status', MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD)
            ->where(function ($query): void {
                $query
                    ->whereNull('provider_file_id')
                    ->orWhere('provider_file_id', '');
            })
            ->lockForUpdate()
            ->update([
                'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED,
                'safe_error_code' => self::ERROR_MISSING_PROVIDER_FILE_ID,
                'safe_error_message' => 'Telegram Account media download requires provider_file_id.',
                'updated_at' => now(),
            ]);

        MessageAttachment::query()
            ->where('channel_id', $channel->id)
            ->where('provider', MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT)
            ->where('download_status', MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD)
            ->where('file_size_bytes', '>', self::maxBytes())
            ->lockForUpdate()
            ->update([
                'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED,
                'safe_error_code' => self::ERROR_FILE_TOO_LARGE,
                'safe_error_message' => 'Telegram Account media file is larger than the local download limit.',
                'updated_at' => now(),
            ]);
    }
}
