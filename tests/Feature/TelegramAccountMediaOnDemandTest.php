<?php

namespace Tests\Feature;

use App\Data\TelegramAccount\NormalizedInboundMessageEvent;
use App\Jobs\DeleteRolledBackInboundMediaFileJob;
use App\Models\Channel;
use App\Models\Dialog;
use App\Models\MediaDownloadStorageLedger;
use App\Models\MediaDownloadTrafficLedger;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Services\Messages\InboundMediaDownloadPolicy;
use App\Services\Messages\InboundMediaQuotaExceededException;
use App\Services\Messages\InboundMediaQuotaLedger;
use App\Services\Messages\StoreMessageAttachmentLocalFileAction;
use App\Services\TelegramAccount\ClaimTelegramAccountMediaDownloadAction;
use App\Services\TelegramAccount\CreateTelegramAccountMediaUploadTargetAction;
use App\Services\TelegramAccount\RequestTelegramAccountMediaDownloadAction;
use App\Services\TelegramAccount\StoreTelegramAccountMediaDirectUploadAction;
use App\Services\TelegramAccount\StoreTelegramAccountMediaDownloadResultAction;
use App\Services\TelegramAccount\SyncTelegramAccountInboundMessageAttachmentsAction;
use App\Services\TelegramAccount\TelegramAccountMediaDownloadPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
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
        $this->assertSame(
            MessageAttachment::DOWNLOAD_STATUS_AVAILABLE_ON_DEMAND,
            $policy->initialDownloadStatus($channel, 0),
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
        $dialog = Dialog::factory()->create([
            'channel_id' => $channel->id,
        ]);
        $message = Message::factory()->create([
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
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
        $manualFailure = MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $channel->id,
            'provider' => MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT,
            'provider_file_id' => 'manual-large-file',
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED,
            'safe_error_code' => 'file_too_large',
            'manual_download_requested_at' => now(),
        ]);
        $localFailure = MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $channel->id,
            'provider' => MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT,
            'provider_file_id' => 'local-large-file',
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED,
            'safe_error_code' => 'file_too_large',
            'local_disk' => MessageAttachment::LOCAL_DISK_PRIVATE,
            'local_path' => 'message-attachments/existing.bin',
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
        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED, $manualFailure->fresh()->download_status);
        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED, $localFailure->fresh()->download_status);
        $this->assertSame('message-attachments/existing.bin', $localFailure->local_path);
    }

    public function test_manual_request_policy_hides_terminal_telegram_errors(): void
    {
        $policy = app(TelegramAccountMediaDownloadPolicy::class);
        $channel = new Channel([
            'telegram_account_media_on_demand_enabled' => true,
        ]);
        $channel->id = 10;
        $channel->exists = true;
        $attachment = new MessageAttachment([
            'channel_id' => 10,
            'provider' => MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT,
            'provider_file_id' => '42',
            'media_kind' => MessageAttachment::MEDIA_KIND_DOCUMENT,
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED,
            'safe_error_code' => 'tdlib_file_not_found',
        ]);
        $attachment->setRelation('channel', $channel);

        $this->assertFalse($policy->canRequestManually($attachment));

        $attachment->safe_error_code = 'telegram_file_not_found';

        $this->assertFalse($policy->canRequestManually($attachment));

        $attachment->safe_error_code = 'file_too_large';

        $this->assertTrue($policy->canRequestManually($attachment));

        $attachment->media_kind = MessageAttachment::MEDIA_KIND_ANIMATION;

        $this->assertTrue($policy->canRequestManually($attachment));

        $attachment->media_kind = MessageAttachment::MEDIA_KIND_DOCUMENT;
        $channel->telegram_account_media_on_demand_enabled = false;

        $this->assertFalse($policy->canRequestManually($attachment));

        $channel->telegram_account_media_on_demand_enabled = true;
        $attachment->provider_file_id = null;
        $attachment->provider_file_reference = '4242';

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

    public function test_replayed_event_preserves_deleted_local_state(): void
    {
        $channel = Channel::factory()->account()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'telegram_account_media_auto_download_max_bytes' => 100,
        ]);
        $message = Message::factory()->create([
            'channel_id' => $channel->id,
            'provider_event_key' => 'telegram-account:deleted-replay',
            'external_message_id' => 'deleted-replay-message',
        ]);
        $attachment = MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $channel->id,
            'provider' => MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT,
            'provider_event_key' => $message->provider_event_key,
            'provider_attachment_key' => 'deleted-replay-file',
            'provider_file_id' => 'deleted-replay-file',
            'file_size_bytes' => 11,
            'media_kind' => MessageAttachment::MEDIA_KIND_DOCUMENT,
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DELETED_LOCAL,
            'safe_error_code' => 'retention_deleted',
            'safe_error_message' => 'Локальная копия файла удалена по сроку хранения.',
        ]);

        app(SyncTelegramAccountInboundMessageAttachmentsAction::class)->handle(
            $message,
            new NormalizedInboundMessageEvent(
                schemaVersion: '1',
                gatewayEventId: 'deleted-replay-event',
                channelId: (int) $channel->id,
                platform: Channel::PLATFORM_TELEGRAM,
                connectionType: Channel::CONNECTION_TYPE_ACCOUNT,
                peerType: NormalizedInboundMessageEvent::PEER_TYPE_PRIVATE,
                peerKey: 'telegram_account:deleted-replay-peer',
                messageKey: (string) $message->provider_event_key,
                externalChatId: 'deleted-replay-chat',
                externalUserId: 'deleted-replay-user',
                externalMessageId: (string) $message->external_message_id,
                externalUsername: null,
                contactName: null,
                messageKind: 'media',
                text: null,
                media: [[
                    'provider_attachment_key' => 'deleted-replay-file',
                    'provider_file_id' => 'deleted-replay-file',
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

        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_DELETED_LOCAL, $attachment->download_status);
        $this->assertSame('retention_deleted', $attachment->safe_error_code);
        $this->assertSame('Локальная копия файла удалена по сроку хранения.', $attachment->safe_error_message);
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

    public function test_storage_quota_denies_account_claim_without_spending_attempt_or_creating_ledger(): void
    {
        config([
            'inbound_media.storage.enforce' => true,
            'inbound_media.storage.global_limit_bytes' => 50,
            'inbound_media.storage.channel_limit_bytes' => 1_000,
            'inbound_media.storage.minimum_free_bytes' => 0,
            'inbound_media.storage.minimum_free_percent' => 0,
            'inbound_media.traffic.enforce' => true,
            'inbound_media.traffic.channel_daily_limit_bytes' => 1_000,
        ]);
        $channel = Channel::factory()->account()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $dialog = Dialog::factory()->create([
            'channel_id' => $channel->id,
        ]);
        $message = Message::factory()->create([
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
        ]);
        $attachment = MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $channel->id,
            'provider' => MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT,
            'provider_file_id' => 'quota-denied-account-file',
            'file_size_bytes' => 100,
            'media_kind' => MessageAttachment::MEDIA_KIND_DOCUMENT,
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD,
            'media_download_max_bytes' => 1_000,
            'media_download_attempts' => 0,
        ]);

        $this->assertNull(app(ClaimTelegramAccountMediaDownloadAction::class)->handle($channel));

        $attachment->refresh();

        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_AVAILABLE_ON_DEMAND, $attachment->download_status);
        $this->assertSame(InboundMediaDownloadPolicy::REASON_STORAGE_QUOTA_EXCEEDED, $attachment->safe_error_code);
        $this->assertSame(0, $attachment->media_download_attempts);
        $this->assertNull($attachment->media_download_claim_token);
        $this->assertNull($attachment->media_download_claimed_at);
        $this->assertNull($attachment->media_download_heartbeat_at);
        $this->assertNull($attachment->media_download_attempt_deadline_at);
        $this->assertSame(0, MediaDownloadStorageLedger::query()->count());
        $this->assertSame(0, MediaDownloadTrafficLedger::query()->count());
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
            ->with('message-attachments/99/88.g1.claim-88.upload', Mockery::type(\DateTimeInterface::class))
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
        $this->assertSame(7200, $target['expires_in_seconds']);
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
        $temporaryPath = "message-attachments/{$message->id}/{$attachment->id}.g1.stale-cleanup-token.upload";
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

    public function test_direct_upload_is_copied_to_stable_object_and_temporary_object_is_deleted_after_commit(): void
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
            'media_download_claimed_at' => now(),
            'media_download_heartbeat_at' => now(),
            'media_download_attempt_deadline_at' => now()->addHour(),
            'media_download_attempts' => 1,
            'media_download_upload_size_bytes' => 7,
            'file_size_bytes' => 7,
        ]);
        $this->reserveQuotaForAttempt($attachment);
        $temporaryPath = "message-attachments/{$message->id}/{$attachment->id}.g1.s3-cleanup-token.upload";
        $stablePath = app(StoreMessageAttachmentLocalFileAction::class)->buildClaimedPath(
            $attachment,
            'pdf',
            's3-cleanup-token',
        );
        $stream = fopen('php://temp', 'w+b');
        $this->assertIsResource($stream);
        fwrite($stream, 'S3-DATA');
        rewind($stream);
        $candidatePath = null;

        $disk = Mockery::mock();
        $disk->shouldReceive('exists')->twice()->with($temporaryPath)->andReturn(true);
        $disk->shouldReceive('readStream')->once()->with($temporaryPath)->andReturn($stream);
        $disk->shouldReceive('size')->once()->with($temporaryPath)->andReturn(7);
        $disk->shouldReceive('copy')
            ->once()
            ->with($temporaryPath, Mockery::on(function (string $path) use ($stablePath, &$candidatePath): bool {
                $candidatePath = $path;

                return str_starts_with($path, $stablePath.'.commit.');
            }))
            ->andReturn(true);
        $disk->shouldReceive('exists')->once()->with($stablePath)->andReturn(false);
        $disk->shouldReceive('move')
            ->once()
            ->with(Mockery::on(function (string $path) use (&$candidatePath): bool {
                return $path === $candidatePath;
            }), $stablePath)
            ->andReturn(true);
        $disk->shouldReceive('delete')->once()->with($temporaryPath)->andReturn(true);
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
        $this->assertSame($stablePath, $stored->local_path);
        $this->assertNull($stored->media_download_upload_size_bytes);
        $this->assertNull($stored->media_download_claim_token);
        $this->assertNull($stored->media_download_claimed_at);
        $this->assertNull($stored->media_download_heartbeat_at);
        $this->assertNull($stored->media_download_attempt_deadline_at);
    }

    public function test_media_result_locks_dialog_before_attachment(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        $channel = Channel::factory()->account()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $dialog = Dialog::factory()->create([
            'channel_id' => $channel->id,
        ]);
        $message = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'channel_id' => $channel->id,
        ]);
        $attachment = MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $channel->id,
            'provider' => MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT,
            'provider_file_id' => 'lock-order-file',
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING,
            'media_download_claim_token' => 'lock-order-token',
            'media_download_claimed_at' => now(),
            'media_download_heartbeat_at' => now(),
            'media_download_attempt_deadline_at' => now()->addHour(),
        ]);
        $queries = [];

        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        app(StoreTelegramAccountMediaDownloadResultAction::class)->markAvailableOnDemand(
            $channel,
            $attachment,
            'lock-order-token',
        );

        $dialogQueryIndex = collect($queries)->search(
            static fn (string $sql): bool => str_contains($sql, 'from "dialogs"'),
        );
        $attachmentQueryIndex = collect($queries)->search(
            static fn (string $sql): bool => str_contains($sql, 'from "message_attachments"')
                && str_contains(mb_strtolower($sql), 'for update'),
        );
        $messageLockQueryIndex = collect($queries)->search(
            static fn (string $sql): bool => str_contains($sql, 'from "messages"')
                && str_contains(mb_strtolower($sql), 'for update'),
        );

        $this->assertIsInt($dialogQueryIndex);
        $this->assertIsInt($messageLockQueryIndex);
        $this->assertIsInt($attachmentQueryIndex);
        $this->assertLessThan($messageLockQueryIndex, $dialogQueryIndex);
        $this->assertLessThan($attachmentQueryIndex, $messageLockQueryIndex);
    }

    public function test_direct_upload_cleanup_failure_queues_durable_cleanup_without_failing_committed_result(): void
    {
        Queue::fake();

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
            'provider_file_id' => 'cleanup-retry-file',
            'original_filename' => 'cleanup-retry.pdf',
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING,
            'media_download_claim_token' => 'cleanup-retry-token',
            'media_download_claimed_at' => now(),
            'media_download_heartbeat_at' => now(),
            'media_download_attempt_deadline_at' => now()->addHour(),
            'media_download_attempts' => 1,
            'file_size_bytes' => 7,
        ]);
        $this->reserveQuotaForAttempt($attachment);
        $temporaryPath = "message-attachments/{$message->id}/{$attachment->id}.g1.cleanup-retry-token.upload";
        $stablePath = app(StoreMessageAttachmentLocalFileAction::class)->buildClaimedPath(
            $attachment,
            'pdf',
            'cleanup-retry-token',
        );
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, 'PDFDATA');
        rewind($stream);
        $candidatePath = null;

        $disk = Mockery::mock();
        Storage::shouldReceive('disk')
            ->with(MessageAttachment::LOCAL_DISK_PRIVATE)
            ->andReturn($disk);
        $disk->shouldReceive('exists')->twice()->with($temporaryPath)->andReturn(true);
        $disk->shouldReceive('readStream')->once()->with($temporaryPath)->andReturn($stream);
        $disk->shouldReceive('size')->once()->with($temporaryPath)->andReturn(7);
        $disk->shouldReceive('copy')
            ->once()
            ->with($temporaryPath, Mockery::on(function (string $path) use ($stablePath, &$candidatePath): bool {
                $candidatePath = $path;

                return str_starts_with($path, $stablePath.'.commit.');
            }))
            ->andReturn(true);
        $disk->shouldReceive('exists')->once()->with($stablePath)->andReturn(false);
        $disk->shouldReceive('move')
            ->once()
            ->with(Mockery::on(function (string $path) use (&$candidatePath): bool {
                return $path === $candidatePath;
            }), $stablePath)
            ->andReturn(true);
        $disk->shouldReceive('delete')->once()->with($temporaryPath)->andReturn(false);

        $action = app(StoreTelegramAccountMediaDownloadResultAction::class);
        $stored = $action->markDownloadedFromDirectUpload(
            $channel,
            $attachment,
            'cleanup-retry-token',
            [
                'original_filename' => 'cleanup-retry.pdf',
                'mime_type' => 'application/pdf',
                'file_size_bytes' => 7,
            ],
        );

        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED, $stored->download_status);
        $this->assertSame($stablePath, $stored->local_path);
        Queue::assertPushed(
            DeleteRolledBackInboundMediaFileJob::class,
            static fn (DeleteRolledBackInboundMediaFileJob $job): bool => $job->attachmentId === $attachment->id
                && $job->disk === MessageAttachment::LOCAL_DISK_PRIVATE
                && $job->path === $temporaryPath,
        );
    }

    public function test_failed_direct_upload_publication_queues_durable_candidate_cleanup(): void
    {
        Queue::fake();

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
            'provider_file_id' => 'candidate-cleanup-file',
            'original_filename' => 'candidate-cleanup.pdf',
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING,
            'media_download_claim_token' => 'candidate-cleanup-token',
            'media_download_claimed_at' => now(),
            'media_download_heartbeat_at' => now(),
            'media_download_attempt_deadline_at' => now()->addHour(),
            'media_download_attempts' => 1,
            'file_size_bytes' => 7,
        ]);
        $this->reserveQuotaForAttempt($attachment);
        $temporaryPath = "message-attachments/{$message->id}/{$attachment->id}.g1.candidate-cleanup-token.upload";
        $stablePath = app(StoreMessageAttachmentLocalFileAction::class)->buildClaimedPath(
            $attachment,
            'pdf',
            'candidate-cleanup-token',
        );
        $stream = fopen('php://temp', 'w+b');

        $this->assertIsResource($stream);
        fwrite($stream, 'PDFDATA');
        rewind($stream);

        $candidatePath = null;
        $disk = Mockery::mock();

        Storage::shouldReceive('disk')
            ->with(MessageAttachment::LOCAL_DISK_PRIVATE)
            ->andReturn($disk);
        $disk->shouldReceive('exists')->once()->with($temporaryPath)->andReturn(true);
        $disk->shouldReceive('readStream')->once()->with($temporaryPath)->andReturn($stream);
        $disk->shouldReceive('size')->once()->with($temporaryPath)->andReturn(7);
        $disk->shouldReceive('copy')
            ->once()
            ->with($temporaryPath, Mockery::on(function (string $path) use ($stablePath, &$candidatePath): bool {
                $candidatePath = $path;

                return str_starts_with($path, $stablePath.'.commit.');
            }))
            ->andReturn(true);
        $disk->shouldReceive('exists')->once()->with($stablePath)->andReturn(false);
        $disk->shouldReceive('move')
            ->once()
            ->with(Mockery::on(function (string $path) use (&$candidatePath): bool {
                return $path === $candidatePath;
            }), $stablePath)
            ->andReturn(false);
        $disk->shouldReceive('exists')
            ->once()
            ->with(Mockery::on(function (string $path) use (&$candidatePath): bool {
                return $path === $candidatePath;
            }))
            ->andReturn(true);
        $disk->shouldReceive('delete')
            ->once()
            ->with(Mockery::on(function (string $path) use (&$candidatePath): bool {
                return $path === $candidatePath;
            }))
            ->andReturn(false);

        try {
            app(StoreTelegramAccountMediaDownloadResultAction::class)->markDownloadedFromDirectUpload(
                $channel,
                $attachment,
                'candidate-cleanup-token',
                [
                    'original_filename' => 'candidate-cleanup.pdf',
                    'mime_type' => 'application/pdf',
                    'file_size_bytes' => 7,
                ],
            );

            $this->fail('Expected direct upload publication to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(
                'Direct media upload could not be finalized atomically.',
                $exception->getMessage(),
            );
        }

        $this->assertIsString($candidatePath);
        Queue::assertPushed(
            DeleteRolledBackInboundMediaFileJob::class,
            static fn (DeleteRolledBackInboundMediaFileJob $job): bool => $job->attachmentId === $attachment->id
                && $job->disk === MessageAttachment::LOCAL_DISK_PRIVATE
                && $job->path === $candidatePath,
        );
    }

    public function test_manual_request_uses_dialog_message_attachment_lock_order(): void
    {
        $channel = Channel::factory()->account()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'telegram_account_media_on_demand_enabled' => true,
        ]);
        $dialog = Dialog::factory()->create([
            'channel_id' => $channel->id,
        ]);
        $message = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'channel_id' => $channel->id,
        ]);
        $attachment = MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $channel->id,
            'provider' => MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT,
            'provider_file_id' => 'manual-lock-order-file',
            'media_kind' => MessageAttachment::MEDIA_KIND_DOCUMENT,
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_AVAILABLE_ON_DEMAND,
        ]);
        $operator = User::factory()->create();
        $queries = [];

        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        app(RequestTelegramAccountMediaDownloadAction::class)->handle($dialog, $attachment, $operator);

        $dialogQueryIndex = collect($queries)->search(
            static fn (string $sql): bool => str_contains($sql, 'from "dialogs"')
                && str_contains(mb_strtolower($sql), 'for update'),
        );
        $messageQueryIndex = collect($queries)->search(
            static fn (string $sql): bool => str_contains($sql, 'from "messages"')
                && str_contains(mb_strtolower($sql), 'for update'),
        );
        $attachmentQueryIndex = collect($queries)->search(
            static fn (string $sql): bool => str_contains($sql, 'from "message_attachments"')
                && str_contains(mb_strtolower($sql), 'for update'),
        );

        $this->assertIsInt($dialogQueryIndex);
        $this->assertIsInt($messageQueryIndex);
        $this->assertIsInt($attachmentQueryIndex);
        $this->assertLessThan($messageQueryIndex, $dialogQueryIndex);
        $this->assertLessThan($attachmentQueryIndex, $messageQueryIndex);
    }

    public function test_manual_request_after_policy_block_stays_in_generation_and_uses_new_lease_sequence(): void
    {
        config([
            'inbound_media.storage.enforce' => true,
            'inbound_media.storage.global_limit_bytes' => 10_000,
            'inbound_media.storage.channel_limit_bytes' => 10_000,
            'inbound_media.storage.minimum_free_bytes' => 0,
            'inbound_media.storage.minimum_free_percent' => 0,
            'inbound_media.traffic.enforce' => true,
            'inbound_media.traffic.channel_daily_limit_bytes' => 10_000,
        ]);
        $channel = Channel::factory()->account()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'telegram_account_media_on_demand_enabled' => true,
        ]);
        $dialog = Dialog::factory()->create(['channel_id' => $channel->id]);
        $message = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'channel_id' => $channel->id,
        ]);
        $attachment = MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $channel->id,
            'provider' => MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT,
            'provider_file_id' => 'manual-generation-file',
            'media_kind' => MessageAttachment::MEDIA_KIND_DOCUMENT,
            'file_size_bytes' => 100,
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING,
            'media_download_generation' => 1,
            'media_download_attempts' => 1,
            'media_download_lease_sequence' => 1,
            'media_download_max_bytes' => 1_000,
        ]);
        $quotaLedger = app(InboundMediaQuotaLedger::class);
        $this->assertTrue($quotaLedger->reserveForAttempt($attachment, 1)->allowed);
        $quotaLedger->failAttempt(
            $attachment,
            1,
            40,
            InboundMediaDownloadPolicy::REASON_TRAFFIC_QUOTA_EXCEEDED,
        );
        $attachment->forceFill([
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_AVAILABLE_ON_DEMAND,
            'media_download_attempts' => 0,
            'safe_error_code' => InboundMediaDownloadPolicy::REASON_TRAFFIC_QUOTA_EXCEEDED,
        ])->save();
        $operator = User::factory()->create();

        $requested = app(RequestTelegramAccountMediaDownloadAction::class)->handle(
            $dialog,
            $attachment,
            $operator,
        );

        $this->assertSame(1, $requested->media_download_generation);
        $this->assertSame(0, $requested->media_download_attempts);
        $this->assertSame(1, $requested->media_download_lease_sequence);
        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD, $requested->download_status);

        $claimed = app(ClaimTelegramAccountMediaDownloadAction::class)->handle($channel);

        $this->assertInstanceOf(MessageAttachment::class, $claimed);
        $this->assertSame(1, $claimed->media_download_generation);
        $this->assertSame(1, $claimed->media_download_attempts);
        $this->assertSame(2, $claimed->media_download_lease_sequence);
        $this->assertDatabaseHas('media_download_traffic_ledgers', [
            'message_attachment_id' => $attachment->id,
            'generation' => 1,
            'attempt_number' => 1,
            'status' => MediaDownloadTrafficLedger::STATUS_CONSUMED,
            'consumed_bytes' => 40,
        ]);
        $this->assertDatabaseHas('media_download_traffic_ledgers', [
            'message_attachment_id' => $attachment->id,
            'generation' => 1,
            'attempt_number' => 2,
            'status' => MediaDownloadTrafficLedger::STATUS_RESERVED,
        ]);
    }

    public function test_manual_download_is_disabled_while_storage_quota_is_exhausted(): void
    {
        config([
            'inbound_media.storage.enforce' => true,
            'inbound_media.storage.global_limit_bytes' => 50,
            'inbound_media.storage.channel_limit_bytes' => 1_000,
            'inbound_media.storage.minimum_free_bytes' => 0,
            'inbound_media.storage.minimum_free_percent' => 0,
        ]);
        $channel = Channel::factory()->account()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'telegram_account_media_on_demand_enabled' => true,
        ]);
        $message = Message::factory()->create(['channel_id' => $channel->id]);
        $attachment = MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $channel->id,
            'provider' => MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT,
            'provider_file_id' => 'manual-quota-file',
            'media_kind' => MessageAttachment::MEDIA_KIND_DOCUMENT,
            'file_size_bytes' => 100,
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_AVAILABLE_ON_DEMAND,
        ]);

        $availability = app(InboundMediaDownloadPolicy::class)->manualAvailability($attachment);

        $this->assertTrue($availability['visible']);
        $this->assertFalse($availability['allowed']);
        $this->assertSame('Недостаточно свободного места для загрузки файла.', $availability['reason']);
    }

    public function test_range_quota_preflight_reports_only_bytes_already_stored(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        config([
            'inbound_media.storage.enforce' => true,
            'inbound_media.storage.global_limit_bytes' => 10_000,
            'inbound_media.storage.channel_limit_bytes' => 10_000,
            'inbound_media.storage.minimum_free_bytes' => 0,
            'inbound_media.storage.minimum_free_percent' => 0,
            'inbound_media.traffic.enforce' => true,
            'inbound_media.traffic.channel_daily_limit_bytes' => 1_000,
        ]);
        $channel = Channel::factory()->account()->create(['platform' => Channel::PLATFORM_TELEGRAM]);
        $message = Message::factory()->create(['channel_id' => $channel->id]);
        $attachment = MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $channel->id,
            'provider' => MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT,
            'provider_file_id' => 'range-quota-file',
            'media_kind' => MessageAttachment::MEDIA_KIND_DOCUMENT,
            'file_size_bytes' => 100,
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING,
            'media_download_claim_token' => 'range-quota-token',
            'media_download_claimed_at' => now(),
            'media_download_heartbeat_at' => now(),
            'media_download_attempt_deadline_at' => now()->addHour(),
            'media_download_attempts' => 1,
            'media_download_max_bytes' => 1_000,
            'media_download_upload_size_bytes' => 100,
        ]);
        $quotaLedger = app(InboundMediaQuotaLedger::class);
        $this->assertTrue($quotaLedger->reserveForAttempt($attachment, 1)->allowed);
        DB::table('media_download_traffic_budgets')
            ->where('channel_id', $channel->id)
            ->update(['consumed_bytes' => 950]);
        $path = app(StoreMessageAttachmentLocalFileAction::class)
            ->buildDirectUploadPath($attachment, 'range-quota-token');
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->put($path, '0123456789');
        $stream = fopen('php://temp', 'w+b');
        $this->assertIsResource($stream);
        fwrite($stream, 'abcdefghij');
        rewind($stream);

        try {
            app(StoreTelegramAccountMediaDirectUploadAction::class)->handle(
                $channel,
                $attachment,
                'range-quota-token',
                $stream,
                'bytes 10-19/100',
                10,
            );
            $this->fail('Traffic quota must reject the next range before it is written.');
        } catch (InboundMediaQuotaExceededException $exception) {
            $this->assertSame(InboundMediaDownloadPolicy::REASON_TRAFFIC_QUOTA_EXCEEDED, $exception->reason);
            $this->assertSame(10, $exception->transferredBytes);
        } finally {
            fclose($stream);
        }

        $this->assertSame('0123456789', Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->get($path));
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

    private function reserveQuotaForAttempt(MessageAttachment $attachment): void
    {
        config()->set('inbound_media.storage.minimum_free_bytes', 0);
        config()->set('inbound_media.storage.minimum_free_percent', 0);

        $decision = app(InboundMediaQuotaLedger::class)->reserveForAttempt(
            $attachment,
            max(1, (int) $attachment->media_download_attempts),
        );

        $this->assertTrue($decision->allowed);
    }
}
