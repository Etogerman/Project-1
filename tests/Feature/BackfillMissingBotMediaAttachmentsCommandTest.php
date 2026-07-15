<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Message;
use App\Models\MessageAttachment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BackfillMissingBotMediaAttachmentsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_dry_run_does_not_create_missing_telegram_photo_attachment(): void
    {
        $this->createTelegramPhotoMessage();

        $this->artisan('bot-media:backfill-missing-attachments')
            ->assertExitCode(0);

        $this->assertDatabaseCount('message_attachments', 0);
    }

    public function test_command_backfills_largest_telegram_photo_and_is_idempotent(): void
    {
        $message = $this->createTelegramPhotoMessage();

        $this->artisan('bot-media:backfill-missing-attachments', [
            '--force' => true,
            '--message' => [$message->id],
        ])->assertExitCode(0);

        $this->assertDatabaseHas('message_attachments', [
            'message_id' => $message->id,
            'channel_id' => $message->channel_id,
            'provider' => MessageAttachment::PROVIDER_TELEGRAM_BOT,
            'provider_event_key' => '9001',
            'provider_attachment_key' => 'large-photo-unique',
            'provider_file_id' => 'large-photo-file',
            'provider_file_unique_id' => 'large-photo-unique',
            'media_kind' => MessageAttachment::MEDIA_KIND_IMAGE,
            'file_size_bytes' => 5000,
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD,
        ]);

        $this->artisan('bot-media:backfill-missing-attachments', [
            '--force' => true,
            '--message' => [$message->id],
        ])->assertExitCode(0);

        $this->assertSame(1, MessageAttachment::query()->count());
    }

    public function test_command_backfills_max_image_without_copying_sensitive_token_or_url_to_attachment_fields(): void
    {
        $message = $this->createMaxImageMessage();

        $this->artisan('bot-media:backfill-missing-attachments', [
            '--force' => true,
            '--message' => [$message->id],
        ])->assertExitCode(0);

        $attachment = MessageAttachment::query()->sole();

        $this->assertSame(MessageAttachment::PROVIDER_MAX_BOT, $attachment->provider);
        $this->assertSame('mid.legacy-image-1', $attachment->provider_event_key);
        $this->assertSame('25852958504', $attachment->provider_attachment_key);
        $this->assertSame('25852958504', $attachment->provider_file_reference);
        $this->assertSame(MessageAttachment::MEDIA_KIND_IMAGE, $attachment->media_kind);
        $this->assertSame('25852958504', data_get($attachment->raw_payload_excerpt, 'photo_id'));
        $this->assertStringNotContainsString('secret-token', json_encode($attachment->raw_payload_excerpt));
        $this->assertStringNotContainsString('https://i.oneme.ru/private/image.jpg', json_encode($attachment->raw_payload_excerpt));
    }

    public function test_command_does_not_reset_existing_downloaded_attachment(): void
    {
        $message = $this->createTelegramPhotoMessage();
        $attachment = MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $message->channel_id,
            'provider' => MessageAttachment::PROVIDER_TELEGRAM_BOT,
            'provider_event_key' => '9001',
            'provider_attachment_key' => 'large-photo-unique',
            'provider_file_id' => 'large-photo-file',
            'provider_file_unique_id' => 'large-photo-unique',
            'media_kind' => MessageAttachment::MEDIA_KIND_IMAGE,
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED,
            'local_disk' => MessageAttachment::LOCAL_DISK_PRIVATE,
            'local_path' => MessageAttachment::LOCAL_PATH_PREFIX.'/'.$message->id.'/photo.jpg',
        ]);

        $this->artisan('bot-media:backfill-missing-attachments', [
            '--force' => true,
            '--message' => [$message->id],
        ])->assertExitCode(0);

        $attachment->refresh();

        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED, $attachment->download_status);
        $this->assertSame(MessageAttachment::LOCAL_DISK_PRIVATE, $attachment->local_disk);
        $this->assertSame(MessageAttachment::LOCAL_PATH_PREFIX.'/'.$message->id.'/photo.jpg', $attachment->local_path);
        $this->assertSame(1, MessageAttachment::query()->count());
    }

    public function test_command_can_backfill_and_download_telegram_photo_in_one_forced_run(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        Http::fake([
            'https://api.telegram.org/bottelegram-token/getFile*' => Http::response([
                'ok' => true,
                'result' => [
                    'file_path' => 'photos/backfilled-photo.jpg',
                    'file_size' => strlen('backfilled-photo-bytes'),
                ],
            ]),
            'https://api.telegram.org/file/bottelegram-token/photos/backfilled-photo.jpg' => Http::response(
                'backfilled-photo-bytes',
                200,
                ['Content-Type' => 'image/jpeg'],
            ),
        ]);
        $message = $this->createTelegramPhotoMessage();

        $this->artisan('bot-media:backfill-missing-attachments', [
            '--force' => true,
            '--download' => true,
            '--message' => [$message->id],
        ])->assertExitCode(0);

        $attachment = MessageAttachment::query()->sole();

        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED, $attachment->download_status);
        $this->assertSame(MessageAttachment::LOCAL_DISK_PRIVATE, $attachment->local_disk);
        $this->assertTrue($attachment->isInlinePreviewable());
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->assertExists((string) $attachment->local_path);
    }

    private function createTelegramPhotoMessage(): Message
    {
        [$channel, $contact, $identity] = $this->createIdentity(Channel::PLATFORM_TELEGRAM, [
            'credentials' => ['token' => 'telegram-token'],
        ]);

        return Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'provider_event_key' => null,
            'external_chat_id' => '4242',
            'external_message_id' => '1021',
            'text' => 'Photo',
            'raw_payload' => [
                'update_id' => 9001,
                'message' => [
                    'message_id' => 1021,
                    'date' => now()->timestamp,
                    'chat' => [
                        'id' => 4242,
                        'type' => 'private',
                    ],
                    'from' => [
                        'id' => 4242,
                        'is_bot' => false,
                        'first_name' => 'Client',
                    ],
                    'caption' => 'Photo',
                    'photo' => [
                        [
                            'file_id' => 'small-photo-file',
                            'file_unique_id' => 'small-photo-unique',
                            'file_size' => 100,
                            'width' => 90,
                            'height' => 90,
                        ],
                        [
                            'file_id' => 'large-photo-file',
                            'file_unique_id' => 'large-photo-unique',
                            'file_size' => 5000,
                            'width' => 800,
                            'height' => 600,
                        ],
                    ],
                ],
            ],
        ]);
    }

    private function createMaxImageMessage(): Message
    {
        [$channel, $contact, $identity] = $this->createIdentity(Channel::PLATFORM_MAX, [
            'credentials' => ['token' => 'max-token'],
        ]);

        return Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'provider_event_key' => null,
            'external_chat_id' => 'max-chat-1',
            'external_message_id' => 'mid.legacy-image-1',
            'text' => 'Photo',
            'raw_payload' => [
                'update_type' => 'message_created',
                'user_locale' => 'ru',
                'message' => [
                    'sender' => [
                        'user_id' => 60850565,
                        'name' => 'Client',
                    ],
                    'recipient' => [
                        'chat_id' => 'max-chat-1',
                    ],
                    'body' => [
                        'mid' => 'mid.legacy-image-1',
                        'text' => 'Photo',
                        'attachments' => [
                            [
                                'type' => 'image',
                                'payload' => [
                                    'url' => 'https://i.oneme.ru/private/image.jpg?access_token=secret-token',
                                    'token' => 'secret-token',
                                    'photo_id' => 25852958504,
                                    'width' => 640,
                                    'height' => 480,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $channelAttributes
     * @return array{0: Channel, 1: Contact, 2: ContactIdentity}
     */
    private function createIdentity(string $platform, array $channelAttributes = []): array
    {
        $channel = Channel::factory()->create([
            'platform' => $platform,
            ...$channelAttributes,
        ]);
        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $platform,
            'external_user_id' => $platform === Channel::PLATFORM_MAX ? '60850565' : '4242',
        ]);

        return [$channel, $contact, $identity];
    }
}
