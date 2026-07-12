<?php

namespace Tests\Feature;

use App\Data\TelegramAccount\NormalizedInboundMessageEvent;
use App\Models\Channel;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Services\TelegramAccount\ClaimTelegramAccountMediaDownloadAction;
use App\Services\TelegramAccount\CreateTelegramAccountMediaUploadTargetAction;
use App\Services\TelegramAccount\StoreTelegramAccountMediaDownloadResultAction;
use App\Services\TelegramAccount\SyncTelegramAccountInboundMessageAttachmentsAction;
use App\Services\TelegramAccount\TelegramAccountMediaDownloadPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class TelegramAccountMediaOnDemandTest extends TestCase
{
    use RefreshDatabase;

    public function test_policy_uses_channel_limit_and_defers_unknown_or_oversized_files(): void
    {
        $channel = Channel::factory()->account()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'telegram_account_media_auto_download_max_bytes' => 10,
        ]);
        $policy = app(TelegramAccountMediaDownloadPolicy::class);

        $this->assertSame(10, $policy->automaticMaxBytes($channel));
        $this->assertSame(
            MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD,
            $policy->initialDownloadStatus($channel, 10),
        );
        $this->assertSame(
            MessageAttachment::DOWNLOAD_STATUS_AVAILABLE_ON_DEMAND,
            $policy->initialDownloadStatus($channel, 11),
        );
        $this->assertSame(
            MessageAttachment::DOWNLOAD_STATUS_AVAILABLE_ON_DEMAND,
            $policy->initialDownloadStatus($channel, null),
        );

        $channel->telegram_account_media_auto_download_max_bytes = 0;

        $this->assertSame(
            MessageAttachment::DOWNLOAD_STATUS_AVAILABLE_ON_DEMAND,
            $policy->initialDownloadStatus($channel, 1),
        );
    }

    public function test_backfill_is_dry_run_by_default_and_only_converts_legacy_large_file_errors(): void
    {
        $channel = Channel::factory()->account()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $message = Message::factory()->create([
            'channel_id' => $channel->id,
        ]);
        $legacyLargeFile = MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $channel->id,
            'provider' => MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT,
            'provider_file_id' => 'legacy-large-file',
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED,
            'safe_error_code' => 'file_too_large',
            'safe_error_message' => 'Telegram Account media file is larger than the local download limit.',
        ]);
        $unrelatedFailure = MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $channel->id,
            'provider' => MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT,
            'provider_file_id' => 'unrelated-failure',
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED,
            'safe_error_code' => 'telegram_file_not_found',
        ]);

        $this->artisan('telegram-account-media:backfill-on-demand')
            ->expectsOutputToContain('Dry-run завершён')
            ->assertSuccessful();

        $this->assertSame(
            MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED,
            $legacyLargeFile->fresh()->download_status,
        );

        $this->artisan('telegram-account-media:backfill-on-demand', ['--apply' => true])
            ->expectsOutputToContain('Преобразовано вложений: 1')
            ->assertSuccessful();

        $legacyLargeFile->refresh();
        $unrelatedFailure->refresh();

        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_AVAILABLE_ON_DEMAND, $legacyLargeFile->download_status);
        $this->assertSame('auto_download_limit_exceeded', $legacyLargeFile->safe_error_code);
        $this->assertNull($legacyLargeFile->safe_error_message);
        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED, $unrelatedFailure->download_status);
        $this->assertSame('telegram_file_not_found', $unrelatedFailure->safe_error_code);
    }

    public function test_manual_request_policy_hides_terminal_telegram_errors(): void
    {
        $policy = app(TelegramAccountMediaDownloadPolicy::class);
        $attachment = new MessageAttachment([
            'provider' => MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT,
            'provider_file_id' => '42',
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED,
            'safe_error_code' => 'tdlib_file_not_found',
        ]);

        $this->assertFalse($policy->canRequestManually($attachment));

        $attachment->safe_error_code = 'telegram_file_not_found';

        $this->assertFalse($policy->canRequestManually($attachment));

        $attachment->safe_error_code = 'file_too_large';

        $this->assertTrue($policy->canRequestManually($attachment));
    }

    public function test_replayed_event_does_not_auto_download_file_already_deferred_for_manual_request(): void
    {
        $channel = Channel::factory()->account()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'telegram_account_media_auto_download_max_bytes' => 10,
        ]);
        $message = Message::factory()->create([
            'channel_id' => $channel->id,
            'provider_event_key' => 'telegram-account:deferred-replay',
            'external_message_id' => 'deferred-replay-message',
        ]);
        $attachment = MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $channel->id,
            'provider' => MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT,
            'provider_event_key' => $message->provider_event_key,
            'provider_attachment_key' => 'deferred-replay-file',
            'provider_file_id' => 'deferred-replay-file',
            'file_size_bytes' => 11,
            'media_kind' => MessageAttachment::MEDIA_KIND_DOCUMENT,
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_AVAILABLE_ON_DEMAND,
            'safe_error_code' => TelegramAccountMediaDownloadPolicy::ERROR_AUTO_DOWNLOAD_LIMIT_EXCEEDED,
        ]);

        $channel->forceFill([
            'telegram_account_media_auto_download_max_bytes' => 100,
        ])->save();

        app(SyncTelegramAccountInboundMessageAttachmentsAction::class)->handle(
            $message,
            new NormalizedInboundMessageEvent(
                schemaVersion: '1',
                gatewayEventId: 'deferred-replay-event',
                channelId: (int) $channel->id,
                platform: Channel::PLATFORM_TELEGRAM,
                connectionType: Channel::CONNECTION_TYPE_ACCOUNT,
                peerType: NormalizedInboundMessageEvent::PEER_TYPE_PRIVATE,
                peerKey: 'telegram_account:deferred-replay-peer',
                messageKey: (string) $message->provider_event_key,
                externalChatId: 'deferred-replay-chat',
                externalUserId: 'deferred-replay-user',
                externalMessageId: (string) $message->external_message_id,
                externalUsername: null,
                contactName: null,
                messageKind: 'media',
                text: null,
                media: [[
                    'provider_attachment_key' => 'deferred-replay-file',
                    'provider_file_id' => 'deferred-replay-file',
                    'type' => 'document',
                    'file_size_bytes' => 11,
                ]],
                isArchived: false,
                rawPayload: [],
                occurredAt: now(),
                historySource: NormalizedInboundMessageEvent::HISTORY_SOURCE_LIVE,
            ),
        );

        $attachment->refresh();

        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_AVAILABLE_ON_DEMAND, $attachment->download_status);
        $this->assertSame(
            TelegramAccountMediaDownloadPolicy::ERROR_AUTO_DOWNLOAD_LIMIT_EXCEEDED,
            $attachment->safe_error_code,
        );
        $this->assertNull(app(ClaimTelegramAccountMediaDownloadAction::class)->handle($channel));
    }

    public function test_replayed_event_preserves_terminal_media_download_failure(): void
    {
        $channel = Channel::factory()->account()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'telegram_account_media_auto_download_max_bytes' => 100,
        ]);
        $message = Message::factory()->create([
            'channel_id' => $channel->id,
            'provider_event_key' => 'telegram-account:terminal-replay',
            'external_message_id' => 'terminal-replay-message',
        ]);
        $attachment = MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $channel->id,
            'provider' => MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT,
            'provider_event_key' => $message->provider_event_key,
            'provider_attachment_key' => 'terminal-replay-file',
            'provider_file_id' => 'terminal-replay-file',
            'file_size_bytes' => 11,
            'media_kind' => MessageAttachment::MEDIA_KIND_DOCUMENT,
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED,
            'safe_error_code' => 'telegram_file_not_found',
            'safe_error_message' => 'Telegram file is no longer available.',
        ]);

        app(SyncTelegramAccountInboundMessageAttachmentsAction::class)->handle(
            $message,
            new NormalizedInboundMessageEvent(
                schemaVersion: '1',
                gatewayEventId: 'terminal-replay-event',
                channelId: (int) $channel->id,
                platform: Channel::PLATFORM_TELEGRAM,
                connectionType: Channel::CONNECTION_TYPE_ACCOUNT,
                peerType: NormalizedInboundMessageEvent::PEER_TYPE_PRIVATE,
                peerKey: 'telegram_account:terminal-replay-peer',
                messageKey: (string) $message->provider_event_key,
                externalChatId: 'terminal-replay-chat',
                externalUserId: 'terminal-replay-user',
                externalMessageId: (string) $message->external_message_id,
                externalUsername: null,
                contactName: null,
                messageKind: 'media',
                text: null,
                media: [[
                    'provider_attachment_key' => 'terminal-replay-file',
                    'provider_file_id' => 'terminal-replay-file',
                    'type' => 'document',
                    'file_size_bytes' => 11,
                ]],
                isArchived: false,
                rawPayload: [],
                occurredAt: now(),
                historySource: NormalizedInboundMessageEvent::HISTORY_SOURCE_LIVE,
            ),
        );

        $attachment->refresh();

        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED, $attachment->download_status);
        $this->assertSame('telegram_file_not_found', $attachment->safe_error_code);
        $this->assertSame('Telegram file is no longer available.', $attachment->safe_error_message);
        $this->assertNull(app(ClaimTelegramAccountMediaDownloadAction::class)->handle($channel));
    }

    public function test_replayed_pending_event_preserves_limit_snapshot_and_retry_window(): void
    {
        $channel = Channel::factory()->account()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'telegram_account_media_auto_download_max_bytes' => 100,
        ]);
        $message = Message::factory()->create([
            'channel_id' => $channel->id,
            'provider_event_key' => 'telegram-account:pending-replay',
            'external_message_id' => 'pending-replay-message',
        ]);
        $retryAt = now()->addMinute()->startOfSecond();
        $attachment = MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $channel->id,
            'provider' => MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT,
            'provider_event_key' => $message->provider_event_key,
            'provider_attachment_key' => 'pending-replay-file',
            'provider_file_id' => 'pending-replay-file',
            'file_size_bytes' => 11,
            'media_kind' => MessageAttachment::MEDIA_KIND_DOCUMENT,
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD,
            'media_download_max_bytes' => 100,
            'media_download_next_retry_at' => $retryAt,
            'safe_error_code' => 'tdlib_timeout',
            'safe_error_message' => 'TDLib download timed out.',
        ]);

        $channel->forceFill([
            'telegram_account_media_auto_download_max_bytes' => 10,
        ])->save();

        app(SyncTelegramAccountInboundMessageAttachmentsAction::class)->handle(
            $message,
            new NormalizedInboundMessageEvent(
                schemaVersion: '1',
                gatewayEventId: 'pending-replay-event',
                channelId: (int) $channel->id,
                platform: Channel::PLATFORM_TELEGRAM,
                connectionType: Channel::CONNECTION_TYPE_ACCOUNT,
                peerType: NormalizedInboundMessageEvent::PEER_TYPE_PRIVATE,
                peerKey: 'telegram_account:pending-replay-peer',
                messageKey: (string) $message->provider_event_key,
                externalChatId: 'pending-replay-chat',
                externalUserId: 'pending-replay-user',
                externalMessageId: (string) $message->external_message_id,
                externalUsername: null,
                contactName: null,
                messageKind: 'media',
                text: null,
                media: [[
                    'provider_attachment_key' => 'pending-replay-file',
                    'provider_file_id' => 'pending-replay-file',
                    'type' => 'document',
                    'file_size_bytes' => 11,
                ]],
                isArchived: false,
                rawPayload: [],
                occurredAt: now(),
                historySource: NormalizedInboundMessageEvent::HISTORY_SOURCE_LIVE,
            ),
        );

        $attachment->refresh();

        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD, $attachment->download_status);
        $this->assertSame(100, $attachment->media_download_max_bytes);
        $this->assertTrue($attachment->media_download_next_retry_at->equalTo($retryAt));
        $this->assertSame('tdlib_timeout', $attachment->safe_error_code);
        $this->assertNull(app(ClaimTelegramAccountMediaDownloadAction::class)->handle($channel));
    }

    public function test_s3_upload_target_is_private_temporary_put_without_exposing_host_header(): void
    {
        config()->set('filesystems.message_attachments_disk', 'message_attachments');
        config()->set('filesystems.disks.message_attachments.driver', 's3');

        $channel = new Channel(['platform' => Channel::PLATFORM_TELEGRAM]);
        $channel->id = 77;
        $channel->exists = true;
        $attachment = new MessageAttachment([
            'channel_id' => 77,
            'provider' => MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT,
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING,
            'media_download_claim_token' => 'claim-88',
        ]);
        $attachment->id = 88;
        $attachment->message_id = 99;
        $attachment->exists = true;
        $disk = Mockery::mock();
        $disk->shouldReceive('temporaryUploadUrl')
            ->once()
            ->with('message-attachments/99/88.claim-88.upload', Mockery::type(\DateTimeInterface::class))
            ->andReturn([
                'url' => 'https://private-storage.example.test/signed-object',
                'headers' => [
                    'Host' => ['private-storage.example.test'],
                    'x-amz-security-token' => ['temporary-token'],
                ],
            ]);
        Storage::shouldReceive('disk')
            ->once()
            ->with('message_attachments')
            ->andReturn($disk);

        $target = app(CreateTelegramAccountMediaUploadTargetAction::class)->handle($channel, $attachment);

        $this->assertSame('direct_put', $target['strategy']);
        $this->assertSame('https://private-storage.example.test/signed-object', $target['url']);
        $this->assertSame(['x-amz-security-token' => 'temporary-token'], $target['headers']);
        $this->assertFalse($target['requires_gateway_auth']);
    }

    public function test_stale_claim_keeps_cleanup_token_when_temporary_object_cannot_be_deleted(): void
    {
        config()->set('filesystems.message_attachments_disk', MessageAttachment::LOCAL_DISK_PRIVATE);

        $channel = Channel::factory()->account()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $message = Message::factory()->create([
            'channel_id' => $channel->id,
        ]);
        $attachment = MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $channel->id,
            'provider' => MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT,
            'provider_file_id' => 'stale-cleanup-file',
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING,
            'media_download_claim_token' => 'stale-cleanup-token',
            'updated_at' => now()->subMinutes(131),
        ]);
        $temporaryPath = "message-attachments/{$message->id}/{$attachment->id}.stale-cleanup-token.upload";
        $disk = Mockery::mock();
        $disk->shouldReceive('exists')->once()->with($temporaryPath)->andReturn(true);
        $disk->shouldReceive('delete')->once()->with($temporaryPath)->andReturn(false);
        Storage::shouldReceive('disk')
            ->twice()
            ->with(MessageAttachment::LOCAL_DISK_PRIVATE)
            ->andReturn($disk);

        $claimed = app(ClaimTelegramAccountMediaDownloadAction::class)->handle($channel);

        $this->assertNull($claimed);

        $attachment->refresh();

        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING, $attachment->download_status);
        $this->assertSame('stale-cleanup-token', $attachment->media_download_claim_token);
        $this->assertSame('stale_upload_cleanup_failed', $attachment->safe_error_code);
        $this->assertTrue($attachment->updated_at->between(
            now()->subMinutes(126),
            now()->subMinutes(124),
        ));
    }

    public function test_direct_upload_object_becomes_final_object_without_copy_or_delete(): void
    {
        config()->set('filesystems.message_attachments_disk', 'message_attachments');
        config()->set('filesystems.disks.message_attachments.driver', 's3');

        $channel = Channel::factory()->account()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $message = Message::factory()->create([
            'channel_id' => $channel->id,
        ]);
        $attachment = MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $channel->id,
            'provider' => MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT,
            'provider_file_id' => 's3-cleanup-file',
            'media_kind' => MessageAttachment::MEDIA_KIND_DOCUMENT,
            'original_filename' => 'cleanup.pdf',
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING,
            'media_download_claim_token' => 's3-cleanup-token',
            'media_download_upload_size_bytes' => 7,
        ]);
        $temporaryPath = "message-attachments/{$message->id}/{$attachment->id}.s3-cleanup-token.upload";
        $stream = fopen('php://temp', 'w+b');
        $this->assertIsResource($stream);
        fwrite($stream, 'S3-DATA');
        rewind($stream);

        $disk = Mockery::mock();
        $disk->shouldReceive('exists')->once()->with($temporaryPath)->andReturn(true);
        $disk->shouldReceive('readStream')->once()->with($temporaryPath)->andReturn($stream);
        $disk->shouldReceive('size')->once()->with($temporaryPath)->andReturn(7);
        Storage::shouldReceive('disk')->with('message_attachments')->andReturn($disk);

        $stored = app(StoreTelegramAccountMediaDownloadResultAction::class)->markDownloadedFromDirectUpload(
            $channel,
            $attachment,
            's3-cleanup-token',
            [
                'original_filename' => 'cleanup.pdf',
                'mime_type' => 'application/pdf',
                'file_size_bytes' => 7,
            ],
        );

        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED, $stored->download_status);
        $this->assertSame($temporaryPath, $stored->local_path);
        $this->assertNull($stored->media_download_upload_size_bytes);
        $this->assertNull($stored->media_download_claim_token);
    }

    public function test_migration_refuses_rollback_while_media_claim_token_is_active(): void
    {
        $channel = Channel::factory()->account()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $message = Message::factory()->create([
            'channel_id' => $channel->id,
        ]);
        $attachment = MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $channel->id,
            'provider' => MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT,
            'provider_file_id' => 'active-rollback-file',
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING,
            'media_download_claim_token' => 'active-rollback-token',
        ]);

        $migration = require database_path('migrations/2026_07_11_000001_add_telegram_account_media_on_demand_fields.php');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('active media claims exist');

        $migration->down();
    }
}
