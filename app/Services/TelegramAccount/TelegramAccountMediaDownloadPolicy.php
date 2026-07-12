<?php

namespace App\Services\TelegramAccount;

use App\Models\Channel;
use App\Models\MessageAttachment;

class TelegramAccountMediaDownloadPolicy
{
    public const DEFAULT_AUTO_DOWNLOAD_MAX_BYTES = 20 * 1024 * 1024;

    public const ERROR_AUTO_DOWNLOAD_LIMIT_EXCEEDED = 'auto_download_limit_exceeded';

    public const ERROR_TELEGRAM_FILE_NOT_FOUND = 'telegram_file_not_found';

    public const ERROR_TDLIB_FILE_NOT_FOUND = 'tdlib_file_not_found';

    public function automaticMaxBytes(Channel $channel): int
    {
        $channelValue = $channel->telegram_account_media_auto_download_max_bytes;

        if (is_int($channelValue) && $channelValue >= 0) {
            return $channelValue;
        }

        return max(0, (int) config(
            'bots.telegram_account.media_download_max_bytes',
            self::DEFAULT_AUTO_DOWNLOAD_MAX_BYTES,
        ));
    }

    public function initialDownloadStatus(Channel $channel, ?int $fileSizeBytes): string
    {
        if ($fileSizeBytes === null || $fileSizeBytes > $this->automaticMaxBytes($channel)) {
            return MessageAttachment::DOWNLOAD_STATUS_AVAILABLE_ON_DEMAND;
        }

        return MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD;
    }

    public function exceedsAutomaticLimit(Channel $channel, ?int $fileSizeBytes): bool
    {
        return $fileSizeBytes === null || $fileSizeBytes > $this->automaticMaxBytes($channel);
    }

    public function canRequestManually(MessageAttachment $attachment): bool
    {
        if (
            $attachment->provider !== MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT
            || (! filled($attachment->provider_file_id) && ! filled($attachment->provider_file_reference))
        ) {
            return false;
        }

        if (! in_array($attachment->download_status, [
            MessageAttachment::DOWNLOAD_STATUS_AVAILABLE_ON_DEMAND,
            MessageAttachment::DOWNLOAD_STATUS_METADATA_ONLY,
            MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED,
            MessageAttachment::DOWNLOAD_STATUS_DELETED_LOCAL,
        ], true)) {
            return false;
        }

        return ! in_array($attachment->safe_error_code, [
            ClaimTelegramAccountMediaDownloadAction::ERROR_MISSING_PROVIDER_FILE_ID,
            ClaimTelegramAccountMediaDownloadAction::ERROR_UNSUPPORTED_MEDIA_KIND,
            self::ERROR_TELEGRAM_FILE_NOT_FOUND,
            self::ERROR_TDLIB_FILE_NOT_FOUND,
        ], true);
    }
}
