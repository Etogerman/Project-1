<?php

namespace Tests\Feature;

use App\Data\Bots\StoredInboundMessageResult;
use App\Jobs\ExportMessageToBitrix24OpenLinesJob;
use App\Jobs\ProcessAutoReplyJob;
use App\Jobs\ProcessDataCollectionQuestionJob;
use App\Jobs\ProcessDataCollectionResponseJob;
use App\Jobs\ProcessDeferredParameterAutoReplyJob;
use App\Jobs\ProcessPhoneCaptureFollowUpJob;
use App\Jobs\ProcessScenarioInboundJob;
use App\Jobs\ProcessScenarioStartJob;
use App\Models\Bitrix24MessageExport;
use App\Models\Channel;
use App\Models\ChannelActivityLog;
use App\Models\Contact;
use App\Models\ContactDuplicateReview;
use App\Models\ContactIdentity;
use App\Models\ContactPhoneNumber;
use App\Models\Dialog;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\MessageRevision;
use App\Models\Scenario;
use App\Models\ScenarioChannelBinding;
use App\Models\ScenarioRun;
use App\Models\ScenarioVersion;
use App\Services\Scenarios\DispatchStoredInboundScenarioAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class BotWebhookAutoReplyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('bots.legacy_auto_reply_rules_enabled', true);
    }

    public function test_user_facing_bot_jobs_use_bot_replies_queue(): void
    {
        config()->set('bots.auto_reply_queue', 'bot-replies');
        config()->set('bots.scenario_queue', 'bot-replies');

        $this->assertSame('bot-replies', (new ProcessAutoReplyJob(1))->queue);
        $this->assertSame('bot-replies', (new ProcessPhoneCaptureFollowUpJob(1))->queue);
        $this->assertSame('bot-replies', (new ProcessDeferredParameterAutoReplyJob(1))->queue);
        $this->assertSame('bot-replies', (new ProcessDataCollectionQuestionJob(1))->queue);
        $this->assertSame('bot-replies', (new ProcessDataCollectionResponseJob(1))->queue);
        $this->assertSame('bot-replies', (new ProcessScenarioStartJob(1, 1, '__scenario_constructor_workspace'))->queue);
        $this->assertSame('bot-replies', (new ProcessScenarioInboundJob(1, 1))->queue);
    }

    public function test_telegram_webhook_endpoint_accepts_valid_event_and_queues_auto_reply(): void
    {
        Queue::fake();
        Http::fake();
        config()->set('app.url', 'https://connector.example');

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
            'connection_status' => Channel::CONNECTION_STATUS_NOT_CONNECTED,
            'webhook_status' => Channel::WEBHOOK_STATUS_NOT_INSTALLED,
            'connection_checked_at' => now()->subMinutes(5),
            'connection_error_message' => Channel::CONNECTION_ERROR_STALE,
            'provider_webhook_url' => 'https://old-admin.example/webhooks/telegram/1',
            'expected_webhook_url' => 'https://old-admin.example/webhooks/telegram/1',
        ]);

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload());

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        Http::assertNothingSent();

        $inboundMessage = $this->inboundMessages()->firstOrFail();

        Queue::assertPushed(ProcessAutoReplyJob::class, function (ProcessAutoReplyJob $job) use ($inboundMessage): bool {
            return $job->inboundMessageId === $inboundMessage->id
                && $job->queue === ProcessAutoReplyJob::queueName();
        });

        $channel->refresh();

        $this->assertNotNull($channel->last_webhook_received_at);
        $this->assertSame(Channel::CONNECTION_STATUS_CONNECTED, $channel->connection_status);
        $this->assertSame(Channel::WEBHOOK_STATUS_INSTALLED, $channel->webhook_status);
        $this->assertNull($channel->connection_error_message);
        $this->assertNotNull($channel->connection_checked_at);
        $this->assertSame("https://connector.example/webhooks/telegram/{$channel->id}", $channel->expected_webhook_url);
        $this->assertSame("https://connector.example/webhooks/telegram/{$channel->id}", $channel->provider_webhook_url);
        $this->assertNull($channel->last_reply_sent_at);
        $this->assertNull($channel->last_error_at);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'webhook.received',
        ]);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'bot.reply_queued',
        ]);
        $this->assertDatabaseMissing('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'webhook.max_unhandled_payload',
        ]);
        $this->assertDatabaseCount('contacts', 1);
        $this->assertDatabaseCount('contact_identities', 1);
        $this->assertDatabaseCount('messages', 1);
        $this->assertMessageDirectionCount(Message::DIRECTION_INBOUND, 1);
        $this->assertMessageDirectionCount(Message::DIRECTION_OUTBOUND, 0);
        $this->assertDatabaseHas('contact_identities', [
            'channel_id' => $channel->id,
            'platform' => Channel::PLATFORM_TELEGRAM,
            'external_user_id' => '200',
            'external_username' => 'telegram_user',
        ]);
        $this->assertDatabaseHas('messages', [
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => '300',
            'external_message_id' => '10',
            'text' => 'hello',
            'message_parameter' => null,
        ]);
        $this->assertSame('10', $inboundMessage->provider_event_key);
        $this->assertNull($inboundMessage->auto_reply_sent_at);
    }

    public function test_telegram_media_group_webhook_queues_auto_reply_only_for_first_group_message(): void
    {
        Queue::fake();
        Http::fake([
            'https://api.telegram.org/bottelegram-token/getFile*' => Http::response([
                'ok' => true,
                'result' => [
                    'file_path' => 'photos/album-photo.jpg',
                    'file_size' => 128,
                ],
            ]),
            'https://api.telegram.org/file/bottelegram-token/*' => Http::response(
                'telegram-album-photo-bytes',
                200,
                ['Content-Type' => 'image/jpeg'],
            ),
        ]);
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        foreach ([101 => 'photo-unique-a', 102 => 'photo-unique-b'] as $messageId => $fileUniqueId) {
            $this->withHeaders([
                'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
            ])->postJson("/webhooks/telegram/{$channel->id}", [
                'update_id' => $messageId,
                'message' => [
                    'message_id' => $messageId,
                    'date' => 1_711_539_200 + $messageId,
                    'media_group_id' => 'telegram-media-group-guard-1',
                    'caption' => $messageId === 101 ? 'Подпись альбома' : null,
                    'from' => [
                        'id' => 200,
                        'username' => 'telegram_user',
                        'is_bot' => false,
                    ],
                    'chat' => [
                        'id' => 300,
                        'type' => 'private',
                    ],
                    'photo' => [[
                        'file_id' => 'file-'.$fileUniqueId,
                        'file_unique_id' => $fileUniqueId,
                        'width' => 640,
                        'height' => 640,
                        'file_size' => 128,
                    ]],
                ],
            ])->assertOk();
        }

        $messages = Message::query()->orderBy('id')->get();

        $this->assertCount(2, $messages);
        $this->assertSame('telegram-media-group-guard-1', $messages[0]->provider_group_key);
        $this->assertSame('telegram-media-group-guard-1', $messages[1]->provider_group_key);
        Queue::assertPushed(ProcessAutoReplyJob::class, function (ProcessAutoReplyJob $job) use ($messages): bool {
            return $job->inboundMessageId === $messages[0]->id;
        });
        Queue::assertPushed(ProcessAutoReplyJob::class, 1);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'media_group.automation_sibling_skipped',
        ]);
    }

    public function test_telegram_edited_message_updates_existing_message_and_keeps_revision_without_dispatching(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload(
            userId: 200,
            chatId: 300,
            messageId: 9100,
            text: 'Первый текст',
            date: 1_711_539_200,
        ))->assertOk();

        $message = $this->inboundMessages()->firstOrFail();

        $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", [
            'update_id' => 9101,
            'edited_message' => [
                'message_id' => 9100,
                'date' => 1_711_539_200,
                'edit_date' => 1_711_542_800,
                'text' => 'Обновленный текст',
                'from' => [
                    'id' => 200,
                    'username' => 'telegram_user',
                    'is_bot' => false,
                ],
                'chat' => [
                    'id' => 300,
                    'type' => 'private',
                ],
            ],
        ])->assertOk();

        $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", [
            'update_id' => 9101,
            'edited_message' => [
                'message_id' => 9100,
                'date' => 1_711_539_200,
                'edit_date' => 1_711_542_800,
                'text' => 'Обновленный текст',
                'from' => [
                    'id' => 200,
                    'username' => 'telegram_user',
                    'is_bot' => false,
                ],
                'chat' => [
                    'id' => 300,
                    'type' => 'private',
                ],
            ],
        ])->assertOk();

        Queue::assertPushed(ProcessAutoReplyJob::class, 1);
        Http::assertNothingSent();

        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseCount('message_revisions', 1);

        $message->refresh();

        $this->assertSame('Обновленный текст', $message->text);
        $this->assertSame(1, $message->edit_count);
        $this->assertSame('9101', $message->last_edit_provider_event_key);
        $this->assertNotNull($message->edited_at);

        $revision = MessageRevision::query()->firstOrFail();

        $this->assertSame($message->id, $revision->message_id);
        $this->assertSame(MessageRevision::TYPE_EDIT, $revision->revision_type);
        $this->assertSame('9101', $revision->provider_event_key);
        $this->assertSame('Первый текст', $revision->previous_text);
        $this->assertSame('Обновленный текст', $revision->new_text);
    }

    public function test_telegram_orphaned_edited_message_is_logged_without_creating_message(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", [
            'update_id' => 9111,
            'edited_message' => [
                'message_id' => 9110,
                'date' => 1_711_539_200,
                'edit_date' => 1_711_542_800,
                'text' => 'Поздняя правка',
                'from' => [
                    'id' => 200,
                    'username' => 'telegram_user',
                    'is_bot' => false,
                ],
                'chat' => [
                    'id' => 300,
                    'type' => 'private',
                ],
            ],
        ])->assertOk();

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('message_revisions', 0);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'message_edit.orphaned',
        ]);
    }

    public function test_telegram_edited_message_without_text_preserves_text_and_rich_text(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);
        $payload = $this->telegramPayload(
            userId: 200,
            chatId: 300,
            messageId: 9120,
            text: 'bold text',
            date: 1_711_539_200,
        );
        $payload['message']['entities'] = [
            [
                'type' => 'bold',
                'offset' => 0,
                'length' => 4,
            ],
        ];

        $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $payload)->assertOk();

        $message = $this->inboundMessages()->firstOrFail();
        $previousRichText = $message->rich_text;

        $this->assertIsArray($previousRichText);

        $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", [
            'update_id' => 9121,
            'edited_message' => [
                'message_id' => 9120,
                'date' => 1_711_539_200,
                'edit_date' => 1_711_542_800,
                'from' => [
                    'id' => 200,
                    'username' => 'telegram_user',
                    'is_bot' => false,
                ],
                'chat' => [
                    'id' => 300,
                    'type' => 'private',
                ],
            ],
        ])->assertOk();

        $message->refresh();
        $revision = MessageRevision::query()->firstOrFail();

        $this->assertSame('bold text', $message->text);
        $this->assertSame($previousRichText, $message->rich_text);
        $this->assertSame('bold text', $revision->new_text);
        $this->assertSame($previousRichText, $revision->new_rich_text);
    }

    public function test_telegram_photo_webhook_downloads_previewable_message_attachment(): void
    {
        Queue::fake();
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        Http::fake([
            'https://api.telegram.org/bottelegram-token/getFile*' => Http::response([
                'ok' => true,
                'result' => [
                    'file_path' => 'photos/telegram-large-file.jpg',
                    'file_size' => strlen('telegram-jpeg-bytes'),
                ],
            ]),
            'https://api.telegram.org/file/bottelegram-token/photos/telegram-large-file.jpg' => Http::response(
                'telegram-jpeg-bytes',
                200,
                ['Content-Type' => 'image/jpeg'],
            ),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);
        $payload = $this->telegramPayload(
            messageId: 101,
            text: null,
        );
        $payload['message']['caption'] = 'Фото';
        $payload['message']['photo'] = [
            [
                'file_id' => 'telegram-small-file-id',
                'file_unique_id' => 'telegram-small-unique-id',
                'width' => 90,
                'height' => 90,
                'file_size' => 1200,
            ],
            [
                'file_id' => 'telegram-large-file-id',
                'file_unique_id' => 'telegram-large-unique-id',
                'width' => 1280,
                'height' => 720,
                'file_size' => 8192,
            ],
        ];

        $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $payload)->assertOk();

        $inboundMessage = $this->inboundMessages()->firstOrFail();

        $this->assertDatabaseHas('message_attachments', [
            'message_id' => $inboundMessage->id,
            'channel_id' => $channel->id,
            'provider' => MessageAttachment::PROVIDER_TELEGRAM_BOT,
            'provider_event_key' => '101',
            'provider_attachment_key' => 'telegram-large-unique-id',
            'media_kind' => MessageAttachment::MEDIA_KIND_IMAGE,
            'provider_file_id' => 'telegram-large-file-id',
            'provider_file_unique_id' => 'telegram-large-unique-id',
            'file_size_bytes' => strlen('telegram-jpeg-bytes'),
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED,
            'send_status' => MessageAttachment::SEND_STATUS_NOT_APPLICABLE,
            'local_disk' => MessageAttachment::LOCAL_DISK_PRIVATE,
        ]);

        $attachment = MessageAttachment::query()->where('message_id', $inboundMessage->id)->firstOrFail();

        $this->assertTrue($attachment->isInlinePreviewable());
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->assertExists((string) $attachment->local_path);

        $storedPath = $attachment->local_path;

        $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $payload)->assertOk();

        $attachment->refresh();

        $this->assertDatabaseCount('message_attachments', 1);
        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED, $attachment->download_status);
        $this->assertSame(MessageAttachment::LOCAL_DISK_PRIVATE, $attachment->local_disk);
        $this->assertSame($storedPath, $attachment->local_path);
    }

    public function test_telegram_voice_webhook_downloads_previewable_audio_attachment(): void
    {
        Queue::fake();
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        Http::fake([
            'https://api.telegram.org/bottelegram-token/getFile*' => Http::response([
                'ok' => true,
                'result' => [
                    'file_path' => 'voice/file_1.ogg',
                    'file_size' => strlen('telegram-voice-bytes'),
                ],
            ]),
            'https://api.telegram.org/file/bottelegram-token/voice/file_1.ogg' => Http::response(
                'telegram-voice-bytes',
                200,
                ['Content-Type' => 'application/octet-stream'],
            ),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);
        $payload = $this->telegramPayload(
            messageId: 102,
            text: null,
        );
        unset($payload['message']['text']);
        $payload['message']['voice'] = [
            'file_id' => 'telegram-voice-file-id',
            'file_unique_id' => 'telegram-voice-unique-id',
            'duration' => 7,
            'file_size' => 151_664,
            'mime_type' => 'audio/ogg',
        ];

        $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $payload)->assertOk();

        $inboundMessage = $this->inboundMessages()->firstOrFail();

        $this->assertDatabaseHas('message_attachments', [
            'message_id' => $inboundMessage->id,
            'channel_id' => $channel->id,
            'provider' => MessageAttachment::PROVIDER_TELEGRAM_BOT,
            'provider_event_key' => '102',
            'provider_attachment_key' => 'telegram-voice-unique-id',
            'media_kind' => MessageAttachment::MEDIA_KIND_VOICE,
            'provider_file_id' => 'telegram-voice-file-id',
            'provider_file_unique_id' => 'telegram-voice-unique-id',
            'mime_type' => 'audio/ogg',
            'extension' => 'ogg',
            'file_size_bytes' => strlen('telegram-voice-bytes'),
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED,
            'send_status' => MessageAttachment::SEND_STATUS_NOT_APPLICABLE,
            'local_disk' => MessageAttachment::LOCAL_DISK_PRIVATE,
        ]);

        $attachment = MessageAttachment::query()->where('message_id', $inboundMessage->id)->firstOrFail();

        $this->assertTrue($attachment->isInlinePreviewable());
        $this->assertSame(MessageAttachment::PREVIEW_KIND_AUDIO, $attachment->previewKind());
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->assertExists((string) $attachment->local_path);
    }

    public function test_telegram_document_webhook_downloads_previewable_pdf_attachment(): void
    {
        Queue::fake();
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        Http::fake([
            'https://api.telegram.org/bottelegram-token/getFile*' => Http::response([
                'ok' => true,
                'result' => [
                    'file_path' => 'documents/offer.pdf',
                    'file_size' => strlen('%PDF-telegram-document-bytes'),
                ],
            ]),
            'https://api.telegram.org/file/bottelegram-token/documents/offer.pdf' => Http::response(
                '%PDF-telegram-document-bytes',
                200,
                ['Content-Type' => 'application/octet-stream'],
            ),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);
        $payload = $this->telegramPayload(
            messageId: 103,
            text: null,
        );
        unset($payload['message']['text']);
        $payload['message']['caption'] = 'PDF';
        $payload['message']['document'] = [
            'file_id' => 'telegram-document-file-id',
            'file_unique_id' => 'telegram-document-unique-id',
            'file_name' => 'offer.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 333_000,
        ];

        $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $payload)->assertOk();

        $inboundMessage = $this->inboundMessages()->firstOrFail();

        $this->assertDatabaseHas('message_attachments', [
            'message_id' => $inboundMessage->id,
            'channel_id' => $channel->id,
            'provider' => MessageAttachment::PROVIDER_TELEGRAM_BOT,
            'provider_event_key' => '103',
            'provider_attachment_key' => 'telegram-document-unique-id',
            'media_kind' => MessageAttachment::MEDIA_KIND_DOCUMENT,
            'provider_file_id' => 'telegram-document-file-id',
            'provider_file_unique_id' => 'telegram-document-unique-id',
            'original_filename' => 'offer.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'file_size_bytes' => strlen('%PDF-telegram-document-bytes'),
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED,
            'send_status' => MessageAttachment::SEND_STATUS_NOT_APPLICABLE,
            'local_disk' => MessageAttachment::LOCAL_DISK_PRIVATE,
        ]);

        $attachment = MessageAttachment::query()->where('message_id', $inboundMessage->id)->firstOrFail();

        $this->assertFalse($attachment->isInlinePreviewable());
        $this->assertNull($attachment->previewKind());
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->assertExists((string) $attachment->local_path);
    }

    public function test_telegram_video_webhook_downloads_previewable_video_attachment(): void
    {
        Queue::fake();
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        Http::fake([
            'https://api.telegram.org/bottelegram-token/getFile*' => Http::response([
                'ok' => true,
                'result' => [
                    'file_path' => 'videos/room-tour.mp4',
                    'file_size' => strlen('telegram-video-bytes'),
                ],
            ]),
            'https://api.telegram.org/file/bottelegram-token/videos/room-tour.mp4' => Http::response(
                'telegram-video-bytes',
                200,
                ['Content-Type' => 'application/octet-stream'],
            ),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);
        $payload = $this->telegramPayload(
            messageId: 104,
            text: null,
        );
        unset($payload['message']['text']);
        $payload['message']['caption'] = 'Видео';
        $payload['message']['video'] = [
            'file_id' => 'telegram-video-file-id',
            'file_unique_id' => 'telegram-video-unique-id',
            'file_name' => 'room-tour.mp4',
            'mime_type' => 'video/mp4',
            'duration' => 12,
            'width' => 1280,
            'height' => 720,
            'file_size' => 444_000,
        ];

        $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $payload)->assertOk();

        $inboundMessage = $this->inboundMessages()->firstOrFail();

        $this->assertDatabaseHas('message_attachments', [
            'message_id' => $inboundMessage->id,
            'channel_id' => $channel->id,
            'provider' => MessageAttachment::PROVIDER_TELEGRAM_BOT,
            'provider_event_key' => '104',
            'provider_attachment_key' => 'telegram-video-unique-id',
            'media_kind' => MessageAttachment::MEDIA_KIND_VIDEO,
            'provider_file_id' => 'telegram-video-file-id',
            'provider_file_unique_id' => 'telegram-video-unique-id',
            'original_filename' => 'room-tour.mp4',
            'mime_type' => 'video/mp4',
            'extension' => 'mp4',
            'file_size_bytes' => strlen('telegram-video-bytes'),
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED,
            'send_status' => MessageAttachment::SEND_STATUS_NOT_APPLICABLE,
            'local_disk' => MessageAttachment::LOCAL_DISK_PRIVATE,
        ]);

        $attachment = MessageAttachment::query()->where('message_id', $inboundMessage->id)->firstOrFail();

        $this->assertTrue($attachment->isInlinePreviewable());
        $this->assertSame(MessageAttachment::PREVIEW_KIND_VIDEO, $attachment->previewKind());
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->assertExists((string) $attachment->local_path);
    }

    public function test_telegram_animation_webhook_treats_bot_api_document_copy_as_one_animation_attachment(): void
    {
        Queue::fake();
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        Http::fake([
            'https://api.telegram.org/bottelegram-token/getFile*' => Http::response([
                'ok' => true,
                'result' => [
                    'file_path' => 'animations/4ff.gif.mp4',
                    'file_size' => strlen('telegram-animation-bytes'),
                ],
            ]),
            'https://api.telegram.org/file/bottelegram-token/animations/4ff.gif.mp4' => Http::response(
                'telegram-animation-bytes',
                200,
                ['Content-Type' => 'video/mp4'],
            ),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);
        $payload = $this->telegramPayload(
            messageId: 108,
            text: null,
        );
        unset($payload['message']['text']);
        $animation = [
            'file_id' => 'telegram-animation-file-id',
            'file_unique_id' => 'telegram-animation-unique-id',
            'file_name' => '4ff.gif.mp4',
            'mime_type' => 'video/mp4',
            'duration' => 1,
            'width' => 320,
            'height' => 180,
            'file_size' => 32_519,
        ];
        $payload['message']['animation'] = $animation;
        $payload['message']['document'] = $animation;

        $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $payload)->assertOk();

        $inboundMessage = $this->inboundMessages()->firstOrFail();

        $this->assertSame(1, MessageAttachment::query()->where('message_id', $inboundMessage->id)->count());
        $this->assertDatabaseHas('message_attachments', [
            'message_id' => $inboundMessage->id,
            'channel_id' => $channel->id,
            'provider' => MessageAttachment::PROVIDER_TELEGRAM_BOT,
            'provider_event_key' => '108',
            'provider_attachment_key' => 'telegram-animation-unique-id',
            'media_kind' => MessageAttachment::MEDIA_KIND_ANIMATION,
            'provider_file_id' => 'telegram-animation-file-id',
            'provider_file_unique_id' => 'telegram-animation-unique-id',
            'original_filename' => '4ff.gif.mp4',
            'mime_type' => 'video/mp4',
            'extension' => 'mp4',
            'file_size_bytes' => strlen('telegram-animation-bytes'),
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED,
            'send_status' => MessageAttachment::SEND_STATUS_NOT_APPLICABLE,
            'local_disk' => MessageAttachment::LOCAL_DISK_PRIVATE,
        ]);

        $attachment = MessageAttachment::query()->where('message_id', $inboundMessage->id)->firstOrFail();

        $this->assertTrue($attachment->isInlinePreviewable());
        $this->assertSame(MessageAttachment::PREVIEW_KIND_VIDEO, $attachment->previewKind());
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->assertExists((string) $attachment->local_path);
    }

    public function test_telegram_sticker_webhook_downloads_previewable_sticker_attachment(): void
    {
        Queue::fake();
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        Http::fake([
            'https://api.telegram.org/bottelegram-token/getFile*' => Http::response([
                'ok' => true,
                'result' => [
                    'file_path' => 'stickers/sticker.webp',
                    'file_size' => strlen('telegram-webp-sticker-bytes'),
                ],
            ]),
            'https://api.telegram.org/file/bottelegram-token/stickers/sticker.webp' => Http::response(
                'RIFFtelegram-webp-sticker-bytesWEBP',
                200,
                ['Content-Type' => 'image/webp'],
            ),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);
        $payload = $this->telegramPayload(
            messageId: 109,
            text: null,
        );
        unset($payload['message']['text']);
        $payload['message']['sticker'] = [
            'file_id' => 'telegram-sticker-file-id',
            'file_unique_id' => 'telegram-sticker-unique-id',
            'type' => 'regular',
            'emoji' => '😂',
            'width' => 512,
            'height' => 512,
            'file_size' => 24_000,
            'is_animated' => false,
            'is_video' => false,
        ];

        $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $payload)->assertOk();

        $inboundMessage = $this->inboundMessages()->firstOrFail();

        $this->assertDatabaseHas('message_attachments', [
            'message_id' => $inboundMessage->id,
            'channel_id' => $channel->id,
            'provider' => MessageAttachment::PROVIDER_TELEGRAM_BOT,
            'provider_event_key' => '109',
            'provider_attachment_key' => 'telegram-sticker-unique-id',
            'media_kind' => MessageAttachment::MEDIA_KIND_STICKER,
            'provider_file_id' => 'telegram-sticker-file-id',
            'provider_file_unique_id' => 'telegram-sticker-unique-id',
            'mime_type' => 'image/webp',
            'extension' => 'webp',
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED,
            'send_status' => MessageAttachment::SEND_STATUS_NOT_APPLICABLE,
            'local_disk' => MessageAttachment::LOCAL_DISK_PRIVATE,
        ]);

        $attachment = MessageAttachment::query()->where('message_id', $inboundMessage->id)->firstOrFail();

        $this->assertTrue($attachment->isInlinePreviewable());
        $this->assertSame(MessageAttachment::PREVIEW_KIND_IMAGE, $attachment->previewKind());
        $this->assertSame('😂', $attachment->raw_payload_excerpt['emoji'] ?? null);
    }

    public function test_telegram_animated_sticker_uses_thumbnail_as_inline_preview(): void
    {
        Queue::fake();
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        Http::fake([
            'https://api.telegram.org/bottelegram-token/getFile*' => Http::response([
                'ok' => true,
                'result' => [
                    'file_path' => 'stickers/thumb.jpg',
                    'file_size' => strlen('telegram-sticker-thumbnail-bytes'),
                ],
            ]),
            'https://api.telegram.org/file/bottelegram-token/stickers/thumb.jpg' => Http::response(
                "\xFF\xD8\xFF".'telegram-sticker-thumbnail-bytes',
                200,
                ['Content-Type' => 'application/octet-stream'],
            ),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);
        $payload = $this->telegramPayload(
            messageId: 110,
            text: null,
        );
        unset($payload['message']['text']);
        $payload['message']['sticker'] = [
            'file_id' => 'telegram-animated-sticker-file-id',
            'file_unique_id' => 'telegram-animated-sticker-unique-id',
            'type' => 'regular',
            'emoji' => '😂',
            'width' => 512,
            'height' => 512,
            'file_size' => 24_000,
            'is_animated' => true,
            'is_video' => false,
            'thumbnail' => [
                'file_id' => 'telegram-sticker-thumb-file-id',
                'file_unique_id' => 'telegram-sticker-thumb-unique-id',
                'width' => 128,
                'height' => 128,
                'file_size' => 5_772,
            ],
        ];

        $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $payload)->assertOk();

        $inboundMessage = $this->inboundMessages()->firstOrFail();

        $this->assertDatabaseHas('message_attachments', [
            'message_id' => $inboundMessage->id,
            'channel_id' => $channel->id,
            'provider' => MessageAttachment::PROVIDER_TELEGRAM_BOT,
            'provider_event_key' => '110',
            'provider_attachment_key' => 'telegram-animated-sticker-unique-id',
            'media_kind' => MessageAttachment::MEDIA_KIND_STICKER,
            'provider_file_id' => 'telegram-animated-sticker-file-id',
            'provider_file_unique_id' => 'telegram-animated-sticker-unique-id',
            'original_filename' => 'sticker-preview.jpg',
            'mime_type' => 'image/jpeg',
            'extension' => 'jpg',
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED,
            'send_status' => MessageAttachment::SEND_STATUS_NOT_APPLICABLE,
            'local_disk' => MessageAttachment::LOCAL_DISK_PRIVATE,
        ]);

        $attachment = MessageAttachment::query()->where('message_id', $inboundMessage->id)->firstOrFail();

        $this->assertTrue($attachment->isInlinePreviewable());
        $this->assertSame(MessageAttachment::PREVIEW_KIND_IMAGE, $attachment->previewKind());
        $this->assertSame('telegram-sticker-thumb-file-id', $attachment->raw_payload_excerpt['thumbnail_file_id'] ?? null);
        $this->assertSame('thumbnail', $attachment->provider_metadata['telegram_preview_source'] ?? null);
        $this->assertSame('telegram-animated-sticker-file-id', $attachment->provider_metadata['telegram_original_file_id'] ?? null);
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->assertExists((string) $attachment->local_path);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/getFile')
            && data_get($request->data(), 'file_id') === 'telegram-sticker-thumb-file-id');
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/getFile')
            && data_get($request->data(), 'file_id') === 'telegram-animated-sticker-file-id');
    }

    public function test_telegram_venue_webhook_stores_human_readable_location_text(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);
        $payload = $this->telegramPayload(
            messageId: 110,
            text: null,
        );
        unset($payload['message']['text']);
        $payload['message']['venue'] = [
            'title' => 'Sorveteria Veneza',
            'address' => 'Rua Barra Velha, 239',
            'location' => [
                'latitude' => -26.45135,
                'longitude' => -48.620254,
            ],
        ];

        $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $payload)->assertOk();

        $inboundMessage = $this->inboundMessages()->firstOrFail();

        $this->assertSame(Message::KIND_INBOUND_USER, $inboundMessage->message_kind);
        $this->assertSame(
            "Локация: Sorveteria Veneza\nRua Barra Velha, 239\n-26.45135, -48.620254",
            $inboundMessage->text,
        );
        $this->assertSame(0, MessageAttachment::query()->where('message_id', $inboundMessage->id)->count());
    }

    public function test_telegram_video_note_webhook_downloads_previewable_round_video_attachment(): void
    {
        Queue::fake();
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        Http::fake([
            'https://api.telegram.org/bottelegram-token/getFile*' => Http::response([
                'ok' => true,
                'result' => [
                    'file_path' => 'video_notes/round.mp4',
                    'file_size' => strlen('telegram-video-note-bytes'),
                ],
            ]),
            'https://api.telegram.org/file/bottelegram-token/video_notes/round.mp4' => Http::response(
                'telegram-video-note-bytes',
                200,
                ['Content-Type' => 'application/octet-stream'],
            ),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);
        $payload = $this->telegramPayload(
            messageId: 105,
            text: null,
        );
        unset($payload['message']['text']);
        $payload['message']['video_note'] = [
            'file_id' => 'telegram-video-note-file-id',
            'file_unique_id' => 'telegram-video-note-unique-id',
            'duration' => 21,
            'length' => 384,
            'file_size' => 456_789,
        ];

        $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $payload)->assertOk();

        $inboundMessage = $this->inboundMessages()->firstOrFail();

        $this->assertDatabaseHas('message_attachments', [
            'message_id' => $inboundMessage->id,
            'channel_id' => $channel->id,
            'provider' => MessageAttachment::PROVIDER_TELEGRAM_BOT,
            'provider_event_key' => '105',
            'provider_attachment_key' => 'telegram-video-note-unique-id',
            'media_kind' => MessageAttachment::MEDIA_KIND_VIDEO_NOTE,
            'provider_file_id' => 'telegram-video-note-file-id',
            'provider_file_unique_id' => 'telegram-video-note-unique-id',
            'mime_type' => 'video/mp4',
            'extension' => 'mp4',
            'file_size_bytes' => strlen('telegram-video-note-bytes'),
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED,
            'send_status' => MessageAttachment::SEND_STATUS_NOT_APPLICABLE,
            'local_disk' => MessageAttachment::LOCAL_DISK_PRIVATE,
        ]);

        $attachment = MessageAttachment::query()->where('message_id', $inboundMessage->id)->firstOrFail();

        $this->assertSame(21, data_get($attachment->provider_metadata, 'duration'));
        $this->assertTrue(data_get($attachment->provider_metadata, 'is_video_note'));
        $this->assertTrue($attachment->isInlinePreviewable());
        $this->assertSame(MessageAttachment::PREVIEW_KIND_VIDEO, $attachment->previewKind());
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->assertExists((string) $attachment->local_path);
    }

    public function test_telegram_start_payload_webhook_saves_message_parameter_and_queues_auto_reply(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload(
            messageId: 11,
            text: '/start TEXT_1',
        ));

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $inboundMessage = $this->inboundMessages()->firstOrFail();

        Queue::assertPushed(ProcessAutoReplyJob::class, function (ProcessAutoReplyJob $job) use ($inboundMessage): bool {
            return $job->inboundMessageId === $inboundMessage->id;
        });

        $this->assertDatabaseHas('messages', [
            'id' => $inboundMessage->id,
            'text' => '/start TEXT_1',
            'message_parameter' => 'TEXT_1',
        ]);
    }

    public function test_telegram_my_chat_member_webhook_stores_system_event_and_queues_live_export_for_ready_dialog(): void
    {
        config()->set('bitrix24.features.openlines_enabled', true);

        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);
        $contact = Contact::factory()->create([
            'bitrix24_contact_id' => 'B24-CONTACT-TG-200',
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_SYNCED,
            'bitrix24_sync_pending' => false,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '200',
            'external_username' => 'telegram_user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => '200',
        ]);

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramMyChatMemberPayload(
            userId: 200,
            chatId: 200,
            oldStatus: 'member',
            newStatus: 'kicked',
            updateId: 2010,
        ));

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        Queue::assertNotPushed(ProcessDataCollectionResponseJob::class);
        Queue::assertNotPushed(ProcessPhoneCaptureFollowUpJob::class);
        Queue::assertNotPushed(ProcessScenarioInboundJob::class);
        Queue::assertNotPushed(ProcessScenarioStartJob::class);
        Queue::assertPushed(ExportMessageToBitrix24OpenLinesJob::class, function (ExportMessageToBitrix24OpenLinesJob $job): bool {
            return $job->retryAfterSync === false;
        });
        Http::assertNothingSent();

        $storedMessage = Message::query()->firstOrFail();

        $this->assertSame(Message::KIND_INBOUND_SYSTEM_EVENT, $storedMessage->message_kind);
        $this->assertSame(Message::SYSTEM_EVENT_CODE_BOT_BLOCKED_BY_USER, $storedMessage->system_event_code);
        $this->assertSame(Message::SENT_BY_TYPE_SYSTEM, $storedMessage->sent_by_type);
        $this->assertSame(Message::SENT_BY_SYSTEM_CODE_TELEGRAM_BOT_SUBSCRIPTION, $storedMessage->sent_by_system_code);
        $this->assertSame('2010', $storedMessage->provider_event_key);
        $this->assertDatabaseCount('contacts', 1);
        $this->assertDatabaseCount('contact_identities', 1);
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseMissing('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'bot.reply_queued',
        ]);

        $dialog->refresh();

        $this->assertSame(Dialog::BOT_SUBSCRIPTION_STATUS_BLOCKED_BY_USER, $dialog->bot_subscription_status);
        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $storedMessage->id,
            'contact_id' => $contact->id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_PENDING,
        ]);
    }

    public function test_max_webhook_endpoint_accepts_valid_event_and_queues_auto_reply(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);

        $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $this->maxPayload())->assertOk();

        Http::assertNothingSent();

        $inboundMessage = $this->inboundMessages()->firstOrFail();

        Queue::assertPushed(ProcessAutoReplyJob::class, function (ProcessAutoReplyJob $job) use ($inboundMessage): bool {
            return $job->inboundMessageId === $inboundMessage->id;
        });

        $channel->refresh();

        $this->assertNotNull($channel->last_webhook_received_at);
        $this->assertNull($channel->last_reply_sent_at);
        $this->assertNull($channel->last_error_at);
        $this->assertDatabaseCount('contacts', 1);
        $this->assertDatabaseCount('contact_identities', 1);
        $this->assertDatabaseCount('messages', 1);
        $this->assertMessageDirectionCount(Message::DIRECTION_INBOUND, 1);
        $this->assertMessageDirectionCount(Message::DIRECTION_OUTBOUND, 0);
        $this->assertDatabaseHas('contact_identities', [
            'channel_id' => $channel->id,
            'platform' => Channel::PLATFORM_MAX,
            'external_user_id' => '500',
            'external_username' => 'max_user',
        ]);
        $this->assertDatabaseHas('messages', [
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => '700',
            'external_message_id' => 'max-10',
            'text' => 'hello',
        ]);
        $this->assertSame('max-10', $inboundMessage->provider_event_key);
        $this->assertNull($inboundMessage->auto_reply_sent_at);
    }

    public function test_max_image_webhook_downloads_previewable_message_attachment_without_secret_fields(): void
    {
        Queue::fake();
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        config()->set('bots.max.trusted_media_hosts', ['max.example']);
        Http::fake([
            'https://max.example/*' => Http::response(
                'max-jpeg-bytes',
                200,
                ['Content-Type' => 'image/jpeg'],
            ),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);
        $payload = $this->maxPayload(
            messageId: 'max-photo-101',
            text: null,
        );
        $payload['message']['body']['attachments'] = [
            [
                'type' => 'image',
                'payload' => [
                    'photo_id' => '25852958504',
                    'url' => 'https://max.example/private/photo.jpg?access_token=secret-token',
                    'token' => 'secret-token',
                    'width' => 538,
                    'height' => 1280,
                ],
            ],
        ];

        $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $payload)->assertOk();

        $inboundMessage = $this->inboundMessages()->firstOrFail();
        $attachment = MessageAttachment::query()->where('message_id', $inboundMessage->id)->firstOrFail();

        $this->assertSame(MessageAttachment::PROVIDER_MAX_BOT, $attachment->provider);
        $this->assertSame('max-photo-101', $attachment->provider_event_key);
        $this->assertSame('25852958504', $attachment->provider_attachment_key);
        $this->assertSame('25852958504', $attachment->provider_file_reference);
        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED, $attachment->download_status);
        $this->assertSame(MessageAttachment::LOCAL_DISK_PRIVATE, $attachment->local_disk);
        $this->assertTrue($attachment->isInlinePreviewable());
        $this->assertStringNotContainsString('secret-token', json_encode($attachment->provider_metadata, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('access_token', json_encode($attachment->raw_payload_excerpt, JSON_THROW_ON_ERROR));
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->assertExists((string) $attachment->local_path);
    }

    public function test_max_sticker_webhook_downloads_real_asset_from_message_lookup_when_webhook_has_stub_url(): void
    {
        Queue::fake();
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        config()->set('bots.max.trusted_media_hosts', ['mycdn.me', 'oneme.ru']);
        Http::fake([
            'https://platform-api.max.ru/messages/max-sticker-101' => Http::response([
                'body' => [
                    'mid' => 'max-sticker-101',
                    'attachments' => [
                        [
                            'type' => 'sticker',
                            'width' => 170,
                            'height' => 170,
                            'payload' => [
                                'url' => 'https://i.oneme.ru/getSmile?smileId=429b5&smileType=4',
                                'code' => '429b5',
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

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);
        $payload = $this->maxPayload(
            messageId: 'max-sticker-101',
            text: null,
        );
        $payload['message']['body']['attachments'] = [
            [
                'type' => 'sticker',
                'width' => 144,
                'height' => 144,
                'payload' => [
                    'url' => 'https://st.mycdn.me/static/messages/res/images/stub/sticker_31856a27@2x.png',
                    'code' => '429b5',
                ],
            ],
        ];

        $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $payload)->assertOk();

        $inboundMessage = $this->inboundMessages()->firstOrFail();
        $attachment = MessageAttachment::query()->where('message_id', $inboundMessage->id)->firstOrFail();

        $this->assertSame(MessageAttachment::PROVIDER_MAX_BOT, $attachment->provider);
        $this->assertSame('max-sticker-101', $attachment->provider_event_key);
        $this->assertSame('429b5', $attachment->provider_attachment_key);
        $this->assertSame('429b5', $attachment->provider_file_reference);
        $this->assertSame(MessageAttachment::MEDIA_KIND_STICKER, $attachment->media_kind);
        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED, $attachment->download_status);
        $this->assertSame(MessageAttachment::LOCAL_DISK_PRIVATE, $attachment->local_disk);
        $this->assertSame('image/png', $attachment->mime_type);
        $this->assertSame('png', $attachment->extension);
        $this->assertSame(170, data_get($attachment->provider_metadata, 'width'));
        $this->assertSame(170, data_get($attachment->provider_metadata, 'height'));
        $this->assertSame(MessageAttachment::PREVIEW_KIND_IMAGE, $attachment->previewKind());
        $this->assertStringNotContainsString('st.mycdn.me', json_encode($attachment->provider_metadata, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('st.mycdn.me', json_encode($attachment->raw_payload_excerpt, JSON_THROW_ON_ERROR));
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->assertExists((string) $attachment->local_path);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://platform-api.max.ru/messages/max-sticker-101');
        Http::assertSent(fn ($request): bool => $request->url() === 'https://i.oneme.ru/getSmile?smileId=429b5&smileType=4');
        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://st.mycdn.me/static/messages/res/images/stub/sticker_31856a27@2x.png');
    }

    public function test_max_audio_webhook_downloads_previewable_message_attachment_without_secret_fields(): void
    {
        Queue::fake();
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        config()->set('bots.max.trusted_media_hosts', ['max.example']);
        Http::fake([
            'https://max.example/*' => Http::response(
                'max-audio-bytes',
                200,
                ['Content-Type' => 'audio/mpeg'],
            ),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);
        $payload = $this->maxPayload(
            messageId: 'max-audio-102',
            text: null,
        );
        $payload['message']['body']['attachments'] = [
            [
                'type' => 'audio',
                'payload' => [
                    'token' => 'max-audio-token',
                    'url' => 'https://max.example/private/audio.mp3?access_token=secret-token',
                    'duration' => 7,
                ],
            ],
        ];

        $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $payload)->assertOk();

        $inboundMessage = $this->inboundMessages()->firstOrFail();
        $attachment = MessageAttachment::query()->where('message_id', $inboundMessage->id)->firstOrFail();

        $this->assertSame(MessageAttachment::PROVIDER_MAX_BOT, $attachment->provider);
        $this->assertSame('max-audio-102', $attachment->provider_event_key);
        $this->assertSame('token:'.sha1('max-audio-token'), $attachment->provider_attachment_key);
        $this->assertSame('token:'.sha1('max-audio-token'), $attachment->provider_file_reference);
        $this->assertSame(MessageAttachment::MEDIA_KIND_AUDIO, $attachment->media_kind);
        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED, $attachment->download_status);
        $this->assertSame('audio/mpeg', $attachment->mime_type);
        $this->assertSame('mp3', $attachment->extension);
        $this->assertSame(MessageAttachment::LOCAL_DISK_PRIVATE, $attachment->local_disk);
        $this->assertTrue($attachment->isInlinePreviewable());
        $this->assertSame(MessageAttachment::PREVIEW_KIND_AUDIO, $attachment->previewKind());
        $this->assertStringNotContainsString('max-audio-token', json_encode($attachment->provider_metadata, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('secret-token', json_encode($attachment->provider_metadata, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('access_token', json_encode($attachment->raw_payload_excerpt, JSON_THROW_ON_ERROR));
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->assertExists((string) $attachment->local_path);
    }

    public function test_max_audio_webhook_downloads_real_okcdn_media_url_by_default(): void
    {
        Queue::fake();
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        Http::fake([
            'https://maxvd126.okcdn.ru/*' => Http::response(
                'max-okcdn-audio-bytes',
                200,
                ['Content-Type' => 'audio/ogg'],
            ),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);
        $payload = $this->maxPayload(
            messageId: 'max-okcdn-audio-105',
            text: null,
        );
        $payload['message']['body']['attachments'] = [
            [
                'type' => 'audio',
                'payload' => [
                    'id' => 15446112952411,
                    'token' => 'max-okcdn-audio-token',
                    'url' => 'https://maxvd126.okcdn.ru/?expires=1782842145216&srcIp=0.0.0.0&type=2&sig=signed&ct=2',
                ],
            ],
        ];

        $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $payload)->assertOk();

        $inboundMessage = $this->inboundMessages()->firstOrFail();
        $attachment = MessageAttachment::query()->where('message_id', $inboundMessage->id)->firstOrFail();

        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED, $attachment->download_status);
        $this->assertSame('audio/ogg', $attachment->mime_type);
        $this->assertSame('ogg', $attachment->extension);
        $this->assertSame(MessageAttachment::LOCAL_DISK_PRIVATE, $attachment->local_disk);
        $this->assertTrue($attachment->isInlinePreviewable());
        $this->assertSame(MessageAttachment::PREVIEW_KIND_AUDIO, $attachment->previewKind());
        $this->assertStringNotContainsString('max-okcdn-audio-token', json_encode($attachment->provider_metadata, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('expires', json_encode($attachment->raw_payload_excerpt, JSON_THROW_ON_ERROR));
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->assertExists((string) $attachment->local_path);
    }

    public function test_max_video_webhook_downloads_previewable_message_attachment_without_secret_fields(): void
    {
        Queue::fake();
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        config()->set('bots.max.trusted_media_hosts', ['max.example']);
        Http::fake([
            'https://platform-api.max.ru/videos/max-video-token' => Http::response([
                'token' => 'max-video-token',
                'urls' => [
                    'mp4_720' => 'https://max.example/private/video-720.mp4?access_token=derived-secret',
                ],
                'width' => 1280,
                'height' => 720,
                'duration' => 14,
            ]),
            'https://max.example/private/video-720.mp4*' => Http::response(
                'max-video-bytes',
                200,
                ['Content-Type' => 'video/mp4'],
            ),
            'https://max.example/private/video.mp4*' => Http::response('', 500),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);
        $payload = $this->maxPayload(
            messageId: 'max-video-103',
            text: null,
        );
        $payload['message']['body']['attachments'] = [
            [
                'type' => 'video',
                'payload' => [
                    'token' => 'max-video-token',
                    'url' => 'https://max.example/private/video.mp4?access_token=secret-token',
                    'duration' => 14,
                    'width' => 1280,
                    'height' => 720,
                ],
            ],
        ];

        $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $payload)->assertOk();

        $inboundMessage = $this->inboundMessages()->firstOrFail();
        $attachment = MessageAttachment::query()->where('message_id', $inboundMessage->id)->firstOrFail();

        $this->assertSame(MessageAttachment::PROVIDER_MAX_BOT, $attachment->provider);
        $this->assertSame('max-video-103', $attachment->provider_event_key);
        $this->assertSame('token:'.sha1('max-video-token'), $attachment->provider_attachment_key);
        $this->assertSame('token:'.sha1('max-video-token'), $attachment->provider_file_reference);
        $this->assertSame(MessageAttachment::MEDIA_KIND_VIDEO, $attachment->media_kind);
        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED, $attachment->download_status);
        $this->assertSame('video/mp4', $attachment->mime_type);
        $this->assertSame('mp4', $attachment->extension);
        $this->assertSame(MessageAttachment::LOCAL_DISK_PRIVATE, $attachment->local_disk);
        $this->assertTrue($attachment->isInlinePreviewable());
        $this->assertSame(MessageAttachment::PREVIEW_KIND_VIDEO, $attachment->previewKind());
        $this->assertStringNotContainsString('max-video-token', json_encode($attachment->provider_metadata, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('secret-token', json_encode($attachment->provider_metadata, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('access_token', json_encode($attachment->raw_payload_excerpt, JSON_THROW_ON_ERROR));
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->assertExists((string) $attachment->local_path);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://platform-api.max.ru/videos/max-video-token');
        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://max.example/private/video-720.mp4?'));
    }

    public function test_max_forwarded_video_webhook_downloads_link_message_attachment_as_video_note(): void
    {
        Queue::fake();
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        config()->set('bots.max.trusted_media_hosts', ['max.example']);
        Http::fake([
            'https://platform-api.max.ru/videos/forwarded-video-token' => Http::response([
                'token' => 'forwarded-video-token',
                'urls' => [
                    'mp4_480' => 'https://max.example/private/forwarded-video-480.mp4?access_token=derived-secret',
                ],
                'width' => 480,
                'height' => 480,
                'duration' => 23000,
            ]),
            'https://max.example/private/forwarded-video-480.mp4*' => Http::response(
                'max-forwarded-video-bytes',
                200,
                ['Content-Type' => 'video/mp4'],
            ),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);
        $payload = $this->maxPayload(
            messageId: 'max-forwarded-video-103',
            text: null,
        );
        unset($payload['message']['body']['attachments']);
        $payload['message']['link'] = [
            'type' => 'forward',
            'sender' => [
                'name' => 'Tanya',
                'is_bot' => false,
            ],
            'message' => [
                'mid' => 'forwarded-source-1',
                'text' => null,
                'attachments' => [
                    [
                        'type' => 'video',
                        'payload' => [
                            'token' => 'forwarded-video-token',
                            'url' => 'https://max.example/private/payload-video.mp4?access_token=secret-token',
                        ],
                        'duration' => 23,
                        'thumbnail' => [
                            'url' => 'https://max.example/private/thumb.jpg?access_token=secret-token',
                        ],
                    ],
                ],
            ],
        ];

        $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $payload)->assertOk();

        $inboundMessage = $this->inboundMessages()->firstOrFail();
        $attachment = MessageAttachment::query()->where('message_id', $inboundMessage->id)->firstOrFail();

        $this->assertSame(MessageAttachment::PROVIDER_MAX_BOT, $attachment->provider);
        $this->assertSame('max-forwarded-video-103', $attachment->provider_event_key);
        $this->assertSame('token:'.sha1('forwarded-video-token'), $attachment->provider_attachment_key);
        $this->assertSame('token:'.sha1('forwarded-video-token'), $attachment->provider_file_reference);
        $this->assertSame(MessageAttachment::MEDIA_KIND_VIDEO_NOTE, $attachment->media_kind);
        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED, $attachment->download_status);
        $this->assertSame('video/mp4', $attachment->mime_type);
        $this->assertSame('mp4', $attachment->extension);
        $this->assertSame(MessageAttachment::LOCAL_DISK_PRIVATE, $attachment->local_disk);
        $this->assertTrue($attachment->isInlinePreviewable());
        $this->assertSame(MessageAttachment::PREVIEW_KIND_VIDEO, $attachment->previewKind());
        $this->assertSame(480, data_get($attachment->provider_metadata, 'width'));
        $this->assertSame(480, data_get($attachment->provider_metadata, 'height'));
        $this->assertSame(23, data_get($attachment->provider_metadata, 'duration'));
        $this->assertTrue(data_get($attachment->provider_metadata, 'is_video_note'));
        $this->assertStringNotContainsString('forwarded-video-token', json_encode($attachment->provider_metadata, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('secret-token', json_encode($attachment->provider_metadata, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('access_token', json_encode($attachment->raw_payload_excerpt, JSON_THROW_ON_ERROR));
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->assertExists((string) $attachment->local_path);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://platform-api.max.ru/videos/forwarded-video-token');
        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://max.example/private/forwarded-video-480.mp4?'));
    }

    public function test_max_forwarded_media_webhook_stores_link_message_text_and_markup(): void
    {
        Queue::fake();
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        config()->set('bots.max.trusted_media_hosts', ['max.example']);
        Http::fake([
            'https://max.example/*' => Http::response(
                'max-forwarded-jpeg-bytes',
                200,
                ['Content-Type' => 'image/jpeg'],
            ),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);
        $payload = $this->maxPayload(
            messageId: 'max-forwarded-rich-media-101',
            text: null,
        );
        unset($payload['message']['body']['attachments']);
        $payload['message']['link'] = [
            'type' => 'forward',
            'sender' => [
                'name' => 'Герман Абрикосов',
                'is_bot' => false,
                'user_id' => 228532008,
            ],
            'message' => [
                'mid' => 'forwarded-source-101',
                'text' => "plain\nbold\nitalic under\nstrike",
                'markup' => [
                    [
                        'from' => 6,
                        'type' => 'strong',
                        'length' => 4,
                    ],
                    [
                        'from' => 11,
                        'type' => 'emphasized',
                        'length' => 12,
                    ],
                    [
                        'from' => 11,
                        'type' => 'underline',
                        'length' => 12,
                    ],
                    [
                        'from' => 24,
                        'type' => 'strikethrough',
                        'length' => 6,
                    ],
                ],
                'attachments' => [
                    [
                        'type' => 'image',
                        'payload' => [
                            'photo_id' => 'forwarded-photo-101',
                            'url' => 'https://max.example/private/photo.jpg?access_token=secret-token',
                            'token' => 'secret-token',
                        ],
                    ],
                ],
            ],
        ];

        $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $payload)->assertOk();

        $inboundMessage = $this->inboundMessages()->firstOrFail();
        $attachment = MessageAttachment::query()->where('message_id', $inboundMessage->id)->firstOrFail();

        $this->assertSame("plain\nbold\nitalic under\nstrike", $inboundMessage->text);
        $this->assertEquals([
            'version' => 1,
            'plain_text' => "plain\nbold\nitalic under\nstrike",
            'runs' => [
                [
                    'text' => "plain\n",
                    'marks' => [],
                ],
                [
                    'text' => 'bold',
                    'marks' => [
                        ['type' => 'bold'],
                    ],
                ],
                [
                    'text' => "\n",
                    'marks' => [],
                ],
                [
                    'text' => 'italic under',
                    'marks' => [
                        ['type' => 'italic'],
                        ['type' => 'underline'],
                    ],
                ],
                [
                    'text' => "\n",
                    'marks' => [],
                ],
                [
                    'text' => 'strike',
                    'marks' => [
                        ['type' => 'strikethrough'],
                    ],
                ],
            ],
        ], $inboundMessage->rich_text);
        $this->assertSame(MessageAttachment::PROVIDER_MAX_BOT, $attachment->provider);
        $this->assertSame('max-forwarded-rich-media-101', $attachment->provider_event_key);
        $this->assertSame('forwarded-photo-101', $attachment->provider_attachment_key);
        $this->assertSame(MessageAttachment::MEDIA_KIND_IMAGE, $attachment->media_kind);
        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED, $attachment->download_status);
        $this->assertStringNotContainsString('secret-token', json_encode($attachment->provider_metadata, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('access_token', json_encode($attachment->raw_payload_excerpt, JSON_THROW_ON_ERROR));
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->assertExists((string) $attachment->local_path);
    }

    public function test_max_video_webhook_downloads_real_okcdn_media_url_by_default(): void
    {
        Queue::fake();
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        Http::fake([
            'https://platform-api.max.ru/videos/max-okcdn-video-token' => Http::response([
                'token' => 'max-okcdn-video-token',
                'urls' => [
                    'mp4_720' => 'https://maxvd369.okcdn.ru/?expires=1782842206501&srcIp=0.0.0.0&type=2&sig=signed&ct=0',
                ],
                'duration' => 21,
            ]),
            'https://maxvd369.okcdn.ru/*' => Http::response(
                'max-okcdn-video-bytes',
                200,
                ['Content-Type' => 'video/mp4'],
            ),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);
        $payload = $this->maxPayload(
            messageId: 'max-okcdn-video-106',
            text: null,
        );
        $payload['message']['body']['attachments'] = [
            [
                'type' => 'video',
                'payload' => [
                    'id' => 14963193636361,
                    'token' => 'max-okcdn-video-token',
                    'url' => 'https://maxvd369.okcdn.ru/?expires=1782842206501&srcIp=0.0.0.0&type=2&sig=signed&ct=0',
                ],
                'duration' => 21,
            ],
        ];

        $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $payload)->assertOk();

        $inboundMessage = $this->inboundMessages()->firstOrFail();
        $attachment = MessageAttachment::query()->where('message_id', $inboundMessage->id)->firstOrFail();

        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED, $attachment->download_status);
        $this->assertSame('video/mp4', $attachment->mime_type);
        $this->assertSame('mp4', $attachment->extension);
        $this->assertSame(MessageAttachment::LOCAL_DISK_PRIVATE, $attachment->local_disk);
        $this->assertTrue($attachment->isInlinePreviewable());
        $this->assertSame(MessageAttachment::PREVIEW_KIND_VIDEO, $attachment->previewKind());
        $this->assertStringNotContainsString('max-okcdn-video-token', json_encode($attachment->provider_metadata, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('expires', json_encode($attachment->raw_payload_excerpt, JSON_THROW_ON_ERROR));
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->assertExists((string) $attachment->local_path);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://platform-api.max.ru/videos/max-okcdn-video-token');
        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://maxvd369.okcdn.ru/?'));
    }

    public function test_max_file_webhook_downloads_previewable_pdf_attachment_without_secret_fields(): void
    {
        Queue::fake();
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        config()->set('bots.max.trusted_media_hosts', ['max.example']);
        Http::fake([
            'https://max.example/*' => Http::response(
                "%PDF-1.4\nmax-pdf-bytes",
                200,
                ['Content-Type' => 'application/pdf'],
            ),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);
        $payload = $this->maxPayload(
            messageId: 'max-file-104',
            text: null,
        );
        $payload['message']['body']['attachments'] = [
            [
                'type' => 'file',
                'filename' => 'contract.pdf',
                'size' => 12000,
                'payload' => [
                    'token' => 'max-file-token',
                    'url' => 'https://max.example/private/contract.pdf?access_token=secret-token',
                ],
            ],
        ];

        $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $payload)->assertOk();

        $inboundMessage = $this->inboundMessages()->firstOrFail();
        $attachment = MessageAttachment::query()->where('message_id', $inboundMessage->id)->firstOrFail();

        $this->assertSame(MessageAttachment::PROVIDER_MAX_BOT, $attachment->provider);
        $this->assertSame('max-file-104', $attachment->provider_event_key);
        $this->assertSame('token:'.sha1('max-file-token'), $attachment->provider_attachment_key);
        $this->assertSame('token:'.sha1('max-file-token'), $attachment->provider_file_reference);
        $this->assertSame(MessageAttachment::MEDIA_KIND_DOCUMENT, $attachment->media_kind);
        $this->assertSame('contract.pdf', $attachment->original_filename);
        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED, $attachment->download_status);
        $this->assertSame('application/pdf', $attachment->mime_type);
        $this->assertSame('pdf', $attachment->extension);
        $this->assertSame(MessageAttachment::LOCAL_DISK_PRIVATE, $attachment->local_disk);
        $this->assertFalse($attachment->isInlinePreviewable());
        $this->assertNull($attachment->previewKind());
        $this->assertStringNotContainsString('max-file-token', json_encode($attachment->provider_metadata, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('secret-token', json_encode($attachment->provider_metadata, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('access_token', json_encode($attachment->raw_payload_excerpt, JSON_THROW_ON_ERROR));
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->assertExists((string) $attachment->local_path);
    }

    public function test_max_media_download_rejects_urls_with_connection_parts(): void
    {
        Queue::fake();
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        config()->set('bots.max.trusted_media_hosts', ['max.example']);
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);

        foreach ([
            'port' => 'https://max.example:444/private/audio.mp3',
            'user' => 'https://user@max.example/private/audio.mp3',
            'password' => 'https://user:pass@max.example/private/audio.mp3',
        ] as $case => $url) {
            $payload = $this->maxPayload(
                messageId: 'max-bad-url-'.$case,
                text: null,
            );
            $payload['message']['body']['attachments'] = [
                [
                    'type' => 'audio',
                    'payload' => [
                        'token' => 'max-bad-url-token-'.$case,
                        'url' => $url,
                    ],
                ],
            ];

            $this->withHeaders([
                'X-Max-Bot-Api-Secret' => 'max-secret',
            ])->postJson("/webhooks/max/{$channel->id}", $payload)->assertOk();

            $attachment = MessageAttachment::query()
                ->where('provider_event_key', 'max-bad-url-'.$case)
                ->firstOrFail();

            $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED, $attachment->download_status);
            $this->assertSame('bot_media_download_invalid_payload', $attachment->safe_error_code);
            $this->assertNull($attachment->local_path);
        }

        Http::assertNothingSent();
    }

    public function test_max_bot_started_webhook_queues_only_auto_reply_runtime_job_when_parameter_is_present(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);

        $response = $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $this->maxBotStartedPayload(payload: 'promo_123'));

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $storedMessage = $this->inboundMessages()->firstOrFail();

        Queue::assertPushed(ProcessAutoReplyJob::class, function (ProcessAutoReplyJob $job) use ($storedMessage): bool {
            return $job->inboundMessageId === $storedMessage->id;
        });
        Queue::assertNotPushed(ProcessDataCollectionResponseJob::class);
        Queue::assertNotPushed(ProcessPhoneCaptureFollowUpJob::class);
        Queue::assertNotPushed(ExportMessageToBitrix24OpenLinesJob::class);
        Http::assertNothingSent();

        $this->assertSame(Message::KIND_INBOUND_USER, $storedMessage->message_kind);
        $this->assertNull($storedMessage->text);
        $this->assertNull($storedMessage->external_message_id);
        $this->assertSame('promo_123', $storedMessage->message_parameter);
        $this->assertSame('bot_started', data_get($storedMessage->raw_payload, 'update_type'));
        $this->assertStringStartsWith('max-bot-started:', $storedMessage->provider_event_key ?? '');
        $this->assertDatabaseHas('contact_identities', [
            'channel_id' => $channel->id,
            'platform' => Channel::PLATFORM_MAX,
            'external_user_id' => '500',
            'external_username' => 'max_user',
        ]);
    }

    public function test_max_bot_started_webhook_without_parameter_remains_store_only(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);

        $response = $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $this->maxBotStartedPayload(payload: '   '));

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        Queue::assertNotPushed(ProcessDataCollectionResponseJob::class);
        Queue::assertNotPushed(ProcessPhoneCaptureFollowUpJob::class);

        $storedMessage = $this->inboundMessages()->firstOrFail();

        $this->assertNull($storedMessage->message_parameter);
        $this->assertSame('bot_started', data_get($storedMessage->raw_payload, 'update_type'));
    }

    public function test_max_bot_started_with_parameter_skips_auto_reply_when_scenario_dispatcher_consumes_message(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);

        $dispatcher = Mockery::mock(DispatchStoredInboundScenarioAction::class);
        $dispatcher->shouldReceive('shouldBlockVipIbizaParameterStartBecauseBusyState')->once()->andReturn(false);
        $dispatcher->shouldReceive('handle')->once()->andReturn(true);
        $this->app->instance(DispatchStoredInboundScenarioAction::class, $dispatcher);

        $response = $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $this->maxBotStartedPayload(payload: 'promo_123'));

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        Queue::assertNotPushed(ProcessDataCollectionResponseJob::class);
        Queue::assertNotPushed(ProcessPhoneCaptureFollowUpJob::class);
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_max_text_message_checks_priority_scenario_before_active_run(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);

        $dispatcher = Mockery::mock(DispatchStoredInboundScenarioAction::class);
        $dispatcher->shouldReceive('shouldBlockVipIbizaParameterStartBecauseBusyState')
            ->once()
            ->ordered()
            ->andReturn(false);
        $dispatcher->shouldReceive('startPriorityScenario')
            ->once()
            ->ordered()
            ->andReturn(true);
        $dispatcher->shouldNotReceive('continueActiveRun');
        $dispatcher->shouldNotReceive('startMatchingScenario');
        $this->app->instance(DispatchStoredInboundScenarioAction::class, $dispatcher);

        $response = $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $this->maxPayload(
            messageId: 'max-priority-start-1',
            text: 'старт',
        ));

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        Queue::assertNotPushed(ProcessDataCollectionResponseJob::class);
        Queue::assertNotPushed(ProcessPhoneCaptureFollowUpJob::class);
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_text_message_checks_priority_scenario_before_regular_flow(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $dispatcher = Mockery::mock(DispatchStoredInboundScenarioAction::class);
        $dispatcher->shouldReceive('shouldBlockVipIbizaParameterStartBecauseBusyState')
            ->once()
            ->ordered()
            ->andReturn(false);
        $dispatcher->shouldReceive('startPriorityScenario')
            ->once()
            ->ordered()
            ->andReturn(true);
        $dispatcher->shouldNotReceive('continueActiveRun');
        $dispatcher->shouldNotReceive('startMatchingScenario');
        $this->app->instance(DispatchStoredInboundScenarioAction::class, $dispatcher);

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload(
            messageId: 9001,
            text: 'удалить',
        ));

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        Queue::assertNotPushed(ProcessDataCollectionResponseJob::class);
        Queue::assertNotPushed(ProcessPhoneCaptureFollowUpJob::class);
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_max_bot_started_webhook_queues_only_auto_reply_for_contact_in_active_data_collection(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);

        $contact = Contact::factory()->create([
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_ACTIVE,
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_FIRST_NAME,
        ]);

        ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => Channel::PLATFORM_MAX,
            'external_user_id' => '500',
            'external_username' => 'max_user',
        ]);

        $response = $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $this->maxBotStartedPayload(payload: 'promo_123'));

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $storedMessage = $this->inboundMessages()->latest('id')->firstOrFail();

        Queue::assertNotPushed(ProcessDataCollectionResponseJob::class);
        Queue::assertPushed(ProcessAutoReplyJob::class, function (ProcessAutoReplyJob $job) use ($storedMessage): bool {
            return $job->inboundMessageId === $storedMessage->id;
        });
        Queue::assertNotPushed(ExportMessageToBitrix24OpenLinesJob::class);

        $this->assertSame($contact->id, $storedMessage->contact_id);
        $this->assertSame('promo_123', $storedMessage->message_parameter);
        $this->assertSame('bot_started', data_get($storedMessage->raw_payload, 'update_type'));
    }

    public function test_repeated_max_bot_started_webhook_with_parameter_still_queues_auto_reply_for_contact_in_active_data_collection(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);

        $contact = Contact::factory()->create([
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_ACTIVE,
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_FIRST_NAME,
        ]);

        ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => Channel::PLATFORM_MAX,
            'external_user_id' => '500',
            'external_username' => 'max_user',
        ]);

        $headers = [
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ];
        $payload = $this->maxBotStartedPayload(payload: 'promo_123');

        $this->withHeaders($headers)
            ->postJson("/webhooks/max/{$channel->id}", $payload)
            ->assertOk()
            ->assertExactJson([
                'ok' => true,
            ]);

        $storedMessage = $this->inboundMessages()->latest('id')->firstOrFail();

        $this->withHeaders($headers)
            ->postJson("/webhooks/max/{$channel->id}", $payload)
            ->assertOk()
            ->assertExactJson([
                'ok' => true,
            ]);

        Queue::assertPushed(ProcessAutoReplyJob::class, function (ProcessAutoReplyJob $job) use ($storedMessage): bool {
            return $job->inboundMessageId === $storedMessage->id;
        });
        Queue::assertPushed(ProcessAutoReplyJob::class, 2);
        Queue::assertNotPushed(ProcessDataCollectionResponseJob::class);
        Queue::assertNotPushed(ExportMessageToBitrix24OpenLinesJob::class);
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_max_vip_ibiza_parameter_start_with_active_run_sends_blocking_reply(): void
    {
        Queue::fake();
        Http::fake([
            'https://platform-api.max.ru/*' => Http::response([
                'message' => [
                    'body' => [
                        'mid' => 'max-out-501',
                    ],
                ],
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);

        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '500',
            'external_username' => 'max_user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => '700',
        ]);

        $scenario = $this->createPublishedScenario('vip_ibiza', [
            'version' => 1,
            'start_block_id' => 'welcome',
            'triggers' => [
                [
                    'type' => 'parameter',
                    'value' => 'vip_ibiza_apply',
                ],
                [
                    'type' => 'parameter',
                    'value' => 'vip_ibiza_tg1',
                ],
                [
                    'type' => 'parameter',
                    'value' => 'vip_ibiza_inst1',
                ],
            ],
            'blocks' => [
                'welcome' => [
                    'type' => 'message',
                    'text' => 'Добро пожаловать',
                    'next' => 'capture_phone',
                ],
                'capture_phone' => [
                    'type' => 'phone_capture',
                    'text' => 'Поделитесь номером телефона.',
                    'next' => 'end',
                ],
                'end' => [
                    'type' => 'complete',
                ],
            ],
        ]);

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $otherScenario = $this->createPublishedScenario('other_flow', [
            'version' => 1,
            'start_block_id' => 'welcome',
            'triggers' => [
                [
                    'type' => 'parameter',
                    'value' => 'other_flow',
                ],
            ],
            'blocks' => [
                'welcome' => [
                    'type' => 'message',
                    'text' => 'Добро пожаловать',
                    'next' => 'end',
                ],
                'end' => [
                    'type' => 'complete',
                ],
            ],
        ]);

        $run = ScenarioRun::query()->create([
            'dialog_id' => $dialog->id,
            'scenario_code' => $otherScenario->code,
            'status' => ScenarioRun::STATUS_ACTIVE,
            'current_step' => 'awaiting_topic',
            'state_payload' => [],
            'started_at' => now()->subMinute(),
        ]);

        $response = $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $this->maxBotStartedPayload(payload: 'vip_ibiza_apply'));

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $storedMessage = $this->inboundMessages()->firstOrFail();
        $run->refresh();

        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame($otherScenario->code, $run->scenario_code);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://platform-api.max.ru/messages?chat_id=700'
            && $request['text'] === 'У тебя уже идёт сбор данных. Сначала заверши его.');

        Queue::assertNotPushed(ProcessScenarioInboundJob::class);
        Queue::assertNotPushed(ProcessScenarioStartJob::class);
        Queue::assertNotPushed(ProcessDataCollectionResponseJob::class);
        Queue::assertNotPushed(ProcessAutoReplyJob::class);

        $this->assertNotNull($storedMessage->fresh()->auto_reply_sent_at);
        $this->assertDatabaseHas('messages', [
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_AUTO_REPLY,
            'reply_to_message_id' => $storedMessage->id,
            'text' => 'У тебя уже идёт сбор данных. Сначала заверши его.',
        ]);
    }

    public function test_repeated_max_vip_ibiza_parameter_start_with_busy_state_sends_blocking_reply_only_once(): void
    {
        Queue::fake();
        Http::fake([
            'https://platform-api.max.ru/*' => Http::response([
                'message' => [
                    'body' => [
                        'mid' => 'max-out-502',
                    ],
                ],
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);

        $contact = Contact::factory()->create([
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_ACTIVE,
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_FIRST_NAME,
        ]);
        ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '500',
            'external_username' => 'max_user',
        ]);

        $headers = [
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ];
        $payload = $this->maxBotStartedPayload(payload: 'vip_ibiza_inst1');

        $this->withHeaders($headers)
            ->postJson("/webhooks/max/{$channel->id}", $payload)
            ->assertOk();

        $this->withHeaders($headers)
            ->postJson("/webhooks/max/{$channel->id}", $payload)
            ->assertOk();

        $message = $this->inboundMessages()->firstOrFail();

        $this->assertNotNull($message->auto_reply_sent_at);
        Http::assertSentCount(1);
        Queue::assertNotPushed(ProcessScenarioInboundJob::class);
        Queue::assertNotPushed(ProcessScenarioStartJob::class);
        Queue::assertNotPushed(ProcessDataCollectionResponseJob::class);
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        $this->assertDatabaseCount('messages', 2);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'webhook.duplicate_ignored',
        ]);
    }

    public function test_max_vip_ibiza_parameter_start_with_active_collector_and_no_active_run_sends_blocking_reply(): void
    {
        Queue::fake();
        Http::fake([
            'https://platform-api.max.ru/*' => Http::response([
                'message' => [
                    'body' => [
                        'mid' => 'max-out-503',
                    ],
                ],
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);

        $contact = Contact::factory()->create([
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_ACTIVE,
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_FIRST_NAME,
        ]);
        ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '500',
            'external_username' => 'max_user',
        ]);

        $scenario = $this->createPublishedScenario('vip_ibiza', [
            'version' => 1,
            'start_block_id' => 'welcome',
            'triggers' => [
                [
                    'type' => 'parameter',
                    'value' => 'vip_ibiza_apply',
                ],
                [
                    'type' => 'parameter',
                    'value' => 'vip_ibiza_tg1',
                ],
                [
                    'type' => 'parameter',
                    'value' => 'vip_ibiza_inst1',
                ],
            ],
            'blocks' => [
                'welcome' => [
                    'type' => 'message',
                    'text' => 'Добро пожаловать',
                    'next' => 'end',
                ],
                'end' => [
                    'type' => 'complete',
                ],
            ],
        ]);

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $response = $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $this->maxBotStartedPayload(payload: 'vip_ibiza_tg1'));

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $storedMessage = $this->inboundMessages()->firstOrFail();

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://platform-api.max.ru/messages?chat_id=700'
            && $request['text'] === 'У тебя уже идёт сбор данных. Сначала заверши его.');

        Queue::assertNotPushed(ProcessDataCollectionResponseJob::class);
        Queue::assertNotPushed(ProcessScenarioInboundJob::class);
        Queue::assertNotPushed(ProcessScenarioStartJob::class);
        Queue::assertNotPushed(ProcessAutoReplyJob::class);

        $this->assertNotNull($storedMessage->fresh()->auto_reply_sent_at);
        $this->assertDatabaseHas('messages', [
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_AUTO_REPLY,
            'reply_to_message_id' => $storedMessage->id,
            'text' => 'У тебя уже идёт сбор данных. Сначала заверши его.',
        ]);
        $this->assertDatabaseMissing('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.data_collection_response_queued',
        ]);
    }

    public function test_max_vip_ibiza_parameter_start_without_busy_state_starts_scenario_as_before(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);

        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '500',
            'external_username' => 'max_user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => '700',
        ]);

        $scenario = $this->createPublishedScenario('vip_ibiza', [
            'version' => 1,
            'start_block_id' => 'welcome',
            'triggers' => [
                [
                    'type' => 'parameter',
                    'value' => 'vip_ibiza_apply',
                ],
                [
                    'type' => 'parameter',
                    'value' => 'vip_ibiza_tg1',
                ],
                [
                    'type' => 'parameter',
                    'value' => 'vip_ibiza_inst1',
                ],
            ],
            'blocks' => [
                'welcome' => [
                    'type' => 'message',
                    'text' => 'Добро пожаловать',
                    'next' => 'capture_phone',
                ],
                'capture_phone' => [
                    'type' => 'phone_capture',
                    'text' => 'Поделитесь номером телефона.',
                    'next' => 'end',
                ],
                'end' => [
                    'type' => 'complete',
                ],
            ],
        ]);

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $response = $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $this->maxBotStartedPayload(payload: 'vip_ibiza_inst1'));

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $storedMessage = $this->inboundMessages()->firstOrFail();

        Queue::assertPushed(ProcessScenarioStartJob::class, function (ProcessScenarioStartJob $job) use ($storedMessage, $dialog, $scenario): bool {
            return $job->inboundMessageId === $storedMessage->id
                && $job->dialogId === $dialog->id
                && $job->scenarioCode === $scenario->code;
        });
        Queue::assertNotPushed(ProcessScenarioInboundJob::class);
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        $this->assertNull($storedMessage->fresh()->auto_reply_sent_at);
    }

    public function test_max_webhook_uses_real_payload_fields_for_contact_name_and_message_id(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);

        $timestamp = Carbon::create(2026, 3, 20, 12, 34, 56, 'UTC')->getTimestampMs() + 123;

        $payload = [
            'update_type' => 'message_created',
            'user_locale' => 'ru',
            'timestamp' => $timestamp,
            'message' => [
                'timestamp' => $timestamp,
                'sender' => [
                    'user_id' => 228532008,
                    'first_name' => 'German',
                    'last_name' => 'Abrikosov',
                    'username' => null,
                    'is_bot' => false,
                ],
                'recipient' => [
                    'chat_id' => 700,
                ],
                'body' => [
                    'mid' => 'max-mid-42',
                    'text' => 'Привет из MAX',
                ],
            ],
        ];

        $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $payload)->assertOk();

        Queue::assertPushed(ProcessAutoReplyJob::class);
        $this->assertDatabaseHas('contacts', [
            'first_name' => 'German Abrikosov',
            'first_name_source' => Contact::FIRST_NAME_SOURCE_AUTO,
        ]);
        $this->assertDatabaseHas('contact_identities', [
            'channel_id' => $channel->id,
            'external_user_id' => '228532008',
            'external_username' => null,
            'display_name' => 'German Abrikosov',
        ]);
        $this->assertDatabaseHas('messages', [
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_message_id' => 'max-mid-42',
            'text' => 'Привет из MAX',
        ]);

        $message = $this->inboundMessages()->firstOrFail();

        $this->assertSame(intdiv($timestamp, 1000), $message->received_at->getTimestamp());
        $this->assertSame('2026-03-20 12:34:56', $message->received_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_telegram_contact_share_webhook_saves_phone_and_queues_confirmation_follow_up(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $payload = $this->telegramPayload(messageId: 90, text: null);
        $payload['message']['contact'] = [
            'phone_number' => '+7 999 123 45 67',
            'user_id' => 200,
        ];

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $payload);

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $storedMessage = $this->inboundMessages()->firstOrFail();

        Queue::assertPushed(ProcessPhoneCaptureFollowUpJob::class, function (ProcessPhoneCaptureFollowUpJob $job) use ($storedMessage): bool {
            return $job->inboundMessageId === $storedMessage->id
                && $job->phoneCaptureStatus === StoredInboundMessageResult::PHONE_CAPTURE_STATUS_CAPTURED_NEW;
        });
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        Http::assertNothingSent();

        $this->assertSame(Message::KIND_INBOUND_CONTACT_SHARE, $storedMessage->message_kind);
        $this->assertDatabaseHas('contact_phone_numbers', [
            'contact_id' => $storedMessage->contact_id,
            'phone_raw' => '+7 999 123 45 67',
            'phone_normalized' => '+79991234567',
        ]);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.phone_captured',
        ]);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.phone_capture_confirmation_queued',
        ]);
    }

    public function test_telegram_contact_share_with_profile_name_still_asks_for_first_name(): void
    {
        Queue::fake();
        config()->set('bots.phone_capture_confirmation_text', 'Спасибо, номер получили.');
        config()->set('bots.data_collection.first_question', 'Как вас зовут?');

        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push([
                    'ok' => true,
                    'result' => [],
                ])
                ->push([
                    'ok' => true,
                    'result' => [
                        'message_id' => 9921,
                    ],
                ])
                ->push([
                    'ok' => true,
                    'result' => [
                        'message_id' => 9922,
                    ],
                ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $payload = $this->telegramPayload(messageId: 92, text: null);
        $payload['message']['from']['first_name'] = 'German';
        $payload['message']['from']['last_name'] = 'Abrikosov';
        $payload['message']['contact'] = [
            'phone_number' => '+7 999 123 45 67',
            'user_id' => 200,
        ];

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $payload);

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $storedMessage = $this->inboundMessages()->firstOrFail();
        $contact = $storedMessage->contact()->firstOrFail()->fresh();
        $identity = $storedMessage->contactIdentity()->firstOrFail()->fresh();

        Http::assertNothingSent();
        Queue::assertPushed(ProcessPhoneCaptureFollowUpJob::class, function (ProcessPhoneCaptureFollowUpJob $job) use ($storedMessage): bool {
            return $job->inboundMessageId === $storedMessage->id
                && $job->phoneCaptureStatus === StoredInboundMessageResult::PHONE_CAPTURE_STATUS_CAPTURED_NEW;
        });

        $this->assertSame('German Abrikosov', $contact->first_name);
        $this->assertSame(Contact::FIRST_NAME_SOURCE_AUTO, $contact->first_name_source);
        $this->assertSame('German Abrikosov', $identity->display_name);
    }

    public function test_telegram_contact_share_skips_follow_up_when_scenario_dispatcher_consumes_message(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $dispatcher = Mockery::mock(DispatchStoredInboundScenarioAction::class);
        $dispatcher->shouldReceive('continueActiveRun')->once()->andReturn(true);
        $this->app->instance(DispatchStoredInboundScenarioAction::class, $dispatcher);

        $payload = $this->telegramPayload(messageId: 91, text: null);
        $payload['message']['contact'] = [
            'phone_number' => '+7 999 123 45 67',
            'user_id' => 200,
        ];

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $payload);

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        Queue::assertNotPushed(ProcessPhoneCaptureFollowUpJob::class);
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        $this->assertSame(1, $this->inboundMessages()->count());
        $this->assertDatabaseMissing('messages', [
            'message_kind' => Message::KIND_OUTBOUND_PHONE_CAPTURE_CONFIRMATION,
        ]);
    }

    public function test_telegram_contact_share_suppresses_global_phone_confirmation_during_active_v3_run(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);
        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '200',
            'external_username' => 'telegram_user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => '300',
        ]);
        $scenario = $this->createPublishedScenario('v3_suppression', [
            'version' => 3,
            'builder_v3_runtime' => [
                'schema_version' => 3,
                'blocks' => [],
            ],
        ]);

        ScenarioRun::query()->create([
            'dialog_id' => $dialog->id,
            'scenario_code' => $scenario->code,
            'status' => ScenarioRun::STATUS_ACTIVE,
            'current_step' => 'some_block',
            'state_payload' => [
                'v3' => [
                    'schema_version' => 3,
                    'current_block_id' => 'some_block',
                    'status' => 'waiting_input',
                ],
            ],
            'started_at' => now()->subMinute(),
        ]);

        $payload = $this->telegramPayload(messageId: 910, text: null);
        $payload['message']['contact'] = [
            'phone_number' => '+7 999 123 45 67',
            'user_id' => 200,
        ];

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $payload);

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $storedMessage = $this->inboundMessages()->firstOrFail();

        Queue::assertNotPushed(ProcessPhoneCaptureFollowUpJob::class);
        Queue::assertNotPushed(ProcessScenarioInboundJob::class);
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        $this->assertSame(Message::KIND_INBOUND_CONTACT_SHARE, $storedMessage->message_kind);
        $this->assertDatabaseHas('contact_phone_numbers', [
            'contact_id' => $contact->id,
            'phone_raw' => '+7 999 123 45 67',
            'phone_normalized' => '+79991234567',
        ]);
        $this->assertDatabaseMissing('messages', [
            'message_kind' => Message::KIND_OUTBOUND_PHONE_CAPTURE_CONFIRMATION,
        ]);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.phone_capture_confirmation_suppressed_for_v3',
        ]);
    }

    public function test_max_contact_share_suppresses_global_phone_confirmation_after_v3_request_contact_prompt(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);
        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '500',
            'external_username' => 'max_user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => '700',
        ]);

        Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_SCENARIO_MESSAGE,
            'sent_by_type' => Message::SENT_BY_TYPE_SYSTEM,
            'sent_by_system_code' => 'scenario_'.Scenario::CONSTRUCTOR_WORKSPACE_CODE,
            'external_chat_id' => '700',
            'text' => 'Стартовое при любом сообщении (серый блок)',
            'raw_payload' => [
                'message' => [
                    'body' => [
                        'attachments' => [[
                            'type' => 'inline_keyboard',
                            'payload' => [
                                'buttons' => [[[
                                    'type' => 'request_contact',
                                    'text' => '1',
                                ]]],
                            ],
                        ]],
                    ],
                ],
            ],
            'created_at' => now()->subMinute(),
        ]);

        $payload = $this->maxPayload(messageId: 'max-v3-contact-91', text: null);
        $payload['message']['body'] = [
            'mid' => 'max-v3-contact-91',
            'contact' => [
                'phone' => '+7 999 123 45 67',
                'user_id' => 500,
            ],
        ];

        $response = $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $payload);

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $storedMessage = $this->inboundMessages()->firstOrFail();

        Queue::assertNotPushed(ProcessPhoneCaptureFollowUpJob::class);
        Queue::assertNotPushed(ProcessScenarioInboundJob::class);
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        $this->assertSame(Message::KIND_INBOUND_CONTACT_SHARE, $storedMessage->message_kind);
        $this->assertDatabaseHas('contact_phone_numbers', [
            'contact_id' => $contact->id,
            'phone_raw' => '+7 999 123 45 67',
            'phone_normalized' => '+79991234567',
            'source' => ContactPhoneNumber::SOURCE_MAX_CONTACT_SHARE,
        ]);
        $this->assertDatabaseMissing('messages', [
            'message_kind' => Message::KIND_OUTBOUND_PHONE_CAPTURE_CONFIRMATION,
        ]);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.phone_capture_confirmation_suppressed_for_v3',
        ]);
    }

    public function test_active_scenario_run_has_priority_over_active_data_collection_for_inbound_user(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $contact = Contact::factory()->create([
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_ACTIVE,
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_FIRST_NAME,
            'data_collection_last_prompted_field' => Contact::DATA_COLLECTION_FIELD_FIRST_NAME,
            'data_collection_started_at' => now(),
        ]);

        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '200',
            'external_username' => 'telegram_user',
        ]);

        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => '300',
        ]);

        $scenario = $this->createPublishedScenario(code: 'vip_ibiza_apply');

        $run = ScenarioRun::query()->create([
            'dialog_id' => $dialog->id,
            'scenario_code' => $scenario->code,
            'status' => ScenarioRun::STATUS_ACTIVE,
            'current_step' => 'welcome',
            'state_payload' => [],
            'started_at' => now()->subMinute(),
        ]);

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload(
            userId: 200,
            chatId: 300,
            messageId: 902,
            text: 'Герман',
        ));

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $storedMessage = $this->inboundMessages()->firstOrFail();

        Queue::assertPushed(ProcessScenarioInboundJob::class, function (ProcessScenarioInboundJob $job) use ($storedMessage, $run): bool {
            return $job->inboundMessageId === $storedMessage->id
                && $job->scenarioRunId === $run->id;
        });
        Queue::assertNotPushed(ProcessDataCollectionResponseJob::class);
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
    }

    public function test_telegram_contact_share_with_active_database_run_falls_back_to_legacy_phone_follow_up(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '200',
            'external_username' => 'telegram_user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => '300',
        ]);

        $scenario = $this->createPublishedScenario(code: 'vip_ibiza_apply');

        ScenarioRun::query()->create([
            'dialog_id' => $dialog->id,
            'scenario_code' => $scenario->code,
            'status' => ScenarioRun::STATUS_ACTIVE,
            'current_step' => 'welcome',
            'state_payload' => [],
            'started_at' => now()->subMinute(),
        ]);

        $payload = $this->telegramPayload(messageId: 93, text: null);
        $payload['message']['contact'] = [
            'phone_number' => '+7 999 123 45 67',
            'user_id' => 200,
        ];

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $payload);

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        Queue::assertPushed(ProcessPhoneCaptureFollowUpJob::class);
        Queue::assertNotPushed(ProcessScenarioInboundJob::class);
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
    }

    public function test_telegram_contact_share_with_active_database_run_on_phone_capture_queues_scenario_inbound_job(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '200',
            'external_username' => 'telegram_user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => '300',
        ]);

        $scenario = $this->createPublishedScenario('vip_ibiza_apply', [
            'version' => 1,
            'start_block_id' => 'welcome',
            'triggers' => [
                [
                    'type' => 'parameter',
                    'value' => 'vip_ibiza_apply',
                ],
            ],
            'blocks' => [
                'welcome' => [
                    'type' => 'message',
                    'text' => 'Добро пожаловать',
                    'next' => 'capture_phone',
                ],
                'capture_phone' => [
                    'type' => 'phone_capture',
                    'text' => 'Поделитесь номером телефона.',
                    'next' => 'end',
                ],
                'end' => [
                    'type' => 'complete',
                ],
            ],
        ]);

        $run = ScenarioRun::query()->create([
            'dialog_id' => $dialog->id,
            'scenario_code' => $scenario->code,
            'status' => ScenarioRun::STATUS_ACTIVE,
            'current_step' => 'capture_phone',
            'state_payload' => [],
            'started_at' => now()->subMinute(),
        ]);

        $payload = $this->telegramPayload(messageId: 94, text: null);
        $payload['message']['contact'] = [
            'phone_number' => '+7 999 123 45 67',
            'user_id' => 200,
        ];

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $payload);

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $storedMessage = $this->inboundMessages()->firstOrFail();
        $contact->refresh();
        $dialog->refresh();

        Queue::assertPushed(ProcessScenarioInboundJob::class, function (ProcessScenarioInboundJob $job) use ($storedMessage, $run): bool {
            return $job->inboundMessageId === $storedMessage->id
                && $job->scenarioRunId === $run->id;
        });
        Queue::assertNotPushed(ProcessPhoneCaptureFollowUpJob::class);
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        $this->assertSame(Message::KIND_INBOUND_CONTACT_SHARE, $storedMessage->message_kind);
        $this->assertDatabaseHas('contact_phone_numbers', [
            'contact_id' => $contact->id,
            'phone_raw' => '+7 999 123 45 67',
            'phone_normalized' => '+79991234567',
        ]);
        $this->assertSame('+7 999 123 45 67', $dialog->confirmed_phone_raw);
        $this->assertSame('+79991234567', $dialog->confirmed_phone_normalized);
    }

    public function test_telegram_contact_share_with_active_database_run_on_phone_capture_and_sender_mismatch_does_not_queue_scenario_inbound_job(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '200',
            'external_username' => 'telegram_user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => '300',
        ]);

        $scenario = $this->createPublishedScenario('vip_ibiza_apply', [
            'version' => 1,
            'start_block_id' => 'capture_phone',
            'triggers' => [
                [
                    'type' => 'parameter',
                    'value' => 'vip_ibiza_apply',
                ],
            ],
            'blocks' => [
                'capture_phone' => [
                    'type' => 'phone_capture',
                    'text' => 'Поделитесь номером телефона.',
                    'next' => 'end',
                ],
                'end' => [
                    'type' => 'complete',
                ],
            ],
        ]);

        ScenarioRun::query()->create([
            'dialog_id' => $dialog->id,
            'scenario_code' => $scenario->code,
            'status' => ScenarioRun::STATUS_ACTIVE,
            'current_step' => 'capture_phone',
            'state_payload' => [],
            'started_at' => now()->subMinute(),
        ]);

        $payload = $this->telegramPayload(messageId: 95, text: null);
        $payload['message']['contact'] = [
            'phone_number' => '+7 999 123 45 67',
            'user_id' => 999,
        ];

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $payload);

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        Queue::assertNotPushed(ProcessScenarioInboundJob::class);
        Queue::assertNotPushed(ProcessPhoneCaptureFollowUpJob::class);
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        $this->assertDatabaseCount('contact_phone_numbers', 0);
    }

    public function test_telegram_vip_ibiza_deep_link_with_active_vip_ibiza_run_sends_blocking_reply_without_restart(): void
    {
        Queue::fake();
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 501,
                ],
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '200',
            'external_username' => 'telegram_user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => '300',
        ]);

        $scenario = $this->createPublishedScenario('vip_ibiza', [
            'version' => 1,
            'start_block_id' => 'welcome',
            'triggers' => [
                [
                    'type' => 'parameter',
                    'value' => 'vip_ibiza_apply',
                ],
            ],
            'blocks' => [
                'welcome' => [
                    'type' => 'message',
                    'text' => 'Добро пожаловать',
                    'next' => 'capture_phone',
                ],
                'capture_phone' => [
                    'type' => 'phone_capture',
                    'text' => 'Поделитесь номером телефона.',
                    'next' => 'end',
                ],
                'end' => [
                    'type' => 'complete',
                ],
            ],
        ]);

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $run = ScenarioRun::query()->create([
            'dialog_id' => $dialog->id,
            'scenario_code' => $scenario->code,
            'status' => ScenarioRun::STATUS_ACTIVE,
            'current_step' => 'capture_phone',
            'state_payload' => [
                'run' => [
                    'budget_tier' => '15,000 USD и выше',
                ],
            ],
            'started_at' => now()->subMinutes(10),
        ]);

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload(
            userId: 200,
            chatId: 300,
            messageId: 903,
            text: '/start vip_ibiza_apply',
        ));

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $storedMessage = $this->inboundMessages()->firstOrFail();
        $run->refresh();

        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('capture_phone', $run->current_step);
        $this->assertNull($run->exit_outcome);
        $this->assertNull($run->finished_at);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === '300'
            && $request['text'] === 'У тебя уже идёт сбор данных. Сначала заверши его.');

        Queue::assertNotPushed(ProcessScenarioInboundJob::class);
        Queue::assertNotPushed(ProcessScenarioStartJob::class);
        Queue::assertNotPushed(ProcessDataCollectionResponseJob::class);
        Queue::assertNotPushed(ProcessAutoReplyJob::class);

        $this->assertNotNull($storedMessage->fresh()->auto_reply_sent_at);
        $this->assertDatabaseHas('messages', [
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_AUTO_REPLY,
            'reply_to_message_id' => $storedMessage->id,
            'text' => 'У тебя уже идёт сбор данных. Сначала заверши его.',
        ]);
    }

    public function test_repeated_telegram_vip_ibiza_deep_link_with_active_run_sends_blocking_reply_only_once(): void
    {
        Queue::fake();
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 502,
                ],
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '200',
            'external_username' => 'telegram_user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => '300',
        ]);

        $scenario = $this->createPublishedScenario('vip_ibiza', [
            'version' => 1,
            'start_block_id' => 'welcome',
            'triggers' => [
                [
                    'type' => 'parameter',
                    'value' => 'vip_ibiza_apply',
                ],
            ],
            'blocks' => [
                'welcome' => [
                    'type' => 'message',
                    'text' => 'Добро пожаловать',
                    'next' => 'capture_phone',
                ],
                'capture_phone' => [
                    'type' => 'phone_capture',
                    'text' => 'Поделитесь номером телефона.',
                    'next' => 'end',
                ],
                'end' => [
                    'type' => 'complete',
                ],
            ],
        ]);

        ScenarioRun::query()->create([
            'dialog_id' => $dialog->id,
            'scenario_code' => $scenario->code,
            'status' => ScenarioRun::STATUS_ACTIVE,
            'current_step' => 'capture_phone',
            'state_payload' => [],
            'started_at' => now()->subMinute(),
        ]);

        $headers = [
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ];
        $payload = $this->telegramPayload(
            userId: 200,
            chatId: 300,
            messageId: 904,
            text: '/start vip_ibiza_apply',
        );

        $this->withHeaders($headers)
            ->postJson("/webhooks/telegram/{$channel->id}", $payload)
            ->assertOk();

        $this->withHeaders($headers)
            ->postJson("/webhooks/telegram/{$channel->id}", $payload)
            ->assertOk();

        $run = ScenarioRun::query()->active()->where('dialog_id', $dialog->id)->firstOrFail();
        $message = $this->inboundMessages()->firstOrFail();

        $this->assertSame($scenario->code, $run->scenario_code);
        $this->assertSame('capture_phone', $run->current_step);
        $this->assertNotNull($message->auto_reply_sent_at);

        Http::assertSentCount(1);
        Queue::assertNotPushed(ProcessScenarioInboundJob::class);
        Queue::assertNotPushed(ProcessScenarioStartJob::class);
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        $this->assertDatabaseCount('messages', 2);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'webhook.duplicate_ignored',
        ]);
    }

    public function test_plain_telegram_start_does_not_trigger_vip_ibiza_active_run_guard(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '200',
            'external_username' => 'telegram_user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => '300',
        ]);

        $scenario = $this->createPublishedScenario('vip_ibiza', [
            'version' => 1,
            'start_block_id' => 'welcome',
            'triggers' => [
                [
                    'type' => 'parameter',
                    'value' => 'vip_ibiza_apply',
                ],
                [
                    'type' => 'parameter',
                    'value' => 'vip_ibiza_tg1',
                ],
                [
                    'type' => 'parameter',
                    'value' => 'vip_ibiza_inst1',
                ],
            ],
            'blocks' => [
                'welcome' => [
                    'type' => 'message',
                    'text' => 'Добро пожаловать',
                    'next' => 'capture_phone',
                ],
                'capture_phone' => [
                    'type' => 'phone_capture',
                    'text' => 'Поделитесь номером телефона.',
                    'next' => 'end',
                ],
                'end' => [
                    'type' => 'complete',
                ],
            ],
        ]);

        ScenarioRun::query()->create([
            'dialog_id' => $dialog->id,
            'scenario_code' => $scenario->code,
            'status' => ScenarioRun::STATUS_ACTIVE,
            'current_step' => 'capture_phone',
            'state_payload' => [],
            'started_at' => now()->subMinute(),
        ]);

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload(
            userId: 200,
            chatId: 300,
            messageId: 905,
            text: '/start',
        ));

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $run = ScenarioRun::query()->active()->where('dialog_id', $dialog->id)->firstOrFail();

        $this->assertSame($scenario->code, $run->scenario_code);
        $this->assertSame('capture_phone', $run->current_step);

        Queue::assertPushed(ProcessScenarioInboundJob::class);
        Queue::assertNotPushed(ProcessScenarioStartJob::class);
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
    }

    public function test_telegram_vip_ibiza_deep_link_with_other_active_scenario_sends_blocking_reply(): void
    {
        Queue::fake();
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 503,
                ],
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '200',
            'external_username' => 'telegram_user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => '300',
        ]);

        $this->createPublishedScenario('other_flow', [
            'version' => 1,
            'start_block_id' => 'welcome',
            'triggers' => [
                [
                    'type' => 'parameter',
                    'value' => 'other_flow',
                ],
            ],
            'blocks' => [
                'welcome' => [
                    'type' => 'message',
                    'text' => 'Добро пожаловать',
                    'next' => 'end',
                ],
                'end' => [
                    'type' => 'complete',
                ],
            ],
        ]);

        ScenarioRun::query()->create([
            'dialog_id' => $dialog->id,
            'scenario_code' => 'other_flow',
            'status' => ScenarioRun::STATUS_ACTIVE,
            'current_step' => 'awaiting_topic',
            'state_payload' => [],
            'started_at' => now()->subMinute(),
        ]);

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload(
            userId: 200,
            chatId: 300,
            messageId: 906,
            text: '/start vip_ibiza_inst1',
        ));

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $run = ScenarioRun::query()->active()->where('dialog_id', $dialog->id)->firstOrFail();
        $storedMessage = $this->inboundMessages()->firstOrFail();

        $this->assertSame('other_flow', $run->scenario_code);

        Queue::assertNotPushed(ProcessScenarioInboundJob::class);
        Queue::assertNotPushed(ProcessScenarioStartJob::class);
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        $this->assertNotNull($storedMessage->fresh()->auto_reply_sent_at);
        $this->assertDatabaseHas('messages', [
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_AUTO_REPLY,
            'reply_to_message_id' => $storedMessage->id,
            'text' => 'У тебя уже идёт сбор данных. Сначала заверши его.',
        ]);
    }

    public function test_telegram_vip_ibiza_deep_link_with_active_collector_and_no_active_run_sends_blocking_reply(): void
    {
        Queue::fake();
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 504,
                ],
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $contact = Contact::factory()->create([
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_ACTIVE,
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_FIRST_NAME,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '200',
            'external_username' => 'telegram_user',
        ]);
        Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => '300',
        ]);

        $scenario = $this->createPublishedScenario('vip_ibiza', [
            'version' => 1,
            'start_block_id' => 'welcome',
            'triggers' => [
                [
                    'type' => 'parameter',
                    'value' => 'vip_ibiza_apply',
                ],
                [
                    'type' => 'parameter',
                    'value' => 'vip_ibiza_tg1',
                ],
                [
                    'type' => 'parameter',
                    'value' => 'vip_ibiza_inst1',
                ],
            ],
            'blocks' => [
                'welcome' => [
                    'type' => 'message',
                    'text' => 'Добро пожаловать',
                    'next' => 'end',
                ],
                'end' => [
                    'type' => 'complete',
                ],
            ],
        ]);

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload(
            userId: 200,
            chatId: 300,
            messageId: 908,
            text: '/start vip_ibiza_tg1',
        ));

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $storedMessage = $this->inboundMessages()->firstOrFail();

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === '300'
            && $request['text'] === 'У тебя уже идёт сбор данных. Сначала заверши его.');

        Queue::assertNotPushed(ProcessDataCollectionResponseJob::class);
        Queue::assertNotPushed(ProcessScenarioInboundJob::class);
        Queue::assertNotPushed(ProcessScenarioStartJob::class);
        Queue::assertNotPushed(ProcessAutoReplyJob::class);

        $this->assertNotNull($storedMessage->fresh()->auto_reply_sent_at);
        $this->assertDatabaseHas('messages', [
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_AUTO_REPLY,
            'reply_to_message_id' => $storedMessage->id,
            'text' => 'У тебя уже идёт сбор данных. Сначала заверши его.',
        ]);
        $this->assertDatabaseMissing('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.data_collection_response_queued',
        ]);
    }

    public function test_telegram_vip_ibiza_deep_link_without_active_run_starts_scenario_as_before(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '200',
            'external_username' => 'telegram_user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => '300',
        ]);

        $scenario = $this->createPublishedScenario('vip_ibiza', [
            'version' => 1,
            'start_block_id' => 'welcome',
            'triggers' => [
                [
                    'type' => 'parameter',
                    'value' => 'vip_ibiza_apply',
                ],
                [
                    'type' => 'parameter',
                    'value' => 'vip_ibiza_tg1',
                ],
                [
                    'type' => 'parameter',
                    'value' => 'vip_ibiza_inst1',
                ],
            ],
            'blocks' => [
                'welcome' => [
                    'type' => 'message',
                    'text' => 'Добро пожаловать',
                    'next' => 'capture_phone',
                ],
                'capture_phone' => [
                    'type' => 'phone_capture',
                    'text' => 'Поделитесь номером телефона.',
                    'next' => 'end',
                ],
                'end' => [
                    'type' => 'complete',
                ],
            ],
        ]);

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload(
            userId: 200,
            chatId: 300,
            messageId: 907,
            text: '/start vip_ibiza_tg1',
        ));

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $storedMessage = $this->inboundMessages()->firstOrFail();

        Queue::assertPushed(ProcessScenarioStartJob::class, function (ProcessScenarioStartJob $job) use ($storedMessage, $dialog, $scenario): bool {
            return $job->inboundMessageId === $storedMessage->id
                && $job->dialogId === $dialog->id
                && $job->scenarioCode === $scenario->code;
        });
        Queue::assertNotPushed(ProcessScenarioInboundJob::class);
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        $this->assertNull($storedMessage->fresh()->auto_reply_sent_at);
    }

    public function test_telegram_generic_scenario_callback_is_answered_and_ignored_for_database_backed_run(): void
    {
        Queue::fake();
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => true,
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '200',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => '300',
        ]);

        $scenario = $this->createPublishedScenario(code: 'vip_ibiza_apply');
        $run = ScenarioRun::query()->create([
            'dialog_id' => $dialog->id,
            'scenario_code' => $scenario->code,
            'status' => ScenarioRun::STATUS_ACTIVE,
            'current_step' => 'welcome',
            'state_payload' => [],
            'started_at' => now()->subMinute(),
        ]);

        $payload = $this->telegramCallbackPayload(
            callbackId: 'callback-94',
            callbackData: "scenario:{$run->id}:start_selection",
            messageId: 91,
        );

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $payload);

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        Queue::assertNotPushed(ProcessScenarioInboundJob::class);
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        $this->assertDatabaseCount('messages', 0);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/answerCallbackQuery'
            && $request['callback_query_id'] === 'callback-94');
    }

    public function test_stale_telegram_generic_scenario_callback_is_answered_and_ignored(): void
    {
        Queue::fake();
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => true,
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $payload = $this->telegramCallbackPayload(
            callbackId: 'callback-941',
            callbackData: 'scenario:999:start_selection',
            messageId: 93,
        );

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $payload);

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        Queue::assertNotPushed(ProcessScenarioInboundJob::class);
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        $this->assertDatabaseCount('messages', 0);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/answerCallbackQuery'
            && $request['callback_query_id'] === 'callback-941');
    }

    public function test_dispatch_ignores_stored_generic_scenario_callback_for_database_backed_run(): void
    {
        Queue::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '200',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => '300',
        ]);

        $scenario = $this->createPublishedScenario(code: 'vip_ibiza_apply');
        $run = ScenarioRun::query()->create([
            'dialog_id' => $dialog->id,
            'scenario_code' => $scenario->code,
            'status' => ScenarioRun::STATUS_ACTIVE,
            'current_step' => 'welcome',
            'state_payload' => [],
            'started_at' => now()->subMinute(),
        ]);

        $storedMessage = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'external_chat_id' => '300',
            'external_message_id' => 'callback-942',
            'text' => 'scenario:start_selection',
            'message_kind' => Message::KIND_INBOUND_USER,
            'raw_payload' => [
                'callback_query' => [
                    'id' => 'callback-942',
                    'data' => "scenario:{$run->id}:start_selection",
                ],
            ],
        ]);

        $handled = app(DispatchStoredInboundScenarioAction::class)->continueActiveRun($storedMessage);

        $this->assertFalse($handled);
        Queue::assertNotPushed(ProcessScenarioInboundJob::class);
    }

    public function test_telegram_generic_scenario_callback_queues_inbound_job_for_builtin_run(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '200',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => '300',
        ]);

        $run = ScenarioRun::query()->create([
            'dialog_id' => $dialog->id,
            'scenario_code' => 'warmup',
            'status' => ScenarioRun::STATUS_ACTIVE,
            'current_step' => 'awaiting_topic',
            'state_payload' => [],
            'started_at' => now()->subMinute(),
        ]);

        $payload = $this->telegramCallbackPayload(
            callbackId: 'callback-95',
            callbackData: "scenario:warmup:{$run->id}:positive",
            messageId: 92,
        );

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $payload);

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $storedMessage = $this->inboundMessages()->firstOrFail();

        Queue::assertPushed(ProcessScenarioInboundJob::class, function (ProcessScenarioInboundJob $job) use ($storedMessage, $run): bool {
            return $job->inboundMessageId === $storedMessage->id
                && $job->scenarioRunId === $run->id;
        });
        Queue::assertNotPushed(ProcessAutoReplyJob::class);

        $this->assertSame('warmup:positive', $storedMessage->text);
    }

    public function test_telegram_v3_inline_button_callback_queues_inbound_job_for_active_run(): void
    {
        Queue::fake();
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => true,
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);
        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '200',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => '300',
        ]);
        $scenario = $this->createPublishedScenario('v3_inline_callback', [
            'version' => 3,
            'builder_v3_runtime' => [
                'schema_version' => 3,
                'source_revision' => 'v3:test',
                'entrypoints' => [[
                    'block_id' => 'start',
                    'channel_ids' => [$channel->id],
                    'match' => 'any_inbound',
                    'values' => ['*'],
                    'priority' => 10,
                ]],
                'blocks' => [
                    'start' => [
                        'id' => 'start',
                        'db_id' => 1,
                        'kind' => 'state',
                        'title' => 'Старт',
                        'message' => [
                            'text' => 'Выберите действие',
                            'text_format' => Message::TEXT_FORMAT_PLAIN_TEXT,
                        ],
                        'buttons' => [
                            'placement' => 'inline_message',
                            'rows' => [[[
                                'id' => 'btn_catalog',
                                'text' => 'Получить каталог',
                                'type' => 'text',
                                'normalized_text' => 'получить каталог',
                                'output_id' => 'btn_catalog',
                                'target_block_id' => 'catalog',
                            ]]],
                        ],
                        'default_target_block_id' => null,
                    ],
                    'catalog' => [
                        'id' => 'catalog',
                        'db_id' => 2,
                        'kind' => 'state',
                        'title' => 'Каталог',
                        'message' => [
                            'text' => 'Вот каталог',
                            'text_format' => Message::TEXT_FORMAT_PLAIN_TEXT,
                        ],
                        'buttons' => null,
                        'default_target_block_id' => null,
                    ],
                ],
            ],
        ]);
        $run = ScenarioRun::query()->create([
            'dialog_id' => $dialog->id,
            'scenario_code' => $scenario->code,
            'status' => ScenarioRun::STATUS_ACTIVE,
            'current_step' => 'start',
            'state_payload' => [
                'v3' => [
                    'schema_version' => 3,
                    'current_block_id' => 'start',
                    'status' => 'waiting_input',
                    'waiting_output_ids' => ['btn_catalog'],
                ],
            ],
            'started_at' => now()->subMinute(),
        ]);

        $payload = $this->telegramCallbackPayload(
            callbackId: 'callback-v3-1',
            callbackData: 'v3b:start:btn_catalog',
            messageId: 96,
        );

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $payload);

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $storedMessage = $this->inboundMessages()->firstOrFail();

        $this->assertSame('v3b:start:btn_catalog', $storedMessage->text);
        Queue::assertPushed(ProcessScenarioInboundJob::class, function (ProcessScenarioInboundJob $job) use ($storedMessage, $run): bool {
            return $job->inboundMessageId === $storedMessage->id
                && $job->scenarioRunId === $run->id;
        });
        Queue::assertNotPushed(ProcessScenarioStartJob::class);
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/answerCallbackQuery'
            && $request['callback_query_id'] === 'callback-v3-1');
    }

    public function test_max_contact_share_webhook_saves_phone_and_queues_confirmation_follow_up(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);

        $payload = $this->maxPayload(messageId: 'max-contact-90', text: null);
        $payload['message']['body'] = [
            'mid' => 'max-contact-90',
            'contact' => [
                'phone' => '+7 999 123 45 67',
                'user_id' => 500,
            ],
        ];

        $response = $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $payload);

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $storedMessage = $this->inboundMessages()->firstOrFail();

        Queue::assertPushed(ProcessPhoneCaptureFollowUpJob::class, function (ProcessPhoneCaptureFollowUpJob $job) use ($storedMessage): bool {
            return $job->inboundMessageId === $storedMessage->id
                && $job->phoneCaptureStatus === StoredInboundMessageResult::PHONE_CAPTURE_STATUS_CAPTURED_NEW;
        });
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        Http::assertNothingSent();

        $this->assertSame(Message::KIND_INBOUND_CONTACT_SHARE, $storedMessage->message_kind);
        $this->assertDatabaseHas('contact_phone_numbers', [
            'contact_id' => $storedMessage->contact_id,
            'phone_raw' => '+7 999 123 45 67',
            'phone_normalized' => '+79991234567',
            'source' => ContactPhoneNumber::SOURCE_MAX_CONTACT_SHARE,
        ]);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.phone_captured',
        ]);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.phone_capture_confirmation_queued',
        ]);
    }

    public function test_max_contact_share_webhook_with_vcf_attachment_saves_phone_and_queues_confirmation_follow_up(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);

        $payload = $this->maxPayload(messageId: 'max-contact-vcf-90', text: null);
        $payload['message']['sender']['user_id'] = 228532008;
        $payload['message']['body'] = [
            'mid' => 'max-contact-vcf-90',
            'text' => null,
            'attachments' => [[
                'type' => 'contact',
                'payload' => [
                    'max_info' => [
                        'user_id' => 228532008,
                    ],
                    'vcf_info' => "BEGIN:VCARD\r\nVERSION:3.0\r\nTEL;TYPE=cell:79263527111\r\nFN:Герман Абрикосов\r\nEND:VCARD",
                ],
            ]],
        ];

        $response = $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $payload);

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $storedMessage = $this->inboundMessages()->firstOrFail();

        Queue::assertPushed(ProcessPhoneCaptureFollowUpJob::class, function (ProcessPhoneCaptureFollowUpJob $job) use ($storedMessage): bool {
            return $job->inboundMessageId === $storedMessage->id
                && $job->phoneCaptureStatus === StoredInboundMessageResult::PHONE_CAPTURE_STATUS_CAPTURED_NEW;
        });
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        Http::assertNothingSent();

        $this->assertSame(Message::KIND_INBOUND_CONTACT_SHARE, $storedMessage->message_kind);
        $this->assertDatabaseHas('contact_phone_numbers', [
            'contact_id' => $storedMessage->contact_id,
            'phone_raw' => '79263527111',
            'phone_normalized' => '+79263527111',
            'source' => ContactPhoneNumber::SOURCE_MAX_CONTACT_SHARE,
        ]);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.phone_captured',
        ]);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.phone_capture_confirmation_queued',
        ]);
    }

    public function test_max_webhook_logs_unhandled_payload_when_normalizer_returns_null_due_to_missing_chat_id(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);

        $payload = [
            'update_type' => 'message_created',
            'timestamp' => 1_775_578_788_491,
            'user_locale' => 'ru',
            'message' => [
                'sender' => [
                    'user_id' => 228532008,
                    'is_bot' => false,
                ],
                'recipient' => [
                    'user_id' => 241737700,
                    'chat_type' => 'dialog',
                ],
                'body' => [
                    'mid' => 'max-unhandled-contact-1',
                    'contact' => [
                        'name' => 'Герман Абрикосов',
                    ],
                ],
            ],
        ];

        $response = $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $payload);

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        Queue::assertNotPushed(ProcessPhoneCaptureFollowUpJob::class);
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        Queue::assertNotPushed(ProcessScenarioInboundJob::class);
        Http::assertNothingSent();
        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'webhook.received',
        ]);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'webhook.max_unhandled_payload',
        ]);

        $log = ChannelActivityLog::query()
            ->where('channel_id', $channel->id)
            ->where('event', 'webhook.max_unhandled_payload')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('missing_chat_id', data_get($log->context, 'reason'));
        $this->assertSame('message_created', data_get($log->context, 'update_type'));
        $this->assertSame('max-unhandled-contact-1', data_get($log->context, 'message_mid'));
        $this->assertTrue((bool) data_get($log->context, 'has_sender_user_id'));
        $this->assertTrue((bool) data_get($log->context, 'has_recipient_user_id'));
        $this->assertFalse((bool) data_get($log->context, 'has_recipient_chat_id'));
        $this->assertTrue((bool) data_get($log->context, 'has_body_contact'));
        $this->assertFalse((bool) data_get($log->context, 'has_vcf_info'));
        $this->assertIsString(data_get($log->context, 'payload_excerpt'));
    }

    public function test_max_webhook_logs_reason_when_update_type_is_not_supported(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);

        $payload = [
            'update_type' => 'message_callback',
            'timestamp' => 1_775_578_788_491,
        ];

        $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $payload)->assertOk();

        $log = ChannelActivityLog::query()
            ->where('channel_id', $channel->id)
            ->where('event', 'webhook.max_unhandled_payload')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('unsupported_update_type', data_get($log->context, 'reason'));
        $this->assertSame('message_callback', data_get($log->context, 'update_type'));
        $this->assertNull(data_get($log->context, 'message_mid'));
        $this->assertFalse((bool) data_get($log->context, 'has_sender'));
        $this->assertFalse((bool) data_get($log->context, 'has_attachments'));
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_max_webhook_logs_reason_when_message_payload_is_missing(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);

        $payload = [
            'update_type' => 'message_created',
            'timestamp' => 1_775_578_788_491,
            'user_locale' => 'ru',
        ];

        $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $payload)->assertOk();

        $log = ChannelActivityLog::query()
            ->where('channel_id', $channel->id)
            ->where('event', 'webhook.max_unhandled_payload')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('missing_message_payload', data_get($log->context, 'reason'));
        $this->assertFalse((bool) data_get($log->context, 'has_sender'));
        $this->assertFalse((bool) data_get($log->context, 'has_recipient'));
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_max_webhook_logs_reason_when_sender_is_bot(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);

        $payload = [
            'update_type' => 'message_created',
            'timestamp' => 1_775_578_788_491,
            'user_locale' => 'ru',
            'message' => [
                'sender' => [
                    'user_id' => 228532008,
                    'is_bot' => true,
                ],
                'recipient' => [
                    'chat_id' => 66552012,
                    'user_id' => 241737700,
                    'chat_type' => 'dialog',
                ],
                'body' => [
                    'mid' => 'max-unhandled-bot-sender-1',
                    'text' => 'hello',
                ],
            ],
        ];

        $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $payload)->assertOk();

        $log = ChannelActivityLog::query()
            ->where('channel_id', $channel->id)
            ->where('event', 'webhook.max_unhandled_payload')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('sender_is_bot', data_get($log->context, 'reason'));
        $this->assertSame('max-unhandled-bot-sender-1', data_get($log->context, 'message_mid'));
        $this->assertTrue((bool) data_get($log->context, 'has_sender_user_id'));
        $this->assertTrue((bool) data_get($log->context, 'has_recipient_chat_id'));
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_max_webhook_logs_reason_when_payload_is_not_dialog(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);

        $payload = [
            'update_type' => 'message_created',
            'timestamp' => 1_775_578_788_491,
            'message' => [
                'sender' => [
                    'user_id' => 228532008,
                    'is_bot' => false,
                ],
                'recipient' => [
                    'chat_id' => 66552012,
                    'chat_type' => 'dialog',
                ],
                'body' => [
                    'mid' => 'max-unhandled-not-dialog-1',
                    'text' => 'hello',
                ],
            ],
        ];

        $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $payload)->assertOk();

        $log = ChannelActivityLog::query()
            ->where('channel_id', $channel->id)
            ->where('event', 'webhook.max_unhandled_payload')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('not_dialog', data_get($log->context, 'reason'));
        $this->assertSame('max-unhandled-not-dialog-1', data_get($log->context, 'message_mid'));
        $this->assertTrue((bool) data_get($log->context, 'has_sender_user_id'));
        $this->assertFalse((bool) data_get($log->context, 'has_recipient_user_id'));
        $this->assertTrue((bool) data_get($log->context, 'has_recipient_chat_id'));
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_max_webhook_logs_reason_when_sender_user_id_is_missing(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);

        $payload = [
            'update_type' => 'message_created',
            'timestamp' => 1_775_578_788_491,
            'user_locale' => 'ru',
            'message' => [
                'sender' => [
                    'is_bot' => false,
                ],
                'recipient' => [
                    'chat_id' => 66552012,
                    'user_id' => 241737700,
                    'chat_type' => 'dialog',
                ],
                'body' => [
                    'mid' => 'max-unhandled-missing-user-1',
                    'text' => 'hello',
                ],
            ],
        ];

        $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $payload)->assertOk();

        $log = ChannelActivityLog::query()
            ->where('channel_id', $channel->id)
            ->where('event', 'webhook.max_unhandled_payload')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('missing_user_id', data_get($log->context, 'reason'));
        $this->assertSame('max-unhandled-missing-user-1', data_get($log->context, 'message_mid'));
        $this->assertFalse((bool) data_get($log->context, 'has_sender_user_id'));
        $this->assertTrue((bool) data_get($log->context, 'has_recipient_user_id'));
        $this->assertTrue((bool) data_get($log->context, 'has_recipient_chat_id'));
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_late_max_contact_share_logs_delayed_received_and_phone_capture_arrived_late(): void
    {
        Queue::fake();
        Http::fake();
        config()->set('bots.max.delayed_webhook_threshold_seconds', 60);

        Carbon::setTestNow(Carbon::parse('2026-03-31 19:06:46+03:00'));

        try {
            $channel = Channel::factory()->create([
                'platform' => Channel::PLATFORM_MAX,
                'credentials' => [
                    'token' => 'max-token',
                    'webhook_secret' => 'max-secret',
                ],
            ]);

            $payload = $this->maxPayload(
                messageId: 'max-contact-late-90',
                text: null,
                timestamp: '2026-03-31T18:40:58+03:00',
            );
            $payload['message']['body'] = [
                'mid' => 'max-contact-late-90',
                'contact' => [
                    'phone' => '+7 999 123 45 67',
                    'user_id' => 500,
                ],
            ];

            $response = $this->withHeaders([
                'X-Max-Bot-Api-Secret' => 'max-secret',
            ])->postJson("/webhooks/max/{$channel->id}", $payload);

            $response->assertOk()->assertExactJson([
                'ok' => true,
            ]);

            $storedMessage = $this->inboundMessages()
                ->where('external_message_id', 'max-contact-late-90')
                ->firstOrFail();

            Queue::assertPushed(ProcessPhoneCaptureFollowUpJob::class, function (ProcessPhoneCaptureFollowUpJob $job) use ($storedMessage): bool {
                return $job->inboundMessageId === $storedMessage->id
                    && $job->phoneCaptureStatus === StoredInboundMessageResult::PHONE_CAPTURE_STATUS_CAPTURED_NEW;
            });

            $delayedLog = ChannelActivityLog::query()
                ->where('channel_id', $channel->id)
                ->where('event', 'webhook.delayed_received')
                ->latest('id')
                ->firstOrFail();

            $latePhoneCaptureLog = ChannelActivityLog::query()
                ->where('channel_id', $channel->id)
                ->where('event', 'contact.phone_capture_arrived_late')
                ->latest('id')
                ->firstOrFail();

            $this->assertGreaterThan(60, (int) data_get($delayedLog->context, 'delivery_lag_seconds'));
            $this->assertSame('max-contact-late-90', data_get($delayedLog->context, 'external_message_id'));
            $this->assertSame(
                StoredInboundMessageResult::PHONE_CAPTURE_STATUS_CAPTURED_NEW,
                data_get($latePhoneCaptureLog->context, 'phone_capture_status'),
            );
            $this->assertGreaterThan(60, (int) data_get($latePhoneCaptureLog->context, 'delivery_lag_seconds'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_late_max_contact_share_logs_out_of_order_when_newer_inbound_exists(): void
    {
        Queue::fake();
        Http::fake();
        config()->set('bots.max.delayed_webhook_threshold_seconds', 60);

        Carbon::setTestNow(Carbon::parse('2026-03-31 19:06:46+03:00'));

        try {
            $channel = Channel::factory()->create([
                'platform' => Channel::PLATFORM_MAX,
                'credentials' => [
                    'token' => 'max-token',
                    'webhook_secret' => 'max-secret',
                ],
            ]);

            $newerPayload = $this->maxPayload(
                messageId: 'max-user-newer-91',
                text: 'что?',
                timestamp: '2026-03-31T19:05:30+03:00',
            );

            $this->withHeaders([
                'X-Max-Bot-Api-Secret' => 'max-secret',
            ])->postJson("/webhooks/max/{$channel->id}", $newerPayload)->assertOk();

            $newerInbound = $this->inboundMessages()
                ->where('external_message_id', 'max-user-newer-91')
                ->firstOrFail();

            $latePayload = $this->maxPayload(
                messageId: 'max-contact-late-order-92',
                text: null,
                timestamp: '2026-03-31T18:40:58+03:00',
            );
            $latePayload['message']['body'] = [
                'mid' => 'max-contact-late-order-92',
                'contact' => [
                    'phone' => '+7 999 123 45 67',
                    'user_id' => 500,
                ],
            ];

            $this->withHeaders([
                'X-Max-Bot-Api-Secret' => 'max-secret',
            ])->postJson("/webhooks/max/{$channel->id}", $latePayload)->assertOk();

            $lateInbound = $this->inboundMessages()
                ->where('external_message_id', 'max-contact-late-order-92')
                ->firstOrFail();

            $outOfOrderLog = ChannelActivityLog::query()
                ->where('channel_id', $channel->id)
                ->where('event', 'webhook.out_of_order_received')
                ->latest('id')
                ->firstOrFail();

            $this->assertSame($lateInbound->id, (int) data_get($outOfOrderLog->context, 'message_id'));
            $this->assertSame($newerInbound->id, (int) data_get($outOfOrderLog->context, 'newer_inbound_message_id'));
            $this->assertGreaterThan(0, (int) data_get($outOfOrderLog->context, 'seconds_behind_latest_inbound'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_late_max_contact_share_still_merges_into_existing_root_and_queues_follow_up(): void
    {
        Queue::fake();
        Http::fake();
        config()->set('bots.max.delayed_webhook_threshold_seconds', 60);

        Carbon::setTestNow(Carbon::parse('2026-03-31 19:06:46+03:00'));

        try {
            $channel = Channel::factory()->create([
                'platform' => Channel::PLATFORM_MAX,
                'credentials' => [
                    'token' => 'max-token',
                    'webhook_secret' => 'max-secret',
                ],
            ]);

            $existingRoot = Contact::factory()->create([
                'first_name' => 'Герман',
                'country' => 'Россия',
                'city' => 'Москва',
                'age_range' => '30_39',
            ]);
            ContactPhoneNumber::factory()->create([
                'contact_id' => $existingRoot->id,
                'phone_raw' => '+7 999 123 45 67',
                'phone_normalized' => '+79991234567',
                'is_primary' => true,
            ]);

            $payload = $this->maxPayload(
                userId: 228532008,
                messageId: 'max-contact-late-merge-93',
                text: null,
                username: 'max_user_merge',
                timestamp: '2026-03-31T18:40:58+03:00',
            );
            $payload['message']['body'] = [
                'mid' => 'max-contact-late-merge-93',
                'attachments' => [[
                    'type' => 'contact',
                    'payload' => [
                        'max_info' => [
                            'user_id' => 228532008,
                        ],
                        'vcf_info' => "BEGIN:VCARD\r\nVERSION:3.0\r\nTEL;TYPE=cell:79991234567\r\nFN:Герман Абрикосов\r\nEND:VCARD",
                    ],
                ]],
            ];

            $response = $this->withHeaders([
                'X-Max-Bot-Api-Secret' => 'max-secret',
            ])->postJson("/webhooks/max/{$channel->id}", $payload);

            $response->assertOk()->assertExactJson([
                'ok' => true,
            ]);

            $storedMessage = $this->inboundMessages()
                ->where('external_message_id', 'max-contact-late-merge-93')
                ->firstOrFail();

            Queue::assertPushed(ProcessPhoneCaptureFollowUpJob::class, function (ProcessPhoneCaptureFollowUpJob $job) use ($storedMessage): bool {
                return $job->inboundMessageId === $storedMessage->id
                    && $job->phoneCaptureStatus === StoredInboundMessageResult::PHONE_CAPTURE_STATUS_MERGED_TO_ROOT;
            });

            $this->assertSame($existingRoot->id, $storedMessage->contact_id);
            $this->assertDatabaseHas('channel_activity_logs', [
                'channel_id' => $channel->id,
                'event' => 'webhook.delayed_received',
            ]);
            $this->assertDatabaseHas('channel_activity_logs', [
                'channel_id' => $channel->id,
                'event' => 'contact.phone_capture_arrived_late',
            ]);
            $this->assertDatabaseHas('channel_activity_logs', [
                'channel_id' => $channel->id,
                'event' => 'contact.phone_merged_to_existing_root',
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_max_contact_share_with_unknown_format_logs_skip_event_and_does_not_queue_follow_up(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);

        $payload = $this->maxPayload(messageId: 'max-contact-91', text: null);
        $payload['message']['body'] = [
            'mid' => 'max-contact-91',
            'contact' => [],
        ];

        $response = $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $payload);

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        Queue::assertNotPushed(ProcessPhoneCaptureFollowUpJob::class);
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        Queue::assertNotPushed(ProcessScenarioInboundJob::class);
        Http::assertNothingSent();

        $storedMessage = $this->inboundMessages()->firstOrFail();

        $this->assertSame(Message::KIND_INBOUND_CONTACT_SHARE, $storedMessage->message_kind);
        $this->assertDatabaseCount('contact_phone_numbers', 0);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'max.contact_share_unknown_format',
        ]);
    }

    public function test_max_contact_share_with_active_database_run_on_phone_capture_and_unknown_format_does_not_queue_scenario_inbound_job(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);

        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '500',
            'external_username' => 'max_user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => '700',
        ]);

        $scenario = $this->createPublishedScenario('vip_ibiza_apply', [
            'version' => 1,
            'start_block_id' => 'capture_phone',
            'triggers' => [
                [
                    'type' => 'parameter',
                    'value' => 'vip_ibiza_apply',
                ],
            ],
            'blocks' => [
                'capture_phone' => [
                    'type' => 'phone_capture',
                    'text' => 'Поделитесь номером телефона.',
                    'next' => 'end',
                ],
                'end' => [
                    'type' => 'complete',
                ],
            ],
        ]);

        ScenarioRun::query()->create([
            'dialog_id' => $dialog->id,
            'scenario_code' => $scenario->code,
            'status' => ScenarioRun::STATUS_ACTIVE,
            'current_step' => 'capture_phone',
            'state_payload' => [],
            'started_at' => now()->subMinute(),
        ]);

        $payload = $this->maxPayload(messageId: 'max-contact-92', text: null);
        $payload['message']['body'] = [
            'mid' => 'max-contact-92',
            'contact' => [],
        ];

        $response = $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $payload);

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        Queue::assertNotPushed(ProcessScenarioInboundJob::class);
        Queue::assertNotPushed(ProcessPhoneCaptureFollowUpJob::class);
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        $this->assertDatabaseCount('contact_phone_numbers', 0);
    }

    public function test_telegram_contact_share_webhook_merges_into_existing_root_and_queues_merged_follow_up(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $existingRoot = Contact::factory()->create([
            'first_name' => 'Герман',
            'country' => 'Россия',
            'city' => 'Москва',
            'age_range' => '30_39',
        ]);
        ContactPhoneNumber::factory()->create([
            'contact_id' => $existingRoot->id,
            'phone_raw' => '+7 999 123 45 67',
            'phone_normalized' => '+79991234567',
            'is_primary' => true,
        ]);

        $payload = $this->telegramPayload(messageId: 190, text: null);
        $payload['message']['contact'] = [
            'phone_number' => '+7 999 123 45 67',
            'user_id' => 200,
        ];

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $payload);

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $storedMessage = $this->inboundMessages()->firstOrFail();

        Queue::assertPushed(ProcessPhoneCaptureFollowUpJob::class, function (ProcessPhoneCaptureFollowUpJob $job) use ($storedMessage): bool {
            return $job->inboundMessageId === $storedMessage->id
                && $job->phoneCaptureStatus === StoredInboundMessageResult::PHONE_CAPTURE_STATUS_MERGED_TO_ROOT;
        });

        $this->assertSame($existingRoot->id, $storedMessage->contact_id);
        $this->assertDatabaseCount('contact_merge_logs', 1);
        $this->assertDatabaseCount('contact_duplicate_reviews', 0);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.phone_merged_to_existing_root',
        ]);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.phone_capture_confirmation_queued',
        ]);
    }

    public function test_telegram_contact_share_webhook_marks_review_pending_when_phone_matches_multiple_roots(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        foreach ([1, 2] as $index) {
            $contact = Contact::factory()->create([
                'first_name' => 'Контакт '.$index,
            ]);
            ContactPhoneNumber::factory()->create([
                'contact_id' => $contact->id,
                'phone_raw' => '+7 999 123 45 67',
                'phone_normalized' => '+79991234567',
                'is_primary' => true,
            ]);
        }

        $payload = $this->telegramPayload(messageId: 191, text: null);
        $payload['message']['contact'] = [
            'phone_number' => '+7 999 123 45 67',
            'user_id' => 200,
        ];

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $payload);

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $storedMessage = $this->inboundMessages()->firstOrFail();

        Queue::assertPushed(ProcessPhoneCaptureFollowUpJob::class, function (ProcessPhoneCaptureFollowUpJob $job) use ($storedMessage): bool {
            return $job->inboundMessageId === $storedMessage->id
                && $job->phoneCaptureStatus === StoredInboundMessageResult::PHONE_CAPTURE_STATUS_REVIEW_PENDING;
        });

        $this->assertDatabaseCount('contact_merge_logs', 0);
        $this->assertDatabaseHas('contact_duplicate_reviews', [
            'contact_id' => $storedMessage->contact_id,
            'phone_normalized' => '+79991234567',
            'review_type' => ContactDuplicateReview::TYPE_PHONE_OTHER_ROOT_CANDIDATE,
            'status' => ContactDuplicateReview::STATUS_OPEN,
        ]);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.phone_review_pending_multiple_roots',
        ]);
    }

    public function test_telegram_contact_share_with_sender_mismatch_does_not_queue_follow_up(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $payload = $this->telegramPayload(messageId: 91, text: null);
        $payload['message']['contact'] = [
            'phone_number' => '+7 999 123 45 67',
            'user_id' => 999,
        ];

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $payload);

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        Queue::assertNotPushed(ProcessPhoneCaptureFollowUpJob::class);
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        Queue::assertNotPushed(ProcessScenarioInboundJob::class);
        Http::assertNothingSent();
        $this->assertDatabaseCount('contact_phone_numbers', 0);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.phone_capture_skipped_sender_mismatch',
        ]);
    }

    public function test_repeated_telegram_webhook_with_same_update_id_does_not_queue_second_job_after_successful_auto_reply(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $headers = [
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ];

        $payload = $this->telegramPayload(
            messageId: 42,
            text: 'duplicate telegram message',
        );

        $this->withHeaders($headers)
            ->postJson("/webhooks/telegram/{$channel->id}", $payload)
            ->assertOk();

        $message = $this->inboundMessages()->firstOrFail();
        $message->forceFill([
            'auto_reply_sent_at' => now(),
        ])->save();

        $this->withHeaders($headers)
            ->postJson("/webhooks/telegram/{$channel->id}", $payload)
            ->assertOk();

        Queue::assertPushed(ProcessAutoReplyJob::class, 1);
        $this->assertDatabaseCount('messages', 1);
        $this->assertMessageDirectionCount(Message::DIRECTION_INBOUND, 1);
        $this->assertMessageDirectionCount(Message::DIRECTION_OUTBOUND, 0);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'webhook.duplicate_ignored',
        ]);
    }

    public function test_repeated_telegram_webhook_with_same_update_id_requeues_after_previous_failure(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $headers = [
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ];

        $payload = $this->telegramPayload(
            messageId: 43,
            text: 'telegram retry message',
        );

        $this->withHeaders($headers)
            ->postJson("/webhooks/telegram/{$channel->id}", $payload)
            ->assertOk();

        $message = $this->inboundMessages()->firstOrFail();

        $this->assertSame('43', $message->provider_event_key);
        $this->assertNull($message->auto_reply_sent_at);

        $this->withHeaders($headers)
            ->postJson("/webhooks/telegram/{$channel->id}", $payload)
            ->assertOk();

        Queue::assertPushed(ProcessAutoReplyJob::class, 2);
        $this->assertDatabaseCount('messages', 1);
        $this->assertMessageDirectionCount(Message::DIRECTION_INBOUND, 1);
        $this->assertMessageDirectionCount(Message::DIRECTION_OUTBOUND, 0);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'webhook.duplicate_retry_reply',
        ]);
    }

    public function test_repeated_max_webhook_with_same_external_message_id_requeues_after_previous_failure(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);

        $headers = [
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ];

        $payload = $this->maxPayload(
            messageId: 'max-43',
            text: 'max retry message',
        );

        $this->withHeaders($headers)
            ->postJson("/webhooks/max/{$channel->id}", $payload)
            ->assertOk();

        $this->withHeaders($headers)
            ->postJson("/webhooks/max/{$channel->id}", $payload)
            ->assertOk();

        Queue::assertPushed(ProcessAutoReplyJob::class, 2);
        $this->assertDatabaseCount('messages', 1);
        $this->assertSame('max-43', $this->inboundMessages()->firstOrFail()->provider_event_key);
    }

    public function test_repeat_max_webhook_from_same_user_with_different_message_ids_creates_two_inbound_messages(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);

        $headers = [
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ];

        $this->withHeaders($headers)
            ->postJson("/webhooks/max/{$channel->id}", $this->maxPayload(
                messageId: 'max-100',
                text: 'first max message',
            ))
            ->assertOk();

        $this->withHeaders($headers)
            ->postJson("/webhooks/max/{$channel->id}", $this->maxPayload(
                messageId: 'max-101',
                text: 'second max message',
            ))
            ->assertOk();

        Queue::assertPushed(ProcessAutoReplyJob::class, 2);
        $this->assertDatabaseCount('contacts', 1);
        $this->assertDatabaseCount('contact_identities', 1);
        $this->assertDatabaseCount('messages', 2);
        $this->assertMessageDirectionCount(Message::DIRECTION_INBOUND, 2);
        $this->assertMessageDirectionCount(Message::DIRECTION_OUTBOUND, 0);
    }

    public function test_inactive_channel_does_not_process_event(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'is_active' => false,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload())->assertNotFound();

        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->assertDatabaseCount('contacts', 0);
        $this->assertDatabaseCount('contact_identities', 0);
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_invalid_telegram_webhook_secret_is_rejected(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'expected-secret',
            ],
        ]);

        $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'wrong-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload())->assertForbidden();

        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->assertDatabaseCount('contacts', 0);
        $this->assertDatabaseCount('contact_identities', 0);
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_empty_max_webhook_secret_is_rejected(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'expected-secret',
            ],
        ]);

        $this->postJson("/webhooks/max/{$channel->id}", [
            'update_type' => 'message_created',
            'message' => [
                'sender' => [
                    'user_id' => 1,
                    'is_bot' => false,
                ],
                'recipient' => [
                    'user_id' => 2,
                ],
            ],
        ])->assertForbidden();

        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->assertDatabaseCount('contacts', 0);
        $this->assertDatabaseCount('contact_identities', 0);
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_repeat_telegram_webhook_from_same_user_reuses_contact_identity_and_contact(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $headers = [
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ];

        $this->withHeaders($headers)
            ->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload(
                messageId: 10,
                text: 'first message',
            ))
            ->assertOk();

        $this->withHeaders($headers)
            ->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload(
                messageId: 11,
                text: 'second message',
            ))
            ->assertOk();

        Queue::assertPushed(ProcessAutoReplyJob::class, 2);
        $this->assertDatabaseCount('contacts', 1);
        $this->assertDatabaseCount('contact_identities', 1);
        $this->assertDatabaseCount('messages', 2);
        $this->assertMessageDirectionCount(Message::DIRECTION_INBOUND, 2);
        $this->assertMessageDirectionCount(Message::DIRECTION_OUTBOUND, 0);
    }

    public function test_telegram_webhook_without_update_id_keeps_legacy_non_deduplicated_behavior(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $headers = [
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ];

        $payload = $this->telegramPayload(
            messageId: 77,
            text: 'legacy telegram message',
            includeUpdateId: false,
        );

        $this->withHeaders($headers)
            ->postJson("/webhooks/telegram/{$channel->id}", $payload)
            ->assertOk();

        $this->withHeaders($headers)
            ->postJson("/webhooks/telegram/{$channel->id}", $payload)
            ->assertOk();

        Queue::assertPushed(ProcessAutoReplyJob::class, 2);
        $this->assertDatabaseCount('messages', 2);
        $this->assertMessageDirectionCount(Message::DIRECTION_INBOUND, 2);
        $this->assertMessageDirectionCount(Message::DIRECTION_OUTBOUND, 0);
        $this->assertDatabaseHas('messages', [
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'provider_event_key' => null,
        ]);
    }

    public function test_new_telegram_webhook_from_different_user_creates_new_contact_and_identity(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $headers = [
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ];

        $this->withHeaders($headers)
            ->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload(
                userId: 200,
                chatId: 300,
                messageId: 10,
                text: 'first message',
                username: 'telegram_user',
            ))
            ->assertOk();

        $this->withHeaders($headers)
            ->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload(
                userId: 201,
                chatId: 301,
                messageId: 11,
                text: 'second message',
                username: 'telegram_user_2',
            ))
            ->assertOk();

        Queue::assertPushed(ProcessAutoReplyJob::class, 2);
        $this->assertDatabaseCount('contacts', 2);
        $this->assertDatabaseCount('contact_identities', 2);
        $this->assertDatabaseCount('messages', 2);
        $this->assertMessageDirectionCount(Message::DIRECTION_INBOUND, 2);
        $this->assertMessageDirectionCount(Message::DIRECTION_OUTBOUND, 0);
        $this->assertDatabaseHas('contact_identities', [
            'channel_id' => $channel->id,
            'external_user_id' => '201',
            'external_username' => 'telegram_user_2',
        ]);
    }

    public function test_active_data_collection_routes_inbound_user_to_collector_instead_of_auto_reply(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $contact = Contact::factory()->create([
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_ACTIVE,
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_FIRST_NAME,
            'data_collection_last_prompted_field' => Contact::DATA_COLLECTION_FIELD_FIRST_NAME,
            'data_collection_started_at' => now(),
        ]);

        ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '200',
            'external_username' => 'telegram_user',
        ]);

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload(
            userId: 200,
            chatId: 300,
            messageId: 901,
            text: 'Герман',
        ));

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $storedMessage = $this->inboundMessages()->firstOrFail();

        Queue::assertPushed(ProcessDataCollectionResponseJob::class, function (ProcessDataCollectionResponseJob $job) use ($storedMessage): bool {
            return $job->inboundMessageId === $storedMessage->id;
        });
        Queue::assertNotPushed(ProcessAutoReplyJob::class);

        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.data_collection_response_queued',
        ]);
    }

    public function test_active_data_collection_with_unprompted_current_field_requeues_question_instead_of_processing_response(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $contact = Contact::factory()->create([
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_ACTIVE,
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_RESIDENCE_CITY,
            'data_collection_last_prompted_field' => null,
            'data_collection_started_at' => now(),
            'data_collection_current_field_started_at' => null,
        ]);

        ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '200',
            'external_username' => 'telegram_user',
        ]);

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload(
            userId: 200,
            chatId: 300,
            messageId: 903,
            text: 'Санкт-Петербург',
        ));

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $storedMessage = $this->inboundMessages()->firstOrFail();

        Queue::assertPushed(ProcessDataCollectionQuestionJob::class, function (ProcessDataCollectionQuestionJob $job) use ($storedMessage): bool {
            return $job->sourceMessageId === $storedMessage->id
                && $job->contactId === $storedMessage->contact_id
                && $job->expectedField === Contact::DATA_COLLECTION_FIELD_RESIDENCE_CITY
                && $job->forceSend === false;
        });
        Queue::assertNotPushed(ProcessDataCollectionResponseJob::class);
        Queue::assertNotPushed(ProcessAutoReplyJob::class);

        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.data_collection_pending_question_queued',
        ]);
    }

    public function test_active_data_collection_with_legacy_sent_question_does_not_requeue_prompt_again(): void
    {
        Queue::fake();
        Http::fake();

        config()->set('bots.data_collection.first_question', 'Как вас зовут?');

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $fieldStartedAt = now()->subMinute();
        $contact = Contact::factory()->create([
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_ACTIVE,
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_FIRST_NAME,
            'data_collection_last_prompted_field' => null,
            'data_collection_started_at' => $fieldStartedAt->copy()->subMinute(),
            'data_collection_current_field_started_at' => $fieldStartedAt,
        ]);

        ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '200',
            'external_username' => 'telegram_user',
        ]);

        Message::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION,
            'message_parameter' => null,
            'text' => 'Как вас зовут?',
            'received_at' => $fieldStartedAt,
        ]);

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload(
            userId: 200,
            chatId: 300,
            messageId: 904,
            text: 'Герман',
        ));

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $storedMessage = $this->inboundMessages()->latest('id')->firstOrFail();

        Queue::assertPushed(ProcessDataCollectionResponseJob::class, function (ProcessDataCollectionResponseJob $job) use ($storedMessage): bool {
            return $job->inboundMessageId === $storedMessage->id
                && $job->contactId === $storedMessage->contact_id
                && $job->expectedField === Contact::DATA_COLLECTION_FIELD_FIRST_NAME;
        });
        Queue::assertNotPushed(ProcessDataCollectionQuestionJob::class);
        Queue::assertNotPushed(ProcessAutoReplyJob::class);

        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.data_collection_response_queued',
        ]);
        $this->assertDatabaseMissing('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.data_collection_pending_question_queued',
        ]);
    }

    public function test_repeated_telegram_webhook_with_same_update_id_does_not_requeue_collector_reply(): void
    {
        Queue::fake();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $contact = Contact::factory()->create([
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_ACTIVE,
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_FIRST_NAME,
            'data_collection_last_prompted_field' => Contact::DATA_COLLECTION_FIELD_FIRST_NAME,
            'data_collection_started_at' => now(),
        ]);

        ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '200',
            'external_username' => 'telegram_user',
        ]);

        $headers = [
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ];
        $payload = $this->telegramPayload(
            userId: 200,
            chatId: 300,
            messageId: 902,
            text: 'Герман',
        );

        $this->withHeaders($headers)
            ->postJson("/webhooks/telegram/{$channel->id}", $payload)
            ->assertOk()
            ->assertExactJson([
                'ok' => true,
            ]);

        $this->withHeaders($headers)
            ->postJson("/webhooks/telegram/{$channel->id}", $payload)
            ->assertOk()
            ->assertExactJson([
                'ok' => true,
            ]);

        $storedMessage = $this->inboundMessages()->firstOrFail();

        Queue::assertPushed(ProcessDataCollectionResponseJob::class, function (ProcessDataCollectionResponseJob $job) use ($storedMessage): bool {
            return $job->inboundMessageId === $storedMessage->id;
        });
        Queue::assertPushed(ProcessDataCollectionResponseJob::class, 1);
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'webhook.duplicate_ignored',
        ]);
    }

    public function test_active_age_range_callback_routes_to_collector_and_answers_callback(): void
    {
        Queue::fake();
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => true,
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $contact = Contact::factory()->create([
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_ACTIVE,
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_AGE_RANGE,
            'data_collection_last_prompted_field' => Contact::DATA_COLLECTION_FIELD_AGE_RANGE,
            'data_collection_started_at' => now(),
        ]);

        ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '200',
            'external_username' => 'telegram_user',
        ]);

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramCallbackPayload(
            userId: 200,
            chatId: 300,
            callbackId: 'callback-901',
            callbackData: 'age_range:24_29',
        ));

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $storedMessage = $this->inboundMessages()->firstOrFail();

        $this->assertSame('24_29', $storedMessage->text);
        Queue::assertPushed(ProcessDataCollectionResponseJob::class, function (ProcessDataCollectionResponseJob $job) use ($storedMessage): bool {
            return $job->inboundMessageId === $storedMessage->id;
        });
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/answerCallbackQuery'
            && $request['callback_query_id'] === 'callback-901');
    }

    public function test_stale_age_range_callback_is_answered_and_ignored(): void
    {
        Queue::fake();
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => true,
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $contact = Contact::factory()->create([
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_COMPLETED,
            'data_collection_current_field' => null,
        ]);

        ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '200',
            'external_username' => 'telegram_user',
        ]);

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramCallbackPayload(
            userId: 200,
            chatId: 300,
            callbackId: 'callback-902',
            callbackData: 'age_range:24_29',
        ));

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        Queue::assertNotPushed(ProcessDataCollectionResponseJob::class);
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        $this->assertDatabaseCount('messages', 0);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/answerCallbackQuery'
            && $request['callback_query_id'] === 'callback-902');
    }

    public function test_active_russian_region_confirm_callback_routes_to_collector_and_answers_callback(): void
    {
        Queue::fake();
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => true,
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $contact = Contact::factory()->create([
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_ACTIVE,
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_RUSSIAN_REGION_CONFIRM,
            'data_collection_last_prompted_field' => Contact::DATA_COLLECTION_FIELD_RUSSIAN_REGION_CONFIRM,
            'pending_region_candidates' => ['Волгоградская область', 'Приморский край'],
            'data_collection_started_at' => now(),
        ]);

        ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '200',
            'external_username' => 'telegram_user',
        ]);

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramCallbackPayload(
            userId: 200,
            chatId: 300,
            callbackId: 'callback-903',
            callbackData: 'russian_region_confirm:2',
        ));

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $storedMessage = $this->inboundMessages()->firstOrFail();

        $this->assertSame('russian_region_confirm:2', $storedMessage->text);
        Queue::assertPushed(ProcessDataCollectionResponseJob::class, function (ProcessDataCollectionResponseJob $job) use ($storedMessage): bool {
            return $job->inboundMessageId === $storedMessage->id;
        });
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/answerCallbackQuery'
            && $request['callback_query_id'] === 'callback-903');
    }

    public function test_stale_russian_region_confirm_callback_is_answered_and_ignored(): void
    {
        Queue::fake();
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => true,
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);

        $contact = Contact::factory()->create([
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_ACTIVE,
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_AGE_RANGE,
            'pending_region_candidates' => ['Волгоградская область', 'Приморский край'],
        ]);

        ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '200',
            'external_username' => 'telegram_user',
        ]);

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramCallbackPayload(
            userId: 200,
            chatId: 300,
            callbackId: 'callback-904',
            callbackData: 'russian_region_confirm:2',
        ));

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        Queue::assertNotPushed(ProcessDataCollectionResponseJob::class);
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        $this->assertDatabaseCount('messages', 0);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/answerCallbackQuery'
            && $request['callback_query_id'] === 'callback-904');
    }

    /**
     * @return array<string, mixed>
     */
    protected function telegramPayload(
        int|string $userId = 200,
        int|string $chatId = 300,
        int|string $messageId = 10,
        ?string $text = 'hello',
        ?string $username = 'telegram_user',
        int $date = 1_711_539_200,
        bool $includeUpdateId = true,
    ): array {
        $payload = [
            'message' => [
                'message_id' => $messageId,
                'date' => $date,
                'text' => $text,
                'from' => [
                    'id' => $userId,
                    'username' => $username,
                    'is_bot' => false,
                ],
                'chat' => [
                    'id' => $chatId,
                    'type' => 'private',
                ],
            ],
        ];

        if ($includeUpdateId) {
            $payload['update_id'] = $messageId;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    protected function telegramCallbackPayload(
        int|string $userId = 200,
        int|string $chatId = 300,
        string $callbackId = 'callback-1',
        string $callbackData = 'age_range:24_29',
        int|string $messageId = 10,
        ?string $username = 'telegram_user',
        int $date = 1_711_539_200,
        bool $includeUpdateId = true,
    ): array {
        $payload = [
            'callback_query' => [
                'id' => $callbackId,
                'data' => $callbackData,
                'from' => [
                    'id' => $userId,
                    'username' => $username,
                    'is_bot' => false,
                ],
                'message' => [
                    'message_id' => $messageId,
                    'date' => $date,
                    'chat' => [
                        'id' => $chatId,
                        'type' => 'private',
                    ],
                ],
            ],
        ];

        if ($includeUpdateId) {
            $payload['update_id'] = $messageId;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    protected function telegramMyChatMemberPayload(
        int|string $userId = 200,
        int|string $chatId = 200,
        string $oldStatus = 'member',
        string $newStatus = 'kicked',
        int $date = 1_711_539_200,
        int|string $updateId = 2010,
        ?string $username = 'telegram_user',
    ): array {
        return [
            'update_id' => $updateId,
            'my_chat_member' => [
                'date' => $date,
                'from' => [
                    'id' => $userId,
                    'username' => $username,
                    'is_bot' => false,
                ],
                'chat' => [
                    'id' => $chatId,
                    'type' => 'private',
                ],
                'old_chat_member' => [
                    'status' => $oldStatus,
                ],
                'new_chat_member' => [
                    'status' => $newStatus,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function maxPayload(
        int|string $userId = 500,
        int|string $chatId = 700,
        int|string $messageId = 'max-10',
        ?string $text = 'hello',
        ?string $username = 'max_user',
        string $timestamp = '2026-03-27T12:00:00+03:00',
    ): array {
        return [
            'update_type' => 'message_created',
            'user_locale' => 'ru',
            'timestamp' => $timestamp,
            'message' => [
                'message_id' => $messageId,
                'timestamp' => $timestamp,
                'sender' => [
                    'user_id' => $userId,
                    'username' => $username,
                    'is_bot' => false,
                ],
                'recipient' => [
                    'chat_id' => $chatId,
                ],
                'body' => [
                    'text' => $text,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function maxBotStartedPayload(
        int|string $userId = 500,
        int|string $chatId = 700,
        ?string $payload = 'promo_123',
        string $timestamp = '2026-04-03T10:00:00+03:00',
    ): array {
        $update = [
            'update_type' => 'bot_started',
            'chat_id' => $chatId,
            'timestamp' => $timestamp,
            'user' => [
                'user_id' => $userId,
                'username' => 'max_user',
                'name' => 'Герман',
            ],
        ];

        if ($payload !== null) {
            $update['payload'] = $payload;
        }

        return $update;
    }

    /**
     * @param  array<string, mixed>|null  $schemaPayload
     */
    protected function createPublishedScenario(string $code, ?array $schemaPayload = null): Scenario
    {
        $scenario = Scenario::query()->create([
            'code' => $code,
            'name' => 'VIP Ibiza',
            'is_active' => true,
            'is_archived' => false,
        ]);

        ScenarioVersion::query()->create([
            'scenario_id' => $scenario->id,
            'version_number' => 1,
            'status' => ScenarioVersion::STATUS_PUBLISHED,
            'schema_payload' => $schemaPayload ?? [
                'version' => 1,
                'start_block_id' => 'welcome',
                'triggers' => [
                    [
                        'type' => 'parameter',
                        'value' => $code,
                    ],
                ],
                'blocks' => [
                    'welcome' => [
                        'type' => 'message',
                        'text' => 'Добро пожаловать',
                        'next' => 'end',
                    ],
                    'end' => [
                        'type' => 'complete',
                    ],
                ],
            ],
        ]);

        return $scenario->fresh('publishedVersion');
    }

    protected function assertMessageDirectionCount(string $direction, int $expectedCount): void
    {
        $this->assertSame(
            $expectedCount,
            Message::query()->where('direction', $direction)->count(),
        );
    }

    protected function inboundMessages()
    {
        return Message::query()->where('direction', Message::DIRECTION_INBOUND);
    }

    protected function outboundMessages()
    {
        return Message::query()->where('direction', Message::DIRECTION_OUTBOUND);
    }
}
