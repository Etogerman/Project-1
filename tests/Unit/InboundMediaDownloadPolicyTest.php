<?php

namespace Tests\Unit;

use App\Models\Channel;
use App\Models\MessageAttachment;
use App\Services\Messages\InboundMediaDownloadPolicy;
use Tests\TestCase;

class InboundMediaDownloadPolicyTest extends TestCase
{
    public function test_cloud_telegram_bot_marks_file_above_transport_limit_unavailable(): void
    {
        config([
            'bots.media.download_max_bytes' => 20 * 1024 * 1024,
            'bots.telegram.local_api_media_download_enabled' => false,
        ]);

        $channel = (new Channel)->forceFill([
            'inbound_media_on_demand_enabled' => true,
        ]);
        $policy = app(InboundMediaDownloadPolicy::class);

        $decision = $policy->initialDecision(
            $channel,
            MessageAttachment::PROVIDER_TELEGRAM_BOT,
            MessageAttachment::MEDIA_KIND_VIDEO,
            20 * 1024 * 1024 + 1,
        );

        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_METADATA_ONLY, $decision['status']);
        $this->assertSame(InboundMediaDownloadPolicy::REASON_TRANSPORT_UNAVAILABLE, $decision['reason']);
        $this->assertSame(
            'Облачный Telegram Bot API загружает файлы только до 20 МБ. Для большего файла нужен Local Bot API.',
            $decision['message'],
        );
    }

    public function test_cloud_telegram_cap_does_not_follow_larger_shared_media_limit(): void
    {
        config([
            'bots.media.download_max_bytes' => 64 * 1024 * 1024,
            'bots.telegram.local_api_media_download_enabled' => false,
        ]);

        $decision = app(InboundMediaDownloadPolicy::class)->initialDecision(
            new Channel,
            MessageAttachment::PROVIDER_TELEGRAM_BOT,
            MessageAttachment::MEDIA_KIND_VIDEO,
            20 * 1024 * 1024 + 1,
        );

        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_METADATA_ONLY, $decision['status']);
        $this->assertSame(InboundMediaDownloadPolicy::REASON_TRANSPORT_UNAVAILABLE, $decision['reason']);
        $this->assertStringContainsString('20 МБ', (string) $decision['message']);
    }

    public function test_local_telegram_bot_keeps_file_above_auto_limit_available_on_demand(): void
    {
        config([
            'bots.media.download_max_bytes' => 20 * 1024 * 1024,
            'bots.telegram.local_api_media_download_enabled' => true,
            'bots.telegram.local_api_base_url' => 'http://telegram-bot-api:8081',
            'bots.telegram.local_api_allow_insecure_http' => true,
            'bots.telegram.local_api_trusted_hosts' => ['telegram-bot-api'],
            'bots.telegram.local_api_file_transport' => 'filesystem',
            'bots.telegram.local_api_files_root' => storage_path('framework/testing'),
        ]);

        $channel = (new Channel)->forceFill([
            'inbound_media_on_demand_enabled' => true,
        ]);
        $policy = app(InboundMediaDownloadPolicy::class);
        $fileSize = 20 * 1024 * 1024 + 1;

        $decision = $policy->initialDecision(
            $channel,
            MessageAttachment::PROVIDER_TELEGRAM_BOT,
            MessageAttachment::MEDIA_KIND_VIDEO,
            $fileSize,
        );

        $attachment = (new MessageAttachment)->forceFill([
            'provider' => MessageAttachment::PROVIDER_TELEGRAM_BOT,
            'media_kind' => MessageAttachment::MEDIA_KIND_VIDEO,
            'provider_file_id' => 'telegram-large-file',
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_AVAILABLE_ON_DEMAND,
            'file_size_bytes' => $fileSize,
        ]);
        $attachment->setRelation('channel', $channel);

        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_AVAILABLE_ON_DEMAND, $decision['status']);
        $this->assertSame(InboundMediaDownloadPolicy::REASON_SIZE_ABOVE_AUTO_LIMIT, $decision['reason']);
        $this->assertTrue($policy->manualAvailability($attachment)['allowed']);
    }

    public function test_missing_filesystem_files_root_keeps_large_telegram_file_unavailable(): void
    {
        config([
            'bots.media.download_max_bytes' => 20 * 1024 * 1024,
            'bots.telegram.local_api_media_download_enabled' => true,
            'bots.telegram.local_api_base_url' => 'http://telegram-bot-api:8081',
            'bots.telegram.local_api_allow_insecure_http' => true,
            'bots.telegram.local_api_trusted_hosts' => ['telegram-bot-api'],
            'bots.telegram.local_api_file_transport' => 'filesystem',
            'bots.telegram.local_api_files_root' => storage_path('framework/testing/missing-telegram-files-root-'.uniqid('', true)),
        ]);

        $decision = app(InboundMediaDownloadPolicy::class)->initialDecision(
            new Channel,
            MessageAttachment::PROVIDER_TELEGRAM_BOT,
            MessageAttachment::MEDIA_KIND_VIDEO,
            20 * 1024 * 1024 + 1,
        );

        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_METADATA_ONLY, $decision['status']);
        $this->assertSame(InboundMediaDownloadPolicy::REASON_TRANSPORT_UNAVAILABLE, $decision['reason']);
    }

    public function test_incomplete_http_bridge_keeps_large_telegram_file_unavailable(): void
    {
        config([
            'bots.media.download_max_bytes' => 20 * 1024 * 1024,
            'bots.telegram.local_api_media_download_enabled' => true,
            'bots.telegram.local_api_base_url' => 'https://telegram-gateway.example/api',
            'bots.telegram.local_api_trusted_hosts' => ['telegram-gateway.example'],
            'bots.telegram.local_api_username' => null,
            'bots.telegram.local_api_password' => null,
            'bots.telegram.local_api_file_transport' => 'http_bridge',
            'bots.telegram.local_api_files_root' => '/var/lib/telegram-bot-api',
            'bots.telegram.local_api_file_bridge_base_url' => 'https://telegram-gateway.example/files',
            'bots.telegram.local_api_file_bridge_trusted_hosts' => ['telegram-gateway.example'],
            'bots.telegram.local_api_file_bridge_username' => 'media-reader',
            'bots.telegram.local_api_file_bridge_password' => 'bridge-secret',
        ]);

        $decision = app(InboundMediaDownloadPolicy::class)->initialDecision(
            new Channel,
            MessageAttachment::PROVIDER_TELEGRAM_BOT,
            MessageAttachment::MEDIA_KIND_VIDEO,
            20 * 1024 * 1024 + 1,
        );

        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_METADATA_ONLY, $decision['status']);
        $this->assertSame(InboundMediaDownloadPolicy::REASON_TRANSPORT_UNAVAILABLE, $decision['reason']);
    }

    public function test_incomplete_optional_local_api_credentials_keep_transport_unavailable(): void
    {
        config([
            'bots.media.download_max_bytes' => 20 * 1024 * 1024,
            'bots.telegram.local_api_media_download_enabled' => true,
            'bots.telegram.local_api_base_url' => 'http://telegram-bot-api:8081',
            'bots.telegram.local_api_allow_insecure_http' => true,
            'bots.telegram.local_api_trusted_hosts' => ['telegram-bot-api'],
            'bots.telegram.local_api_username' => 'media-reader',
            'bots.telegram.local_api_password' => null,
            'bots.telegram.local_api_file_transport' => 'filesystem',
            'bots.telegram.local_api_files_root' => '/var/lib/telegram-bot-api',
        ]);

        $decision = app(InboundMediaDownloadPolicy::class)->initialDecision(
            new Channel,
            MessageAttachment::PROVIDER_TELEGRAM_BOT,
            MessageAttachment::MEDIA_KIND_VIDEO,
            20 * 1024 * 1024 + 1,
        );

        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_METADATA_ONLY, $decision['status']);
        $this->assertSame(InboundMediaDownloadPolicy::REASON_TRANSPORT_UNAVAILABLE, $decision['reason']);
    }

    public function test_incomplete_file_bridge_credentials_keep_transport_unavailable(): void
    {
        config([
            'bots.media.download_max_bytes' => 20 * 1024 * 1024,
            'bots.telegram.local_api_media_download_enabled' => true,
            'bots.telegram.local_api_base_url' => 'https://telegram-gateway.example/api',
            'bots.telegram.local_api_trusted_hosts' => ['telegram-gateway.example'],
            'bots.telegram.local_api_username' => 'media-reader',
            'bots.telegram.local_api_password' => 'gateway-secret',
            'bots.telegram.local_api_file_transport' => 'http_bridge',
            'bots.telegram.local_api_files_root' => '/var/lib/telegram-bot-api',
            'bots.telegram.local_api_file_bridge_base_url' => 'https://telegram-gateway.example/files',
            'bots.telegram.local_api_file_bridge_trusted_hosts' => ['telegram-gateway.example'],
            'bots.telegram.local_api_file_bridge_username' => 'file-reader',
            'bots.telegram.local_api_file_bridge_password' => null,
        ]);

        $decision = app(InboundMediaDownloadPolicy::class)->initialDecision(
            new Channel,
            MessageAttachment::PROVIDER_TELEGRAM_BOT,
            MessageAttachment::MEDIA_KIND_VIDEO,
            51 * 1024 * 1024,
        );

        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_METADATA_ONLY, $decision['status']);
        $this->assertSame(InboundMediaDownloadPolicy::REASON_TRANSPORT_UNAVAILABLE, $decision['reason']);
    }

    public function test_relative_http_bridge_files_root_keeps_large_telegram_file_unavailable(): void
    {
        config([
            'bots.media.download_max_bytes' => 20 * 1024 * 1024,
            'bots.telegram.local_api_media_download_enabled' => true,
            'bots.telegram.local_api_base_url' => 'https://telegram-gateway.example/api',
            'bots.telegram.local_api_trusted_hosts' => ['telegram-gateway.example'],
            'bots.telegram.local_api_username' => 'media-reader',
            'bots.telegram.local_api_password' => 'gateway-secret',
            'bots.telegram.local_api_file_transport' => 'http_bridge',
            'bots.telegram.local_api_files_root' => 'telegram-bot-api',
            'bots.telegram.local_api_file_bridge_base_url' => 'https://telegram-gateway.example/files',
            'bots.telegram.local_api_file_bridge_trusted_hosts' => ['telegram-gateway.example'],
            'bots.telegram.local_api_file_bridge_username' => 'file-reader',
            'bots.telegram.local_api_file_bridge_password' => 'bridge-secret',
        ]);

        $fileSize = 51 * 1024 * 1024;
        $channel = (new Channel)->forceFill([
            'inbound_media_on_demand_enabled' => true,
        ]);
        $policy = app(InboundMediaDownloadPolicy::class);
        $decision = $policy->initialDecision(
            $channel,
            MessageAttachment::PROVIDER_TELEGRAM_BOT,
            MessageAttachment::MEDIA_KIND_VIDEO,
            $fileSize,
        );
        $attachment = (new MessageAttachment)->forceFill([
            'provider' => MessageAttachment::PROVIDER_TELEGRAM_BOT,
            'media_kind' => MessageAttachment::MEDIA_KIND_VIDEO,
            'provider_file_id' => 'telegram-large-file',
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_METADATA_ONLY,
            'file_size_bytes' => $fileSize,
        ]);
        $attachment->setRelation('channel', $channel);

        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_METADATA_ONLY, $decision['status']);
        $this->assertSame(InboundMediaDownloadPolicy::REASON_TRANSPORT_UNAVAILABLE, $decision['reason']);
        $this->assertFalse($policy->manualAvailability($attachment)['allowed']);
    }

    public function test_ipv6_loopback_http_bridge_allows_large_telegram_file_when_allowlisted(): void
    {
        config([
            'bots.media.download_max_bytes' => 20 * 1024 * 1024,
            'bots.telegram.local_api_media_download_enabled' => true,
            'bots.telegram.local_api_base_url' => 'http://[::1]:8081/api',
            'bots.telegram.local_api_allow_insecure_http' => true,
            'bots.telegram.local_api_trusted_hosts' => ['::1'],
            'bots.telegram.local_api_username' => 'media-reader',
            'bots.telegram.local_api_password' => 'gateway-secret',
            'bots.telegram.local_api_file_transport' => 'http_bridge',
            'bots.telegram.local_api_files_root' => '/var/lib/telegram-bot-api',
            'bots.telegram.local_api_file_bridge_base_url' => 'http://[::1]:8082/files',
            'bots.telegram.local_api_file_bridge_trusted_hosts' => ['::1'],
            'bots.telegram.local_api_file_bridge_username' => 'file-reader',
            'bots.telegram.local_api_file_bridge_password' => 'bridge-secret',
        ]);

        $decision = app(InboundMediaDownloadPolicy::class)->initialDecision(
            (new Channel)->forceFill(['inbound_media_on_demand_enabled' => true]),
            MessageAttachment::PROVIDER_TELEGRAM_BOT,
            MessageAttachment::MEDIA_KIND_VIDEO,
            51 * 1024 * 1024,
        );

        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_AVAILABLE_ON_DEMAND, $decision['status']);
        $this->assertSame(InboundMediaDownloadPolicy::REASON_SIZE_ABOVE_AUTO_LIMIT, $decision['reason']);
    }

    public function test_malformed_bracketed_ipv6_http_bridge_does_not_mark_large_telegram_file_available(): void
    {
        config([
            'bots.media.download_max_bytes' => 20 * 1024 * 1024,
            'bots.telegram.local_api_media_download_enabled' => true,
            'bots.telegram.local_api_base_url' => 'http://[::1] :8081/api',
            'bots.telegram.local_api_allow_insecure_http' => true,
            'bots.telegram.local_api_trusted_hosts' => ['::1'],
            'bots.telegram.local_api_username' => 'media-reader',
            'bots.telegram.local_api_password' => 'gateway-secret',
            'bots.telegram.local_api_file_transport' => 'http_bridge',
            'bots.telegram.local_api_files_root' => '/var/lib/telegram-bot-api',
            'bots.telegram.local_api_file_bridge_base_url' => 'http://[::1] :8082/files',
            'bots.telegram.local_api_file_bridge_trusted_hosts' => ['::1'],
            'bots.telegram.local_api_file_bridge_username' => 'file-reader',
            'bots.telegram.local_api_file_bridge_password' => 'bridge-secret',
        ]);

        $decision = app(InboundMediaDownloadPolicy::class)->initialDecision(
            (new Channel)->forceFill(['inbound_media_on_demand_enabled' => true]),
            MessageAttachment::PROVIDER_TELEGRAM_BOT,
            MessageAttachment::MEDIA_KIND_VIDEO,
            51 * 1024 * 1024,
        );

        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_METADATA_ONLY, $decision['status']);
        $this->assertSame(InboundMediaDownloadPolicy::REASON_TRANSPORT_UNAVAILABLE, $decision['reason']);
    }

    public function test_complete_http_bridge_allows_51_mib_telegram_file_on_demand(): void
    {
        config([
            'bots.media.download_max_bytes' => 20 * 1024 * 1024,
            'bots.telegram.local_api_media_download_enabled' => true,
            'bots.telegram.local_api_base_url' => 'https://telegram-gateway.example/api',
            'bots.telegram.local_api_trusted_hosts' => ['telegram-gateway.example'],
            'bots.telegram.local_api_username' => 'media-reader',
            'bots.telegram.local_api_password' => 'gateway-secret',
            'bots.telegram.local_api_file_transport' => 'http_bridge',
            'bots.telegram.local_api_files_root' => '/var/lib/telegram-bot-api',
            'bots.telegram.local_api_file_bridge_base_url' => 'https://telegram-gateway.example/files',
            'bots.telegram.local_api_file_bridge_trusted_hosts' => ['telegram-gateway.example'],
            'bots.telegram.local_api_file_bridge_username' => 'media-reader',
            'bots.telegram.local_api_file_bridge_password' => 'gateway-secret',
        ]);

        $channel = (new Channel)->forceFill([
            'inbound_media_on_demand_enabled' => true,
        ]);
        $policy = app(InboundMediaDownloadPolicy::class);
        $fileSize = 51 * 1024 * 1024;
        $decision = $policy->initialDecision(
            $channel,
            MessageAttachment::PROVIDER_TELEGRAM_BOT,
            MessageAttachment::MEDIA_KIND_VIDEO,
            $fileSize,
        );
        $attachment = (new MessageAttachment)->forceFill([
            'provider' => MessageAttachment::PROVIDER_TELEGRAM_BOT,
            'media_kind' => MessageAttachment::MEDIA_KIND_VIDEO,
            'provider_file_id' => 'telegram-large-file',
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_AVAILABLE_ON_DEMAND,
            'file_size_bytes' => $fileSize,
        ]);
        $attachment->setRelation('channel', $channel);

        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_AVAILABLE_ON_DEMAND, $decision['status']);
        $this->assertSame(InboundMediaDownloadPolicy::REASON_SIZE_ABOVE_AUTO_LIMIT, $decision['reason']);
        $this->assertSame(
            ['visible' => true, 'allowed' => true, 'reason' => null],
            $policy->manualAvailability($attachment),
        );
        $this->assertSame(64 * 1024 * 1024, $policy->manualRequestMaxBytes($attachment));
    }

    public function test_http_bridge_does_not_advertise_files_above_its_runtime_limit(): void
    {
        config([
            'bots.media.download_max_bytes' => 20 * 1024 * 1024,
            'bots.telegram.local_api_media_download_enabled' => true,
            'bots.telegram.local_api_base_url' => 'https://telegram-gateway.example/api',
            'bots.telegram.local_api_trusted_hosts' => ['telegram-gateway.example'],
            'bots.telegram.local_api_username' => 'media-reader',
            'bots.telegram.local_api_password' => 'gateway-secret',
            'bots.telegram.local_api_file_transport' => 'http_bridge',
            'bots.telegram.local_api_files_root' => '/var/lib/telegram-bot-api',
            'bots.telegram.local_api_file_bridge_base_url' => 'https://telegram-gateway.example/files',
            'bots.telegram.local_api_file_bridge_trusted_hosts' => ['telegram-gateway.example'],
            'bots.telegram.local_api_file_bridge_username' => 'media-reader',
            'bots.telegram.local_api_file_bridge_password' => 'gateway-secret',
            'bots.telegram.local_api_file_bridge_max_bytes' => 64 * 1024 * 1024,
        ]);

        $decision = app(InboundMediaDownloadPolicy::class)->initialDecision(
            new Channel,
            MessageAttachment::PROVIDER_TELEGRAM_BOT,
            MessageAttachment::MEDIA_KIND_VIDEO,
            64 * 1024 * 1024 + 1,
        );

        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_METADATA_ONLY, $decision['status']);
        $this->assertSame(InboundMediaDownloadPolicy::REASON_TRANSPORT_UNAVAILABLE, $decision['reason']);
        $this->assertSame(
            'Текущий Telegram Local Bot API gateway загружает файлы только до 64 МБ.',
            $decision['message'],
        );
    }

    public function test_cloud_telegram_bot_accepts_file_at_transport_limit_automatically(): void
    {
        config([
            'bots.media.download_max_bytes' => 20 * 1024 * 1024,
            'bots.telegram.local_api_media_download_enabled' => false,
        ]);

        $decision = app(InboundMediaDownloadPolicy::class)->initialDecision(
            new Channel,
            MessageAttachment::PROVIDER_TELEGRAM_BOT,
            MessageAttachment::MEDIA_KIND_VIDEO,
            20 * 1024 * 1024,
        );

        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD, $decision['status']);
        $this->assertNull($decision['reason']);
    }

    public function test_manual_download_uses_configured_application_hard_limit(): void
    {
        config()->set('inbound_media.manual_hard_limit_bytes', 1024);

        $channel = (new Channel)->forceFill([
            'inbound_media_on_demand_enabled' => true,
        ]);

        $attachment = (new MessageAttachment)->forceFill([
            'provider' => MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT,
            'media_kind' => MessageAttachment::MEDIA_KIND_VIDEO,
            'provider_file_reference' => 'provider-file-reference',
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_AVAILABLE_ON_DEMAND,
            'file_size_bytes' => 1025,
        ]);
        $attachment->setRelation('channel', $channel);

        $policy = app(InboundMediaDownloadPolicy::class);

        $this->assertSame(1024, $policy->manualRequestMaxBytes($attachment));
        $this->assertSame(
            ['visible' => false, 'allowed' => false, 'reason' => null],
            $policy->manualAvailability($attachment),
        );
    }
}
