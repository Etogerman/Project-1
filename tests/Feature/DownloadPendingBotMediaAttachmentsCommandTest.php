<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\MediaDownloadStorageLedger;
use App\Models\MediaDownloadTrafficLedger;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Services\Bots\DownloadBotMessageAttachmentsAction;
use App\Services\Messages\InboundMediaDownloadPolicy;
use App\Services\Messages\StoreMessageAttachmentLocalFileAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DownloadPendingBotMediaAttachmentsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_dry_run_does_not_download_pending_bot_image(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        Http::fake();

        $attachment = $this->createPendingTelegramBotImageAttachment();

        $this->artisan('bot-media:download-pending-images')
            ->assertExitCode(0);

        Http::assertNothingSent();
        $attachment->refresh();

        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD, $attachment->download_status);
        $this->assertNull($attachment->local_path);
    }

    public function test_command_downloads_pending_bot_image_when_forced(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        Http::fake([
            'https://api.telegram.org/bottelegram-token/getFile*' => Http::response([
                'ok' => true,
                'result' => [
                    'file_path' => 'photos/local-preview.jpg',
                    'file_size' => strlen('telegram-image-bytes'),
                ],
            ]),
            'https://api.telegram.org/file/bottelegram-token/photos/local-preview.jpg' => Http::response(
                'telegram-image-bytes',
                200,
                []
            ),
        ]);

        $attachment = $this->createPendingTelegramBotImageAttachment();

        $this->artisan('bot-media:download-pending-images', [
            '--force' => true,
            '--limit' => 10,
        ])->assertExitCode(0);

        $attachment->refresh();

        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED, $attachment->download_status);
        $this->assertSame(MessageAttachment::LOCAL_DISK_PRIVATE, $attachment->local_disk);
        $this->assertSame('image/jpeg', $attachment->mime_type);
        $this->assertTrue($attachment->isInlinePreviewable());
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->assertExists((string) $attachment->local_path);
    }

    public function test_command_streams_telegram_bot_media_from_configured_local_api_files_root(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        $root = storage_path('framework/testing/telegram-local-bot-api');
        $filePath = $root.'/videos/local-large-video.mp4';
        File::deleteDirectory($root);
        File::ensureDirectoryExists(dirname($filePath));
        file_put_contents($filePath, 'telegram-local-api-video-bytes');
        config([
            'bots.telegram.local_api_media_download_enabled' => true,
            'bots.telegram.local_api_base_url' => 'http://telegram-bot-api:8081',
            'bots.telegram.local_api_files_root' => $root,
        ]);
        Http::fake([
            'http://telegram-bot-api:8081/bottelegram-token/getFile*' => Http::response([
                'ok' => true,
                'result' => [
                    'file_path' => $filePath,
                    'file_size' => filesize($filePath),
                ],
            ]),
        ]);

        try {
            $attachment = $this->createPendingTelegramBotVideoAttachment();

            $this->artisan('bot-media:download-pending-images', [
                '--force' => true,
                '--limit' => 10,
            ])->assertExitCode(0);

            $attachment->refresh();

            $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED, $attachment->download_status);
            $this->assertSame(strlen('telegram-local-api-video-bytes'), $attachment->file_size_bytes);
            $this->assertNull($attachment->media_download_claim_token);
            $this->assertNull($attachment->media_download_heartbeat_at);
            Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->assertExists((string) $attachment->local_path);
            Http::assertSentCount(1);
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_command_rejects_telegram_local_api_file_outside_configured_root(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        $root = storage_path('framework/testing/telegram-local-bot-api-root');
        $outsideRoot = storage_path('framework/testing/telegram-local-bot-api-outside');
        $filePath = $outsideRoot.'/private-video.mp4';
        File::deleteDirectory($root);
        File::deleteDirectory($outsideRoot);
        File::ensureDirectoryExists($root);
        File::ensureDirectoryExists($outsideRoot);
        file_put_contents($filePath, 'outside-root-video-bytes');
        config([
            'bots.telegram.local_api_media_download_enabled' => true,
            'bots.telegram.local_api_base_url' => 'http://telegram-bot-api:8081',
            'bots.telegram.local_api_files_root' => $root,
        ]);
        Http::fake([
            'http://telegram-bot-api:8081/bottelegram-token/getFile*' => Http::response([
                'ok' => true,
                'result' => [
                    'file_path' => $filePath,
                    'file_size' => filesize($filePath),
                ],
            ]),
        ]);

        try {
            $attachment = $this->createPendingTelegramBotVideoAttachment();

            $this->artisan('bot-media:download-pending-images', [
                '--force' => true,
                '--limit' => 10,
            ])->assertExitCode(1);

            $attachment->refresh();

            $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED, $attachment->download_status);
            $this->assertNull($attachment->local_path);
            $this->assertSame('bot_media_download_invalid_payload', $attachment->safe_error_code);
            Http::assertSentCount(1);
        } finally {
            File::deleteDirectory($root);
            File::deleteDirectory($outsideRoot);
        }
    }

    public function test_command_rejects_untrusted_telegram_local_api_host_before_sending_token(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        config([
            'bots.telegram.local_api_media_download_enabled' => true,
            'bots.telegram.local_api_base_url' => 'http://untrusted.example:8081',
            'bots.telegram.local_api_trusted_hosts' => ['telegram-bot-api'],
        ]);
        Http::fake();

        $attachment = $this->createPendingTelegramBotVideoAttachment();

        $this->artisan('bot-media:download-pending-images', [
            '--force' => true,
            '--limit' => 10,
        ])->assertExitCode(1);

        $attachment->refresh();

        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED, $attachment->download_status);
        $this->assertSame('bot_media_download_invalid_payload', $attachment->safe_error_code);
        Http::assertNothingSent();
    }

    public function test_command_rejects_truncated_telegram_bot_media_as_integrity_mismatch(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        Http::fake([
            'https://api.telegram.org/bottelegram-token/getFile*' => Http::response([
                'ok' => true,
                'result' => [
                    'file_path' => 'videos/truncated-video.mp4',
                    'file_size' => 100,
                ],
            ]),
            'https://api.telegram.org/file/bottelegram-token/videos/truncated-video.mp4' => Http::response(
                'truncated',
                200,
                [
                    'Content-Type' => 'video/mp4',
                    'Content-Length' => '100',
                ],
            ),
        ]);

        $attachment = $this->createPendingTelegramBotVideoAttachment();

        $this->artisan('bot-media:download-pending-images', [
            '--force' => true,
            '--limit' => 10,
        ])->assertExitCode(0);

        $attachment->refresh();

        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD, $attachment->download_status);
        $this->assertNull($attachment->local_path);
        $this->assertSame('integrity_mismatch', $attachment->safe_error_code);
        $this->assertNotNull($attachment->media_download_next_retry_at);
        $this->assertTrue($attachment->media_download_next_retry_at->isFuture());
    }

    public function test_command_rejects_telegram_bot_media_when_provider_size_differs_from_body(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        Http::fake([
            'https://api.telegram.org/bottelegram-token/getFile*' => Http::response([
                'ok' => true,
                'result' => [
                    'file_path' => 'videos/provider-size-mismatch.mp4',
                    'file_size' => 100,
                ],
            ]),
            'https://api.telegram.org/file/bottelegram-token/videos/provider-size-mismatch.mp4' => Http::response(
                'short-body',
                200,
                ['Content-Type' => 'video/mp4'],
            ),
        ]);

        $attachment = $this->createPendingTelegramBotVideoAttachment();

        $this->artisan('bot-media:download-pending-images', [
            '--force' => true,
            '--limit' => 10,
        ])->assertExitCode(0);

        $attachment->refresh();

        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD, $attachment->download_status);
        $this->assertSame('integrity_mismatch', $attachment->safe_error_code);
        $this->assertNull($attachment->local_path);
    }

    public function test_command_rejects_html_error_page_disguised_as_telegram_bot_video(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        $body = '<!doctype html><html><body>provider error</body></html>';
        Http::fake([
            'https://api.telegram.org/bottelegram-token/getFile*' => Http::response([
                'ok' => true,
                'result' => [
                    'file_path' => 'videos/error-page.mp4',
                    'file_size' => strlen($body),
                ],
            ]),
            'https://api.telegram.org/file/bottelegram-token/videos/error-page.mp4' => Http::response(
                $body,
                200,
                ['Content-Type' => 'video/mp4'],
            ),
        ]);

        $attachment = $this->createPendingTelegramBotVideoAttachment();

        $this->artisan('bot-media:download-pending-images', [
            '--force' => true,
            '--limit' => 10,
        ])->assertExitCode(0);

        $attachment->refresh();

        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD, $attachment->download_status);
        $this->assertSame('integrity_mismatch', $attachment->safe_error_code);
        $this->assertNull($attachment->local_path);
    }

    public function test_command_schedules_retry_for_temporary_provider_failure_and_skips_it_until_due(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        Http::fake([
            'https://api.telegram.org/bottelegram-token/getFile*' => Http::response([], 503),
        ]);

        $attachment = $this->createPendingTelegramBotVideoAttachment();

        $this->artisan('bot-media:download-pending-images', [
            '--force' => true,
            '--limit' => 10,
        ])->assertExitCode(0);

        $attachment->refresh();

        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD, $attachment->download_status);
        $this->assertSame(1, $attachment->media_download_attempts);
        $this->assertSame('provider_unavailable', $attachment->safe_error_code);
        $this->assertNotNull($attachment->media_download_next_retry_at);
        $this->assertTrue($attachment->media_download_next_retry_at->isFuture());
        Http::assertSentCount(1);

        $this->artisan('bot-media:download-pending-images', [
            '--force' => true,
            '--limit' => 10,
        ])->assertExitCode(0);

        $attachment->refresh();

        $this->assertSame(1, $attachment->media_download_attempts);
        Http::assertSentCount(1);
    }

    public function test_command_stops_after_fifth_temporary_provider_failure(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        Http::fake([
            'https://api.telegram.org/bottelegram-token/getFile*' => Http::response([], 503),
        ]);

        $attachment = $this->createPendingTelegramBotVideoAttachment();
        $attachment->forceFill([
            'media_download_attempts' => 4,
        ])->save();

        $this->artisan('bot-media:download-pending-images', [
            '--force' => true,
            '--limit' => 10,
        ])->assertExitCode(1);

        $attachment->refresh();

        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED, $attachment->download_status);
        $this->assertSame(5, $attachment->media_download_attempts);
        $this->assertSame('retries_exhausted', $attachment->safe_error_code);
        $this->assertNull($attachment->media_download_next_retry_at);
    }

    public function test_command_keeps_integrity_reason_after_fifth_truncated_download(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        Http::fake([
            'https://api.telegram.org/bottelegram-token/getFile*' => Http::response([
                'ok' => true,
                'result' => [
                    'file_path' => 'videos/truncated-final-video.mp4',
                    'file_size' => 100,
                ],
            ]),
            'https://api.telegram.org/file/bottelegram-token/videos/truncated-final-video.mp4' => Http::response(
                'truncated',
                200,
                [
                    'Content-Type' => 'video/mp4',
                    'Content-Length' => '100',
                ],
            ),
        ]);

        $attachment = $this->createPendingTelegramBotVideoAttachment();
        $attachment->forceFill([
            'media_download_attempts' => 4,
        ])->save();

        $this->artisan('bot-media:download-pending-images', [
            '--force' => true,
            '--limit' => 10,
        ])->assertExitCode(1);

        $attachment->refresh();

        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED, $attachment->download_status);
        $this->assertSame(5, $attachment->media_download_attempts);
        $this->assertSame('integrity_mismatch', $attachment->safe_error_code);
        $this->assertNull($attachment->media_download_next_retry_at);
    }

    public function test_command_stops_immediately_when_provider_reports_missing_source(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        Http::fake([
            'https://api.telegram.org/bottelegram-token/getFile*' => Http::response([], 404),
        ]);

        $attachment = $this->createPendingTelegramBotVideoAttachment();

        $this->artisan('bot-media:download-pending-images', [
            '--force' => true,
            '--limit' => 10,
        ])->assertExitCode(1);

        $attachment->refresh();

        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED, $attachment->download_status);
        $this->assertSame(1, $attachment->media_download_attempts);
        $this->assertSame('source_unavailable', $attachment->safe_error_code);
        $this->assertNull($attachment->media_download_next_retry_at);
    }

    public function test_first_authorization_failure_is_retried_even_after_previous_network_attempts(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        Http::fake([
            'https://api.telegram.org/bottelegram-token/getFile*' => Http::response([], 401),
        ]);

        $attachment = $this->createPendingTelegramBotVideoAttachment();
        $attachment->forceFill([
            'media_download_attempts' => 2,
            'safe_error_code' => 'network_error',
        ])->save();

        app(DownloadBotMessageAttachmentsAction::class)->handle(
            Message::query()->findOrFail($attachment->message_id),
            [$attachment->id],
        );

        $attachment->refresh();

        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD, $attachment->download_status);
        $this->assertSame(3, $attachment->media_download_attempts);
        $this->assertSame('provider_authorization_failed', $attachment->safe_error_code);
        $this->assertNotNull($attachment->media_download_next_retry_at);
    }

    public function test_second_consecutive_authorization_failure_is_terminal(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        Http::fake([
            'https://api.telegram.org/bottelegram-token/getFile*' => Http::response([], 403),
        ]);

        $attachment = $this->createPendingTelegramBotVideoAttachment();
        $attachment->forceFill([
            'media_download_attempts' => 3,
            'safe_error_code' => 'provider_authorization_failed',
        ])->save();

        app(DownloadBotMessageAttachmentsAction::class)->handle(
            Message::query()->findOrFail($attachment->message_id),
            [$attachment->id],
        );

        $attachment->refresh();

        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED, $attachment->download_status);
        $this->assertSame(4, $attachment->media_download_attempts);
        $this->assertSame('provider_authorization_failed', $attachment->safe_error_code);
        $this->assertNull($attachment->media_download_next_retry_at);
    }

    public function test_download_action_does_not_reset_attachment_downloaded_after_stale_relation_was_loaded(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        Http::fake();

        $attachment = $this->createPendingTelegramBotImageAttachment();
        $staleMessage = Message::query()
            ->with(['channel', 'attachments'])
            ->findOrFail($attachment->message_id);

        $storedAttachment = app(StoreMessageAttachmentLocalFileAction::class)
            ->handle($attachment, 'already-downloaded-image-bytes', 'jpg');
        $storedPath = $storedAttachment->local_path;

        app(DownloadBotMessageAttachmentsAction::class)->handle($staleMessage);

        Http::assertNothingSent();
        $storedAttachment->refresh();

        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED, $storedAttachment->download_status);
        $this->assertSame(MessageAttachment::LOCAL_DISK_PRIVATE, $storedAttachment->local_disk);
        $this->assertSame($storedPath, $storedAttachment->local_path);
        $this->assertNull($storedAttachment->safe_error_code);
        $this->assertNull($storedAttachment->safe_error_message);
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->assertExists((string) $storedAttachment->local_path);
    }

    public function test_storage_quota_denies_telegram_and_max_bot_before_transport_without_spending_attempt(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        Http::fake();
        config([
            'inbound_media.storage.enforce' => true,
            'inbound_media.storage.global_limit_bytes' => 50,
            'inbound_media.storage.channel_limit_bytes' => 1_000,
            'inbound_media.storage.minimum_free_bytes' => 0,
            'inbound_media.storage.minimum_free_percent' => 0,
            'inbound_media.traffic.enforce' => true,
            'inbound_media.traffic.channel_daily_limit_bytes' => 1_000,
        ]);

        $attachments = [
            $this->createPendingTelegramBotImageAttachment(),
            $this->createPendingMaxBotVideoAttachment(),
        ];

        foreach ($attachments as $attachment) {
            $attachment->forceFill([
                'file_size_bytes' => 100,
                'media_download_max_bytes' => 1_000,
            ])->save();

            app(DownloadBotMessageAttachmentsAction::class)->handle(
                Message::query()->findOrFail($attachment->message_id),
                [$attachment->id],
            );

            $attachment->refresh();

            $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_AVAILABLE_ON_DEMAND, $attachment->download_status);
            $this->assertSame(InboundMediaDownloadPolicy::REASON_STORAGE_QUOTA_EXCEEDED, $attachment->safe_error_code);
            $this->assertSame(0, $attachment->media_download_attempts);
            $this->assertNull($attachment->media_download_claim_token);
            $this->assertNull($attachment->media_download_claimed_at);
            $this->assertNull($attachment->media_download_heartbeat_at);
            $this->assertNull($attachment->media_download_attempt_deadline_at);
        }

        Http::assertNothingSent();
        $this->assertSame(0, MediaDownloadStorageLedger::query()->count());
        $this->assertSame(0, MediaDownloadTrafficLedger::query()->count());
    }

    public function test_runtime_traffic_quota_block_consumes_transferred_bytes_without_spending_technical_attempt(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        $body = 'fifteen-bytes!!';
        config([
            // This test isolates the traffic ledger; storage enforcement has its own coverage.
            'inbound_media.storage.enforce' => false,
            'inbound_media.storage.global_limit_bytes' => 10_000,
            'inbound_media.storage.channel_limit_bytes' => 10_000,
            'inbound_media.storage.minimum_free_bytes' => 0,
            'inbound_media.storage.minimum_free_percent' => 0,
            'inbound_media.traffic.enforce' => true,
            'inbound_media.traffic.channel_daily_limit_bytes' => 10,
        ]);
        Http::fake([
            'https://api.telegram.org/bottelegram-token/getFile*' => Http::response([
                'ok' => true,
                'result' => [
                    'file_path' => 'photos/runtime-quota.jpg',
                    'file_size' => strlen($body),
                ],
            ]),
            'https://api.telegram.org/file/bottelegram-token/photos/runtime-quota.jpg' => Http::response(
                $body,
                200,
                [
                    'Content-Type' => 'image/jpeg',
                    'Content-Length' => (string) strlen($body),
                ],
            ),
        ]);
        $attachment = $this->createPendingTelegramBotImageAttachment();
        $attachment->forceFill([
            'file_size_bytes' => null,
            'media_download_max_bytes' => 1_000,
        ])->save();

        app(DownloadBotMessageAttachmentsAction::class)->handle(
            Message::query()->findOrFail($attachment->message_id),
            [$attachment->id],
        );

        $attachment->refresh();

        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_AVAILABLE_ON_DEMAND, $attachment->download_status);
        $this->assertSame(InboundMediaDownloadPolicy::REASON_TRAFFIC_QUOTA_EXCEEDED, $attachment->safe_error_code);
        $this->assertSame(0, $attachment->media_download_attempts);
        $this->assertSame(1, $attachment->media_download_lease_sequence);
        $this->assertNull($attachment->media_download_claim_token);
        $this->assertDatabaseHas('media_download_traffic_ledgers', [
            'message_attachment_id' => $attachment->id,
            'generation' => 1,
            'attempt_number' => 1,
            'status' => MediaDownloadTrafficLedger::STATUS_CONSUMED,
            'consumed_bytes' => strlen($body),
            'release_reason' => InboundMediaDownloadPolicy::REASON_TRAFFIC_QUOTA_EXCEEDED,
        ]);
        $this->assertDatabaseHas('media_download_storage_ledgers', [
            'message_attachment_id' => $attachment->id,
            'generation' => 1,
            'status' => MediaDownloadStorageLedger::STATUS_RELEASED,
            'release_reason' => InboundMediaDownloadPolicy::REASON_TRAFFIC_QUOTA_EXCEEDED,
        ]);
    }

    public function test_command_downloads_pending_telegram_bot_voice_when_forced(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        Http::fake([
            'https://api.telegram.org/bottelegram-token/getFile*' => Http::response([
                'ok' => true,
                'result' => [
                    'file_path' => 'voice/local-voice.ogg',
                    'file_size' => strlen('telegram-voice-bytes'),
                ],
            ]),
            'https://api.telegram.org/file/bottelegram-token/voice/local-voice.ogg' => Http::response(
                'telegram-voice-bytes',
                200,
                []
            ),
        ]);

        $attachment = $this->createPendingTelegramBotVoiceAttachment();

        $this->artisan('bot-media:download-pending-images', [
            '--force' => true,
            '--limit' => 10,
        ])->assertExitCode(0);

        $attachment->refresh();

        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED, $attachment->download_status);
        $this->assertSame(MessageAttachment::LOCAL_DISK_PRIVATE, $attachment->local_disk);
        $this->assertSame('audio/ogg', $attachment->mime_type);
        $this->assertSame('ogg', $attachment->extension);
        $this->assertTrue($attachment->isInlinePreviewable());
        $this->assertSame(MessageAttachment::PREVIEW_KIND_AUDIO, $attachment->previewKind());
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->assertExists((string) $attachment->local_path);
    }

    public function test_command_downloads_pending_telegram_bot_audio_when_forced(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        Http::fake([
            'https://api.telegram.org/bottelegram-token/getFile*' => Http::response([
                'ok' => true,
                'result' => [
                    'file_path' => 'audio/local-track.mp3',
                    'file_size' => strlen('telegram-audio-bytes'),
                ],
            ]),
            'https://api.telegram.org/file/bottelegram-token/audio/local-track.mp3' => Http::response(
                'telegram-audio-bytes',
                200,
                []
            ),
        ]);

        $attachment = $this->createPendingTelegramBotAudioAttachment();

        $this->artisan('bot-media:download-pending-images', [
            '--force' => true,
            '--limit' => 10,
        ])->assertExitCode(0);

        $attachment->refresh();

        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED, $attachment->download_status);
        $this->assertSame(MessageAttachment::LOCAL_DISK_PRIVATE, $attachment->local_disk);
        $this->assertSame('audio/mpeg', $attachment->mime_type);
        $this->assertSame('mp3', $attachment->extension);
        $this->assertTrue($attachment->isInlinePreviewable());
        $this->assertSame(MessageAttachment::PREVIEW_KIND_AUDIO, $attachment->previewKind());
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->assertExists((string) $attachment->local_path);
    }

    public function test_command_downloads_pending_telegram_bot_video_when_forced(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        Http::fake([
            'https://api.telegram.org/bottelegram-token/getFile*' => Http::response([
                'ok' => true,
                'result' => [
                    'file_path' => 'videos/local-clip.mp4',
                    'file_size' => strlen('telegram-video-bytes'),
                ],
            ]),
            'https://api.telegram.org/file/bottelegram-token/videos/local-clip.mp4' => Http::response(
                'telegram-video-bytes',
                200,
                []
            ),
        ]);

        $attachment = $this->createPendingTelegramBotVideoAttachment();

        $this->artisan('bot-media:download-pending-images', [
            '--force' => true,
            '--limit' => 10,
        ])->assertExitCode(0);

        $attachment->refresh();

        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED, $attachment->download_status);
        $this->assertSame(MessageAttachment::LOCAL_DISK_PRIVATE, $attachment->local_disk);
        $this->assertSame('video/mp4', $attachment->mime_type);
        $this->assertSame('mp4', $attachment->extension);
        $this->assertTrue($attachment->isInlinePreviewable());
        $this->assertSame(MessageAttachment::PREVIEW_KIND_VIDEO, $attachment->previewKind());
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->assertExists((string) $attachment->local_path);
    }

    public function test_command_downloads_pending_telegram_bot_video_note_when_forced(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        Http::fake([
            'https://api.telegram.org/bottelegram-token/getFile*' => Http::response([
                'ok' => true,
                'result' => [
                    'file_path' => 'video_notes/local-round.mp4',
                    'file_size' => strlen('telegram-video-note-bytes'),
                ],
            ]),
            'https://api.telegram.org/file/bottelegram-token/video_notes/local-round.mp4' => Http::response(
                'telegram-video-note-bytes',
                200,
                []
            ),
        ]);

        $attachment = $this->createPendingTelegramBotVideoNoteAttachment();

        $this->artisan('bot-media:download-pending-images', [
            '--force' => true,
            '--limit' => 10,
        ])->assertExitCode(0);

        $attachment->refresh();

        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED, $attachment->download_status);
        $this->assertSame(MessageAttachment::LOCAL_DISK_PRIVATE, $attachment->local_disk);
        $this->assertSame('video/mp4', $attachment->mime_type);
        $this->assertSame('mp4', $attachment->extension);
        $this->assertTrue($attachment->isInlinePreviewable());
        $this->assertSame(MessageAttachment::PREVIEW_KIND_VIDEO, $attachment->previewKind());
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->assertExists((string) $attachment->local_path);
    }

    public function test_command_downloads_pending_telegram_bot_document_when_forced(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        Http::fake([
            'https://api.telegram.org/bottelegram-token/getFile*' => Http::response([
                'ok' => true,
                'result' => [
                    'file_path' => 'documents/local-offer.pdf',
                    'file_size' => strlen('%PDF-telegram-document-bytes'),
                ],
            ]),
            'https://api.telegram.org/file/bottelegram-token/documents/local-offer.pdf' => Http::response(
                '%PDF-telegram-document-bytes',
                200,
                []
            ),
        ]);

        $attachment = $this->createPendingTelegramBotDocumentAttachment();

        $this->artisan('bot-media:download-pending-images', [
            '--force' => true,
            '--limit' => 10,
        ])->assertExitCode(0);

        $attachment->refresh();

        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED, $attachment->download_status);
        $this->assertSame(MessageAttachment::LOCAL_DISK_PRIVATE, $attachment->local_disk);
        $this->assertSame('application/pdf', $attachment->mime_type);
        $this->assertSame('pdf', $attachment->extension);
        $this->assertFalse($attachment->isInlinePreviewable());
        $this->assertNull($attachment->previewKind());
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->assertExists((string) $attachment->local_path);
    }

    public function test_command_downloads_pending_telegram_bot_sticker_when_forced(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        Http::fake([
            'https://api.telegram.org/bottelegram-token/getFile*' => Http::response([
                'ok' => true,
                'result' => [
                    'file_path' => 'stickers/local-sticker.webp',
                    'file_size' => strlen('telegram-sticker-bytes'),
                ],
            ]),
            'https://api.telegram.org/file/bottelegram-token/stickers/local-sticker.webp' => Http::response(
                'telegram-sticker-bytes',
                200,
                ['Content-Type' => 'image/webp'],
            ),
        ]);

        $attachment = $this->createPendingTelegramBotStickerAttachment();

        $this->artisan('bot-media:download-pending-images', [
            '--force' => true,
            '--limit' => 10,
        ])->assertExitCode(0);

        $attachment->refresh();

        $this->assertSame(MessageAttachment::MEDIA_KIND_STICKER, $attachment->media_kind);
        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED, $attachment->download_status);
        $this->assertSame(MessageAttachment::LOCAL_DISK_PRIVATE, $attachment->local_disk);
        $this->assertSame('image/webp', $attachment->mime_type);
        $this->assertSame('webp', $attachment->extension);
        $this->assertTrue($attachment->isInlinePreviewable());
        $this->assertSame(MessageAttachment::PREVIEW_KIND_IMAGE, $attachment->previewKind());
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->assertExists((string) $attachment->local_path);
    }

    public function test_command_downloads_pending_telegram_bot_animation_when_forced(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        Http::fake([
            'https://api.telegram.org/bottelegram-token/getFile*' => Http::response([
                'ok' => true,
                'result' => [
                    'file_path' => 'animations/local-animation.mp4',
                    'file_size' => strlen('telegram-animation-bytes'),
                ],
            ]),
            'https://api.telegram.org/file/bottelegram-token/animations/local-animation.mp4' => Http::response(
                'telegram-animation-bytes',
                200,
                ['Content-Type' => 'video/mp4'],
            ),
        ]);

        $attachment = $this->createPendingTelegramBotAnimationAttachment();

        $this->artisan('bot-media:download-pending-images', [
            '--force' => true,
            '--limit' => 10,
        ])->assertExitCode(0);

        $attachment->refresh();

        $this->assertSame(MessageAttachment::MEDIA_KIND_ANIMATION, $attachment->media_kind);
        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED, $attachment->download_status);
        $this->assertSame(MessageAttachment::LOCAL_DISK_PRIVATE, $attachment->local_disk);
        $this->assertSame('video/mp4', $attachment->mime_type);
        $this->assertSame('mp4', $attachment->extension);
        $this->assertTrue($attachment->isInlinePreviewable());
        $this->assertSame(MessageAttachment::PREVIEW_KIND_VIDEO, $attachment->previewKind());
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->assertExists((string) $attachment->local_path);
    }

    public function test_command_downloads_pending_max_bot_video_when_forced(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        config()->set('bots.max.trusted_media_hosts', ['max.example']);
        Http::fake([
            'https://platform-api.max.ru/videos/max-command-video-token' => Http::response([
                'token' => 'max-command-video-token',
                'urls' => [
                    'mp4_720' => 'https://max.example/private/command-video-720.mp4?access_token=derived-secret',
                ],
                'width' => 1280,
                'height' => 720,
                'duration' => 14,
            ]),
            'https://max.example/private/command-video-720.mp4*' => Http::response(
                'max-command-video-bytes',
                200,
                ['Content-Type' => 'video/mp4'],
            ),
        ]);

        $attachment = $this->createPendingMaxBotVideoAttachment();

        $this->artisan('bot-media:download-pending-images', [
            '--force' => true,
            '--limit' => 10,
        ])->assertExitCode(0);

        $attachment->refresh();

        $this->assertSame(MessageAttachment::MEDIA_KIND_VIDEO, $attachment->media_kind);
        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED, $attachment->download_status);
        $this->assertSame(MessageAttachment::LOCAL_DISK_PRIVATE, $attachment->local_disk);
        $this->assertSame('video/mp4', $attachment->mime_type);
        $this->assertSame('mp4', $attachment->extension);
        $this->assertSame(1280, data_get($attachment->provider_metadata, 'width'));
        $this->assertSame(720, data_get($attachment->provider_metadata, 'height'));
        $this->assertSame(14, data_get($attachment->provider_metadata, 'duration'));
        $this->assertNull(data_get($attachment->provider_metadata, 'is_video_note'));
        $this->assertTrue($attachment->isInlinePreviewable());
        $this->assertSame(MessageAttachment::PREVIEW_KIND_VIDEO, $attachment->previewKind());
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->assertExists((string) $attachment->local_path);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://platform-api.max.ru/videos/max-command-video-token');
        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://max.example/private/command-video-720.mp4?'));
    }

    public function test_command_falls_back_to_matching_max_webhook_url_while_video_api_is_not_ready(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        config()->set('bots.max.trusted_media_hosts', ['max.example']);
        Http::fake([
            'https://platform-api.max.ru/videos/max-command-video-token' => Http::response([
                'token' => 'max-command-video-token',
                'urls' => [],
                'width' => 1280,
                'height' => 720,
                'duration' => 14,
            ]),
            'https://max.example/private/payload-video.mp4*' => Http::response(
                'max-webhook-video-bytes',
                200,
                ['Content-Type' => 'video/mp4'],
            ),
        ]);

        $attachment = $this->createPendingMaxBotVideoAttachment();

        $this->artisan('bot-media:download-pending-images', [
            '--force' => true,
            '--limit' => 10,
        ])->assertExitCode(0);

        $attachment->refresh();

        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED, $attachment->download_status);
        $this->assertSame(1280, data_get($attachment->provider_metadata, 'width'));
        $this->assertSame(720, data_get($attachment->provider_metadata, 'height'));
        $this->assertSame(14, data_get($attachment->provider_metadata, 'duration'));
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->assertExists((string) $attachment->local_path);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://platform-api.max.ru/videos/max-command-video-token');
        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://max.example/private/payload-video.mp4?'));
    }

    public function test_command_rejects_untrusted_max_webhook_fallback_url_before_downloading_media(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        config()->set('bots.max.trusted_media_hosts', ['max.example']);
        Http::fake([
            'https://platform-api.max.ru/videos/max-command-video-token' => Http::response([
                'token' => 'max-command-video-token',
                'urls' => [],
            ]),
        ]);

        $attachment = $this->createPendingMaxBotVideoAttachment(
            payloadUrl: 'https://evil.example/private/payload-video.mp4?access_token=secret-token',
        );

        $this->artisan('bot-media:download-pending-images', [
            '--force' => true,
            '--limit' => 10,
        ])->assertExitCode(1);

        $attachment->refresh();

        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED, $attachment->download_status);
        $this->assertSame('bot_media_download_invalid_payload', $attachment->safe_error_code);
        $this->assertNull($attachment->local_disk);
        $this->assertNull($attachment->local_path);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://platform-api.max.ru/videos/max-command-video-token');
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'evil.example'));
    }

    public function test_command_retries_max_video_while_provider_url_is_not_ready(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        Http::fake([
            'https://platform-api.max.ru/videos/max-command-video-token' => Http::response([
                'token' => 'max-command-video-token',
                'urls' => [],
            ]),
        ]);

        $attachment = $this->createPendingMaxBotVideoAttachment(payloadUrl: null);

        $this->artisan('bot-media:download-pending-images', [
            '--force' => true,
            '--limit' => 10,
        ])->assertExitCode(0);

        $attachment->refresh();

        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD, $attachment->download_status);
        $this->assertSame(1, $attachment->media_download_attempts);
        $this->assertSame('temporary_failure', $attachment->safe_error_code);
        $this->assertNotNull($attachment->media_download_next_retry_at);
        Http::assertSentCount(1);
    }

    public function test_exhausted_max_video_processing_failure_remains_available_for_manual_retry(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        Http::fake([
            'https://platform-api.max.ru/videos/max-command-video-token' => Http::response([
                'token' => 'max-command-video-token',
                'urls' => [],
            ]),
        ]);

        $attachment = $this->createPendingMaxBotVideoAttachment(payloadUrl: null);
        $attachment->forceFill(['media_download_attempts' => 4])->save();

        $this->artisan('bot-media:download-pending-images', [
            '--force' => true,
            '--limit' => 10,
        ])->assertExitCode(1);

        $attachment->refresh();
        $availability = app(InboundMediaDownloadPolicy::class)->manualAvailability($attachment);

        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED, $attachment->download_status);
        $this->assertSame(5, $attachment->media_download_attempts);
        $this->assertSame('retries_exhausted', $attachment->safe_error_code);
        $this->assertTrue($availability['visible']);
        $this->assertTrue($availability['allowed']);
    }

    public function test_command_downloads_forwarded_max_bot_image_from_nested_body_attachments(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        config()->set('bots.max.trusted_media_hosts', ['max.example']);
        Http::fake([
            'https://max.example/private/forwarded-image.jpg*' => Http::response(
                "\xFF\xD8\xFF".'max-forwarded-image-bytes',
                200,
                ['Content-Type' => 'image/jpeg'],
            ),
        ]);

        $attachment = $this->createPendingForwardedMaxBotImageAttachment();

        $this->artisan('bot-media:download-pending-images', [
            '--force' => true,
            '--limit' => 10,
        ])->assertExitCode(0);

        $attachment->refresh();

        $this->assertSame(MessageAttachment::MEDIA_KIND_IMAGE, $attachment->media_kind);
        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED, $attachment->download_status);
        $this->assertSame(MessageAttachment::LOCAL_DISK_PRIVATE, $attachment->local_disk);
        $this->assertSame('image/jpeg', $attachment->mime_type);
        $this->assertSame('jpg', $attachment->extension);
        $this->assertTrue($attachment->isInlinePreviewable());
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->assertExists((string) $attachment->local_path);
        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://max.example/private/forwarded-image.jpg?'));
    }

    public function test_command_marks_square_short_max_bot_video_as_video_note_when_downloaded(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        config()->set('bots.max.trusted_media_hosts', ['max.example']);
        Http::fake([
            'https://platform-api.max.ru/videos/max-round-video-token' => Http::response([
                'token' => 'max-round-video-token',
                'urls' => [
                    'mp4_480' => 'https://max.example/private/round-video-480.mp4?access_token=derived-secret',
                ],
                'width' => 480,
                'height' => 480,
                'duration' => 21000,
            ]),
            'https://max.example/private/round-video-480.mp4*' => Http::response(
                'max-round-video-bytes',
                200,
                ['Content-Type' => 'video/mp4'],
            ),
        ]);

        $attachment = $this->createPendingMaxBotVideoAttachment(videoToken: 'max-round-video-token');

        $this->artisan('bot-media:download-pending-images', [
            '--force' => true,
            '--limit' => 10,
        ])->assertExitCode(0);

        $attachment->refresh();

        $this->assertSame(MessageAttachment::MEDIA_KIND_VIDEO_NOTE, $attachment->media_kind);
        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED, $attachment->download_status);
        $this->assertSame(MessageAttachment::LOCAL_DISK_PRIVATE, $attachment->local_disk);
        $this->assertSame('video/mp4', $attachment->mime_type);
        $this->assertSame('mp4', $attachment->extension);
        $this->assertSame(480, data_get($attachment->provider_metadata, 'width'));
        $this->assertSame(480, data_get($attachment->provider_metadata, 'height'));
        $this->assertSame(21, data_get($attachment->provider_metadata, 'duration'));
        $this->assertTrue(data_get($attachment->provider_metadata, 'is_video_note'));
        $this->assertSame(MessageAttachment::MEDIA_KIND_VIDEO_NOTE, data_get($attachment->raw_payload_excerpt, 'media_kind'));
        $this->assertTrue(data_get($attachment->raw_payload_excerpt, 'is_video_note'));
        $this->assertTrue($attachment->isInlinePreviewable());
        $this->assertSame(MessageAttachment::PREVIEW_KIND_VIDEO, $attachment->previewKind());
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->assertExists((string) $attachment->local_path);
    }

    public function test_command_rejects_max_bot_media_when_content_length_exceeds_limit(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        config()->set('bots.media.download_max_bytes', 10);
        config()->set('bots.max.trusted_media_hosts', ['max.example']);
        Http::fake([
            'https://platform-api.max.ru/videos/max-command-video-token' => Http::response([
                'token' => 'max-command-video-token',
                'urls' => [
                    'mp4_720' => 'https://max.example/private/command-video-720.mp4?access_token=derived-secret',
                ],
                'width' => 1280,
                'height' => 720,
                'duration' => 14,
            ]),
            'https://max.example/private/command-video-720.mp4*' => Http::response(
                'too-large-body',
                200,
                [
                    'Content-Type' => 'video/mp4',
                    'Content-Length' => '11',
                ],
            ),
        ]);

        $attachment = $this->createPendingMaxBotVideoAttachment();

        $this->artisan('bot-media:download-pending-images', [
            '--force' => true,
            '--limit' => 10,
        ])->assertExitCode(1);

        $attachment->refresh();

        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED, $attachment->download_status);
        $this->assertNull($attachment->local_disk);
        $this->assertNull($attachment->local_path);
        $this->assertSame('bot_media_download_invalid_payload', $attachment->safe_error_code);
        $this->assertSame('Не удалось скачать медиафайл из MAX.', $attachment->safe_error_message);
    }

    public function test_command_downloads_max_bot_sticker_from_message_lookup_when_webhook_has_stub_url(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        config()->set('bots.max.trusted_media_hosts', ['mycdn.me', 'oneme.ru']);
        Http::fake([
            'https://platform-api.max.ru/messages/max-sticker-message-1' => Http::response([
                'body' => [
                    'mid' => 'max-sticker-message-1',
                    'attachments' => [
                        [
                            'type' => 'sticker',
                            'width' => 170,
                            'height' => 170,
                            'payload' => [
                                'code' => '429b5',
                                'url' => 'https://i.oneme.ru/getSmile?smileId=429b5&smileType=4',
                            ],
                        ],
                    ],
                ],
            ], 200),
            'https://i.oneme.ru/getSmile?smileId=429b5&smileType=4' => Http::response(
                "\x89PNG\r\n\x1A\n".'max-sticker-bytes',
                200,
                ['Content-Type' => 'image/png'],
            ),
            'https://st.mycdn.me/static/messages/res/images/stub/sticker_31856a27@2x.png' => Http::response(
                "\x89PNG\r\n\x1A\n".'max-sticker-stub-bytes',
                200,
                ['Content-Type' => 'image/png'],
            ),
        ]);

        $attachment = $this->createPendingMaxBotStickerAttachment();

        $this->artisan('bot-media:download-pending-images', [
            '--force' => true,
            '--limit' => 10,
        ])->assertExitCode(0);

        $attachment->refresh();

        $this->assertSame(MessageAttachment::MEDIA_KIND_STICKER, $attachment->media_kind);
        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED, $attachment->download_status);
        $this->assertSame(MessageAttachment::LOCAL_DISK_PRIVATE, $attachment->local_disk);
        $this->assertSame('image/png', $attachment->mime_type);
        $this->assertSame('png', $attachment->extension);
        $this->assertSame(170, data_get($attachment->provider_metadata, 'width'));
        $this->assertSame(170, data_get($attachment->provider_metadata, 'height'));
        $this->assertTrue($attachment->isInlinePreviewable());
        $this->assertSame(MessageAttachment::PREVIEW_KIND_IMAGE, $attachment->previewKind());
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->assertExists((string) $attachment->local_path);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://platform-api.max.ru/messages/max-sticker-message-1');
        Http::assertSent(fn ($request): bool => $request->url() === 'https://i.oneme.ru/getSmile?smileId=429b5&smileType=4');
        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://st.mycdn.me/static/messages/res/images/stub/sticker_31856a27@2x.png');
    }

    private function createPendingTelegramBotImageAttachment(): MessageAttachment
    {
        return $this->createPendingTelegramBotAttachment(
            providerAttachmentKey: 'telegram-photo-unique',
            mediaKind: MessageAttachment::MEDIA_KIND_IMAGE,
            providerFileId: 'telegram-photo-file-id',
            providerFileUniqueId: 'telegram-photo-unique',
        );
    }

    private function createPendingTelegramBotVoiceAttachment(): MessageAttachment
    {
        return $this->createPendingTelegramBotAttachment(
            providerAttachmentKey: 'telegram-voice-unique',
            mediaKind: MessageAttachment::MEDIA_KIND_VOICE,
            providerFileId: 'telegram-voice-file-id',
            providerFileUniqueId: 'telegram-voice-unique',
            mimeType: 'audio/ogg',
            extension: 'ogg',
        );
    }

    private function createPendingTelegramBotAudioAttachment(): MessageAttachment
    {
        return $this->createPendingTelegramBotAttachment(
            providerAttachmentKey: 'telegram-audio-unique',
            mediaKind: MessageAttachment::MEDIA_KIND_AUDIO,
            providerFileId: 'telegram-audio-file-id',
            providerFileUniqueId: 'telegram-audio-unique',
            mimeType: 'audio/mpeg',
            extension: 'mp3',
        );
    }

    private function createPendingTelegramBotVideoAttachment(): MessageAttachment
    {
        return $this->createPendingTelegramBotAttachment(
            providerAttachmentKey: 'telegram-video-unique',
            mediaKind: MessageAttachment::MEDIA_KIND_VIDEO,
            providerFileId: 'telegram-video-file-id',
            providerFileUniqueId: 'telegram-video-unique',
            mimeType: 'video/mp4',
            extension: 'mp4',
        );
    }

    private function createPendingTelegramBotVideoNoteAttachment(): MessageAttachment
    {
        return $this->createPendingTelegramBotAttachment(
            providerAttachmentKey: 'telegram-video-note-unique',
            mediaKind: MessageAttachment::MEDIA_KIND_VIDEO_NOTE,
            providerFileId: 'telegram-video-note-file-id',
            providerFileUniqueId: 'telegram-video-note-unique',
            extension: 'mp4',
        );
    }

    private function createPendingTelegramBotDocumentAttachment(): MessageAttachment
    {
        return $this->createPendingTelegramBotAttachment(
            providerAttachmentKey: 'telegram-document-unique',
            mediaKind: MessageAttachment::MEDIA_KIND_DOCUMENT,
            providerFileId: 'telegram-document-file-id',
            providerFileUniqueId: 'telegram-document-unique',
            mimeType: 'application/pdf',
            extension: 'pdf',
        );
    }

    private function createPendingTelegramBotStickerAttachment(): MessageAttachment
    {
        return $this->createPendingTelegramBotAttachment(
            providerAttachmentKey: 'telegram-sticker-unique',
            mediaKind: MessageAttachment::MEDIA_KIND_STICKER,
            providerFileId: 'telegram-sticker-file-id',
            providerFileUniqueId: 'telegram-sticker-unique',
            mimeType: 'image/webp',
            extension: 'webp',
        );
    }

    private function createPendingTelegramBotAnimationAttachment(): MessageAttachment
    {
        return $this->createPendingTelegramBotAttachment(
            providerAttachmentKey: 'telegram-animation-unique',
            mediaKind: MessageAttachment::MEDIA_KIND_ANIMATION,
            providerFileId: 'telegram-animation-file-id',
            providerFileUniqueId: 'telegram-animation-unique',
            mimeType: 'video/mp4',
            extension: 'mp4',
        );
    }

    private function createPendingTelegramBotAttachment(
        string $providerAttachmentKey,
        string $mediaKind,
        string $providerFileId,
        string $providerFileUniqueId,
        ?string $mimeType = null,
        ?string $extension = null,
    ): MessageAttachment {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
            ],
        ]);
        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $message = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'provider_event_key' => 'telegram-update-1',
            'external_message_id' => 'telegram-message-1',
        ]);

        return MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $channel->id,
            'provider' => MessageAttachment::PROVIDER_TELEGRAM_BOT,
            'provider_event_key' => 'telegram-update-1',
            'provider_attachment_key' => $providerAttachmentKey,
            'media_kind' => $mediaKind,
            'mime_type' => $mimeType,
            'extension' => $extension,
            'original_filename' => null,
            'provider_file_id' => $providerFileId,
            'provider_file_unique_id' => $providerFileUniqueId,
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD,
            'local_disk' => null,
            'local_path' => null,
        ]);
    }

    private function createPendingMaxBotVideoAttachment(
        string $videoToken = 'max-command-video-token',
        ?string $payloadUrl = 'https://max.example/private/payload-video.mp4?access_token=secret-token',
    ): MessageAttachment {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'inbound_media_on_demand_enabled' => true,
            'credentials' => [
                'token' => 'max-token',
            ],
        ]);
        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => Channel::PLATFORM_MAX,
        ]);
        $message = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'provider_event_key' => 'max-command-message-1',
            'external_message_id' => 'max-command-message-1',
            'raw_payload' => [
                'message' => [
                    'body' => [
                        'attachments' => [
                            [
                                'type' => 'video',
                                'payload' => array_filter([
                                    'token' => $videoToken,
                                    'url' => $payloadUrl,
                                ], static fn (mixed $value): bool => $value !== null),
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        return MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $channel->id,
            'provider' => MessageAttachment::PROVIDER_MAX_BOT,
            'provider_event_key' => 'max-command-message-1',
            'provider_attachment_key' => 'token:'.sha1($videoToken),
            'provider_file_reference' => 'token:'.sha1($videoToken),
            'media_kind' => MessageAttachment::MEDIA_KIND_VIDEO,
            'mime_type' => null,
            'extension' => null,
            'original_filename' => null,
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD,
            'local_disk' => null,
            'local_path' => null,
        ]);
    }

    private function createPendingForwardedMaxBotImageAttachment(): MessageAttachment
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
            ],
        ]);
        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => Channel::PLATFORM_MAX,
        ]);
        $message = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'provider_event_key' => 'max-forwarded-message-1',
            'external_message_id' => 'max-forwarded-message-1',
            'raw_payload' => [
                'message' => [
                    'link' => [
                        'type' => 'forward',
                        'message' => [
                            'body' => [
                                'attachments' => [
                                    [
                                        'type' => 'image',
                                        'payload' => [
                                            'photo_id' => 'max-forwarded-photo-1',
                                            'url' => 'https://max.example/private/forwarded-image.jpg?access_token=secret-token',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        return MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $channel->id,
            'provider' => MessageAttachment::PROVIDER_MAX_BOT,
            'provider_event_key' => 'max-forwarded-message-1',
            'provider_attachment_key' => 'max-forwarded-photo-1',
            'provider_file_reference' => 'max-forwarded-photo-1',
            'media_kind' => MessageAttachment::MEDIA_KIND_IMAGE,
            'mime_type' => null,
            'extension' => null,
            'original_filename' => null,
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD,
            'local_disk' => null,
            'local_path' => null,
        ]);
    }

    private function createPendingMaxBotStickerAttachment(): MessageAttachment
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
            ],
        ]);
        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => Channel::PLATFORM_MAX,
        ]);
        $message = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'provider_event_key' => 'max-sticker-message-1',
            'external_message_id' => 'max-sticker-message-1',
            'raw_payload' => [
                'message' => [
                    'body' => [
                        'attachments' => [
                            [
                                'type' => 'sticker',
                                'width' => 144,
                                'height' => 144,
                                'payload' => [
                                    'code' => '429b5',
                                    'url' => 'https://st.mycdn.me/static/messages/res/images/stub/sticker_31856a27@2x.png',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        return MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $channel->id,
            'provider' => MessageAttachment::PROVIDER_MAX_BOT,
            'provider_event_key' => 'max-sticker-message-1',
            'provider_attachment_key' => '429b5',
            'provider_file_reference' => '429b5',
            'media_kind' => MessageAttachment::MEDIA_KIND_STICKER,
            'mime_type' => null,
            'extension' => null,
            'original_filename' => null,
            'provider_metadata' => [
                'width' => 144,
                'height' => 144,
            ],
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD,
            'local_disk' => null,
            'local_path' => null,
        ]);
    }
}
