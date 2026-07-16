<?php

namespace App\Services\TelegramAccount;

use App\Models\Channel;
use App\Models\MessageAttachment;
use App\Services\Messages\InboundMediaDownloadPolicy;

class TelegramAccountMediaDownloadPolicy
{
    public const DEFAULT_AUTO_DOWNLOAD_MAX_BYTES = 20 * 1024 * 1024;

    public const ERROR_AUTO_DOWNLOAD_LIMIT_EXCEEDED = 'auto_download_limit_exceeded';

    public const ERROR_TELEGRAM_FILE_NOT_FOUND = 'telegram_file_not_found';

    public const ERROR_TDLIB_FILE_NOT_FOUND = 'tdlib_file_not_found';

    public function __construct(
        private readonly InboundMediaDownloadPolicy $inboundMediaDownloadPolicy,
    ) {}

    public function automaticMaxBytes(Channel $channel): int
    {
        return $this->inboundMediaDownloadPolicy->automaticMaxBytes($channel);
    }

    public function automaticRequestMaxBytes(Channel $channel): int
    {
        return $this->inboundMediaDownloadPolicy->automaticRequestMaxBytes(
            $channel,
            MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT,
        );
    }

    public function initialDownloadStatus(Channel $channel, ?int $fileSizeBytes): string
    {
        if ($fileSizeBytes === null || $fileSizeBytes <= 0 || $fileSizeBytes > $this->automaticRequestMaxBytes($channel)) {
            return MessageAttachment::DOWNLOAD_STATUS_AVAILABLE_ON_DEMAND;
        }

        return MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD;
    }

    public function exceedsAutomaticLimit(Channel $channel, ?int $fileSizeBytes): bool
    {
        return $fileSizeBytes === null || $fileSizeBytes <= 0 || $fileSizeBytes > $this->automaticRequestMaxBytes($channel);
    }

    public function onDemandEnabled(Channel $channel): bool
    {
        return $this->inboundMediaDownloadPolicy->onDemandEnabled($channel);
    }

    public function canRequestManually(MessageAttachment $attachment): bool
    {
        return $attachment->provider === MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT
            && $this->inboundMediaDownloadPolicy->manualAvailability($attachment)['allowed'];
    }

    public function manualRequestMaxBytes(MessageAttachment $attachment): int
    {
        return $this->inboundMediaDownloadPolicy->manualRequestMaxBytes($attachment);
    }
}
