<?php

namespace App\Services\Messages;

use App\Models\Channel;
use App\Models\MessageAttachment;
use App\Services\Dialogs\DialogAutomationGate;

class InboundMediaDownloadPolicy
{
    public const DEFAULT_AUTO_DOWNLOAD_MAX_BYTES = 20 * 1024 * 1024;

    public const MANUAL_HARD_LIMIT_BYTES = 4 * 1024 * 1024 * 1024;

    public const REASON_BLACKLIST_STAGE = DialogAutomationGate::REASON_BLACKLIST_STAGE;

    public const REASON_SIZE_ABOVE_AUTO_LIMIT = 'size_above_auto_limit';

    public const REASON_SIZE_UNKNOWN = 'size_unknown';

    public const REASON_TRANSPORT_UNAVAILABLE = 'transport_unavailable';

    public const REASON_MANUAL_DISABLED = 'manual_download_disabled';

    public const REASON_MANUAL_HARD_LIMIT = 'manual_hard_limit_exceeded';

    public const REASON_STORAGE_QUOTA_EXCEEDED = 'storage_quota_exceeded';

    public const REASON_TRAFFIC_QUOTA_EXCEEDED = 'traffic_quota_exceeded';

    public const REASON_SOURCE_UNAVAILABLE = 'source_unavailable';

    public const REASON_UNSUPPORTED_MEDIA_KIND = 'unsupported_media_kind';

    public function __construct(
        private readonly InboundMediaQuotaLedger $quotaLedger,
    ) {}

    public function withManualAvailabilitySnapshot(callable $callback): mixed
    {
        return $this->quotaLedger->withPreviewSnapshot($callback);
    }

    /**
     * @return array{status:string, reason:?string, message:?string}
     */
    public function initialDecision(
        Channel $channel,
        string $provider,
        string $mediaKind,
        ?int $fileSizeBytes,
        bool $automaticDownloadAllowed = true,
    ): array {
        if (! $this->supports($provider, $mediaKind)) {
            return $this->decision(
                MessageAttachment::DOWNLOAD_STATUS_METADATA_ONLY,
                self::REASON_UNSUPPORTED_MEDIA_KIND,
                'Этот формат медиа пока не поддерживается.',
            );
        }

        if (! $automaticDownloadAllowed) {
            return $this->decision(
                MessageAttachment::DOWNLOAD_STATUS_METADATA_ONLY,
                self::REASON_BLACKLIST_STAGE,
                'Автоматическая загрузка отключена для диалога в ЧС.',
            );
        }

        if ($fileSizeBytes === null || $fileSizeBytes <= 0) {
            return $this->decision(
                MessageAttachment::DOWNLOAD_STATUS_AVAILABLE_ON_DEMAND,
                self::REASON_SIZE_UNKNOWN,
                null,
            );
        }

        if ($fileSizeBytes > $this->automaticRequestMaxBytes($channel, $provider)) {
            return $this->decision(
                MessageAttachment::DOWNLOAD_STATUS_AVAILABLE_ON_DEMAND,
                self::REASON_SIZE_ABOVE_AUTO_LIMIT,
                null,
            );
        }

        return $this->decision(MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD, null, null);
    }

    public function automaticMaxBytes(Channel $channel): int
    {
        $channelValue = $channel->inbound_media_auto_download_max_bytes;

        if (is_int($channelValue) && $channelValue >= 0) {
            return $channelValue;
        }

        $legacyValue = $channel->telegram_account_media_auto_download_max_bytes;

        if (is_int($legacyValue) && $legacyValue >= 0) {
            return $legacyValue;
        }

        if ($channel->isAccountConnection()) {
            return max(0, (int) config(
                'bots.telegram_account.media_download_max_bytes',
                self::DEFAULT_AUTO_DOWNLOAD_MAX_BYTES,
            ));
        }

        return max(0, (int) config('bots.media.download_max_bytes', self::DEFAULT_AUTO_DOWNLOAD_MAX_BYTES));
    }

    public function automaticRequestMaxBytes(Channel $channel, string $provider): int
    {
        return min(
            $this->automaticMaxBytes($channel),
            $this->automaticTransportMaxBytes($provider),
        );
    }

    public function onDemandEnabled(Channel $channel): bool
    {
        if ($channel->inbound_media_on_demand_enabled !== null) {
            return (bool) $channel->inbound_media_on_demand_enabled;
        }

        if ($channel->telegram_account_media_on_demand_enabled !== null) {
            return (bool) $channel->telegram_account_media_on_demand_enabled;
        }

        return false;
    }

    /**
     * @return array{visible:bool, allowed:bool, reason:?string}
     */
    public function manualAvailability(MessageAttachment $attachment): array
    {
        $attachment->loadMissing('channel');
        $channel = $attachment->channel;

        if (
            ! $channel instanceof Channel
            || ! $this->supports((string) $attachment->provider, (string) $attachment->media_kind)
            || ! $this->hasProviderReference($attachment)
            || ! $this->hasManualState($attachment)
            || $this->isTerminallyUnavailable($attachment)
        ) {
            return $this->manualDecision(false, false, null);
        }

        if (! $this->onDemandEnabled($channel)) {
            return $this->manualDecision(
                true,
                false,
                'Ручная загрузка медиа отключена в настройках канала.',
            );
        }

        $fileSizeBytes = $attachment->file_size_bytes;

        if (is_int($fileSizeBytes) && $fileSizeBytes > $this->manualHardLimitBytes()) {
            return $this->manualDecision(false, false, null);
        }

        $transportLimit = $this->manualTransportMaxBytes((string) $attachment->provider);

        if (is_int($fileSizeBytes) && $fileSizeBytes > $transportLimit) {
            return $this->manualDecision(
                true,
                false,
                'Транспорт этого канала пока не поддерживает ручную загрузку файла такого размера.',
            );
        }

        $quotaDecision = $this->quotaLedger->previewForAttempt(
            $attachment,
            $this->manualRequestMaxBytes($attachment),
        );

        if (! $quotaDecision->allowed) {
            return $this->manualDecision(
                true,
                false,
                $this->quotaReasonMessage($quotaDecision->reason),
            );
        }

        return $this->manualDecision(true, true, null);
    }

    public function manualRequestMaxBytes(MessageAttachment $attachment): int
    {
        return min(
            $this->manualHardLimitBytes(),
            $this->manualTransportMaxBytes((string) $attachment->provider),
        );
    }

    public function manualHardLimitBytes(): int
    {
        return max(1, (int) config(
            'inbound_media.manual_hard_limit_bytes',
            self::MANUAL_HARD_LIMIT_BYTES,
        ));
    }

    public function supports(string $provider, string $mediaKind): bool
    {
        return in_array($mediaKind, $this->supportedMediaKinds($provider), true);
    }

    /**
     * @return list<string>
     */
    public function supportedMediaKinds(string $provider): array
    {
        return match ($provider) {
            MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT => [
                MessageAttachment::MEDIA_KIND_IMAGE,
                MessageAttachment::MEDIA_KIND_DOCUMENT,
                MessageAttachment::MEDIA_KIND_VIDEO,
                MessageAttachment::MEDIA_KIND_VIDEO_NOTE,
                MessageAttachment::MEDIA_KIND_AUDIO,
                MessageAttachment::MEDIA_KIND_VOICE,
                MessageAttachment::MEDIA_KIND_STICKER,
                MessageAttachment::MEDIA_KIND_ANIMATION,
            ],
            MessageAttachment::PROVIDER_TELEGRAM_BOT => [
                MessageAttachment::MEDIA_KIND_IMAGE,
                MessageAttachment::MEDIA_KIND_DOCUMENT,
                MessageAttachment::MEDIA_KIND_VIDEO,
                MessageAttachment::MEDIA_KIND_VIDEO_NOTE,
                MessageAttachment::MEDIA_KIND_AUDIO,
                MessageAttachment::MEDIA_KIND_VOICE,
                MessageAttachment::MEDIA_KIND_STICKER,
                MessageAttachment::MEDIA_KIND_ANIMATION,
            ],
            MessageAttachment::PROVIDER_MAX_BOT => [
                MessageAttachment::MEDIA_KIND_IMAGE,
                MessageAttachment::MEDIA_KIND_DOCUMENT,
                MessageAttachment::MEDIA_KIND_VIDEO,
                MessageAttachment::MEDIA_KIND_VIDEO_NOTE,
                MessageAttachment::MEDIA_KIND_AUDIO,
                MessageAttachment::MEDIA_KIND_STICKER,
            ],
            default => [],
        };
    }

    private function manualTransportMaxBytes(string $provider): int
    {
        if ($provider === MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT) {
            return $this->manualHardLimitBytes();
        }

        if (
            $provider === MessageAttachment::PROVIDER_TELEGRAM_BOT
            && (bool) config('bots.telegram.local_api_media_download_enabled', false)
        ) {
            return $this->manualHardLimitBytes();
        }

        if (
            $provider === MessageAttachment::PROVIDER_MAX_BOT
            && (bool) config('bots.media.max_streaming_download_enabled', false)
        ) {
            return $this->manualHardLimitBytes();
        }

        return max(1, (int) config('bots.media.download_max_bytes', self::DEFAULT_AUTO_DOWNLOAD_MAX_BYTES));
    }

    private function automaticTransportMaxBytes(string $provider): int
    {
        if ($provider === MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT) {
            return $this->manualHardLimitBytes();
        }

        if (
            $provider === MessageAttachment::PROVIDER_TELEGRAM_BOT
            && (bool) config('bots.telegram.local_api_media_download_enabled', false)
        ) {
            return $this->manualHardLimitBytes();
        }

        if (
            $provider === MessageAttachment::PROVIDER_MAX_BOT
            && (bool) config('bots.media.max_streaming_download_enabled', false)
        ) {
            return $this->manualHardLimitBytes();
        }

        return max(1, (int) config('bots.media.download_max_bytes', self::DEFAULT_AUTO_DOWNLOAD_MAX_BYTES));
    }

    private function hasProviderReference(MessageAttachment $attachment): bool
    {
        return filled($attachment->provider_file_id)
            || filled($attachment->provider_file_reference)
            || filled($attachment->provider_attachment_key);
    }

    private function hasManualState(MessageAttachment $attachment): bool
    {
        return in_array($attachment->download_status, [
            MessageAttachment::DOWNLOAD_STATUS_AVAILABLE_ON_DEMAND,
            MessageAttachment::DOWNLOAD_STATUS_METADATA_ONLY,
            MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED,
            MessageAttachment::DOWNLOAD_STATUS_DELETED_LOCAL,
        ], true);
    }

    private function isTerminallyUnavailable(MessageAttachment $attachment): bool
    {
        return in_array($attachment->safe_error_code, [
            self::REASON_SOURCE_UNAVAILABLE,
            self::REASON_UNSUPPORTED_MEDIA_KIND,
            self::REASON_MANUAL_HARD_LIMIT,
            'missing_provider_file_id',
            'telegram_file_not_found',
            'tdlib_file_not_found',
            'bot_media_download_invalid_payload',
            'provider_authorization_failed',
            'provider_request_failed',
        ], true);
    }

    private function quotaReasonMessage(?string $reason): string
    {
        return match ($reason) {
            self::REASON_STORAGE_QUOTA_EXCEEDED => 'Недостаточно свободного места для загрузки файла.',
            self::REASON_TRAFFIC_QUOTA_EXCEEDED => 'Дневной лимит загрузки медиа для канала исчерпан.',
            default => 'Загрузка временно недоступна из-за ограничения хранения.',
        };
    }

    /**
     * @return array{status:string, reason:?string, message:?string}
     */
    private function decision(string $status, ?string $reason, ?string $message): array
    {
        return compact('status', 'reason', 'message');
    }

    /**
     * @return array{visible:bool, allowed:bool, reason:?string}
     */
    private function manualDecision(bool $visible, bool $allowed, ?string $reason): array
    {
        return compact('visible', 'allowed', 'reason');
    }
}
