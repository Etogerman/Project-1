<?php

namespace Tests\Feature;

use App\Data\Bots\IncomingBotMessage;
use App\Models\Channel;
use App\Models\MessageAttachment;
use App\Services\Bots\BotIncomingMessageNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BotIncomingMessageNormalizerTest extends TestCase
{
    use RefreshDatabase;

    public function test_telegram_contact_share_payload_is_normalized(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $payload = [
            'update_id' => 77,
            'message' => [
                'message_id' => 88,
                'date' => 1_711_539_200,
                'from' => [
                    'id' => 200,
                    'username' => 'telegram_user',
                    'is_bot' => false,
                ],
                'chat' => [
                    'id' => 300,
                    'type' => 'private',
                ],
                'contact' => [
                    'phone_number' => '+7 999 123 45 67',
                    'user_id' => 200,
                ],
            ],
        ];

        $message = app(BotIncomingMessageNormalizer::class)->normalize($channel, $payload);

        $this->assertInstanceOf(IncomingBotMessage::class, $message);
        $this->assertSame(IncomingBotMessage::KIND_INBOUND_CONTACT_SHARE, $message->inboundKind);
        $this->assertSame('+7 999 123 45 67', $message->sharedPhoneNumber);
        $this->assertSame('200', $message->sharedContactUserId);
        $this->assertNull($message->text);
        $this->assertNull($message->messageParameter);
        $this->assertSame('88', $message->externalMessageId);
        $this->assertSame('77', $message->providerEventKey);
    }

    public function test_telegram_start_payload_is_normalized_into_message_parameter(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $payload = [
            'update_id' => 78,
            'message' => [
                'message_id' => 89,
                'date' => 1_711_539_200,
                'text' => '/start TEXT_1',
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
        ];

        $message = app(BotIncomingMessageNormalizer::class)->normalize($channel, $payload);

        $this->assertInstanceOf(IncomingBotMessage::class, $message);
        $this->assertSame('/start TEXT_1', $message->text);
        $this->assertSame('TEXT_1', $message->messageParameter);
    }

    public function test_plain_telegram_start_does_not_produce_message_parameter(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $payload = [
            'update_id' => 79,
            'message' => [
                'message_id' => 90,
                'date' => 1_711_539_200,
                'text' => '/start',
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
        ];

        $message = app(BotIncomingMessageNormalizer::class)->normalize($channel, $payload);

        $this->assertInstanceOf(IncomingBotMessage::class, $message);
        $this->assertSame('/start', $message->text);
        $this->assertNull($message->messageParameter);
    }

    public function test_regular_telegram_text_does_not_produce_message_parameter(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $payload = [
            'update_id' => 80,
            'message' => [
                'message_id' => 91,
                'date' => 1_711_539_200,
                'text' => 'hello',
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
        ];

        $message = app(BotIncomingMessageNormalizer::class)->normalize($channel, $payload);

        $this->assertInstanceOf(IncomingBotMessage::class, $message);
        $this->assertSame('hello', $message->text);
        $this->assertNull($message->messageParameter);
        $this->assertSame([], $message->media);
    }

    public function test_telegram_text_entities_are_normalized_to_ab_rich_text(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $payload = [
            'update_id' => 81,
            'message' => [
                'message_id' => 92,
                'date' => 1_711_539_200,
                'text' => '😀 bold link',
                'entities' => [
                    [
                        'type' => 'bold',
                        'offset' => 3,
                        'length' => 4,
                    ],
                    [
                        'type' => 'text_link',
                        'offset' => 8,
                        'length' => 4,
                        'url' => 'https://example.test/path',
                    ],
                ],
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
        ];

        $message = app(BotIncomingMessageNormalizer::class)->normalize($channel, $payload);

        $this->assertInstanceOf(IncomingBotMessage::class, $message);
        $this->assertSame('😀 bold link', $message->text);
        $this->assertSame([
            'version' => 1,
            'plain_text' => '😀 bold link',
            'runs' => [
                [
                    'text' => '😀 ',
                    'marks' => [],
                ],
                [
                    'text' => 'bold',
                    'marks' => [
                        ['type' => 'bold'],
                    ],
                ],
                [
                    'text' => ' ',
                    'marks' => [],
                ],
                [
                    'text' => 'link',
                    'marks' => [
                        ['type' => 'link', 'href' => 'https://example.test/path'],
                    ],
                ],
            ],
        ], $message->richText);
    }

    public function test_telegram_poll_payload_is_normalized_as_readable_text(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $payload = [
            'update_id' => 81,
            'message' => [
                'message_id' => 92,
                'date' => 1_711_539_200,
                'from' => [
                    'id' => 200,
                    'username' => 'telegram_user',
                    'is_bot' => false,
                ],
                'chat' => [
                    'id' => 300,
                    'type' => 'private',
                ],
                'poll' => [
                    'id' => 'telegram-poll-1',
                    'question' => 'Что делаем дальше?',
                    'options' => [
                        ['text' => 'Проверяем локально'],
                        ['text' => 'Откладываем'],
                    ],
                ],
            ],
        ];

        $message = app(BotIncomingMessageNormalizer::class)->normalize($channel, $payload);

        $this->assertInstanceOf(IncomingBotMessage::class, $message);
        $this->assertSame("Опрос: Что делаем дальше?\n• Проверяем локально\n• Откладываем", $message->text);
        $this->assertSame([], $message->media);
    }

    public function test_telegram_dice_payload_is_normalized_as_readable_text(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $payload = [
            'update_id' => 82,
            'message' => [
                'message_id' => 93,
                'date' => 1_711_539_200,
                'from' => [
                    'id' => 200,
                    'username' => 'telegram_user',
                    'is_bot' => false,
                ],
                'chat' => [
                    'id' => 300,
                    'type' => 'private',
                ],
                'dice' => [
                    'emoji' => '🎲',
                    'value' => 5,
                ],
            ],
        ];

        $message = app(BotIncomingMessageNormalizer::class)->normalize($channel, $payload);

        $this->assertInstanceOf(IncomingBotMessage::class, $message);
        $this->assertSame('Бросок 🎲: 5', $message->text);
        $this->assertSame([], $message->media);
    }

    public function test_telegram_photo_payload_is_normalized_as_single_largest_image_media_item(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $payload = [
            'update_id' => 81,
            'message' => [
                'message_id' => 92,
                'date' => 1_711_539_200,
                'caption' => 'Фото объекта',
                'media_group_id' => 'telegram-media-group-81',
                'from' => [
                    'id' => 200,
                    'username' => 'telegram_user',
                    'is_bot' => false,
                ],
                'chat' => [
                    'id' => 300,
                    'type' => 'private',
                ],
                'photo' => [
                    [
                        'file_id' => 'telegram-small-file-id',
                        'file_unique_id' => 'telegram-small-unique-id',
                        'file_size' => 600,
                        'width' => 80,
                        'height' => 60,
                    ],
                    [
                        'file_id' => 'telegram-large-file-id',
                        'file_unique_id' => 'telegram-large-unique-id',
                        'file_size' => 4096,
                        'width' => 1280,
                        'height' => 720,
                    ],
                ],
            ],
        ];

        $message = app(BotIncomingMessageNormalizer::class)->normalize($channel, $payload);

        $this->assertInstanceOf(IncomingBotMessage::class, $message);
        $this->assertSame('Фото объекта', $message->text);
        $this->assertSame('telegram-media-group-81', $message->providerGroupKey);
        $this->assertCount(1, $message->media);
        $this->assertSame('image', $message->media[0]['media_kind']);
        $this->assertSame('photo', $message->media[0]['type']);
        $this->assertSame('telegram-large-unique-id', $message->media[0]['provider_attachment_key']);
        $this->assertSame('telegram-large-file-id', $message->media[0]['provider_file_id']);
        $this->assertSame('telegram-large-unique-id', $message->media[0]['provider_file_unique_id']);
        $this->assertSame(4096, $message->media[0]['file_size_bytes']);
        $this->assertSame(1280, $message->media[0]['width']);
        $this->assertSame(720, $message->media[0]['height']);
        $this->assertSame('telegram-media-group-81', $message->media[0]['media_group_id']);
    }

    public function test_telegram_voice_payload_is_normalized_as_voice_media_item(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $payload = [
            'update_id' => 82,
            'message' => [
                'message_id' => 93,
                'date' => 1_711_539_200,
                'from' => [
                    'id' => 200,
                    'username' => 'telegram_user',
                    'is_bot' => false,
                ],
                'chat' => [
                    'id' => 300,
                    'type' => 'private',
                ],
                'voice' => [
                    'file_id' => 'telegram-voice-file-id',
                    'file_unique_id' => 'telegram-voice-unique-id',
                    'duration' => 7,
                    'file_size' => 151_664,
                    'mime_type' => 'audio/ogg',
                ],
            ],
        ];

        $message = app(BotIncomingMessageNormalizer::class)->normalize($channel, $payload);

        $this->assertInstanceOf(IncomingBotMessage::class, $message);
        $this->assertNull($message->text);
        $this->assertSame('93', $message->externalMessageId);
        $this->assertSame('82', $message->providerEventKey);
        $this->assertCount(1, $message->media);
        $this->assertSame('voice', $message->media[0]['media_kind']);
        $this->assertSame('voice', $message->media[0]['type']);
        $this->assertSame('telegram-voice-unique-id', $message->media[0]['provider_attachment_key']);
        $this->assertSame('telegram-voice-file-id', $message->media[0]['provider_file_id']);
        $this->assertSame('telegram-voice-unique-id', $message->media[0]['provider_file_unique_id']);
        $this->assertSame('audio/ogg', $message->media[0]['mime_type']);
        $this->assertSame('ogg', $message->media[0]['extension']);
        $this->assertSame(7, $message->media[0]['duration']);
        $this->assertSame(151_664, $message->media[0]['file_size_bytes']);
    }

    public function test_telegram_document_payload_is_normalized_as_document_media_item(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $payload = [
            'update_id' => 83,
            'message' => [
                'message_id' => 94,
                'date' => 1_711_539_200,
                'caption' => 'Коммерческое предложение',
                'from' => [
                    'id' => 200,
                    'username' => 'telegram_user',
                    'is_bot' => false,
                ],
                'chat' => [
                    'id' => 300,
                    'type' => 'private',
                ],
                'document' => [
                    'file_id' => 'telegram-document-file-id',
                    'file_unique_id' => 'telegram-document-unique-id',
                    'file_name' => 'offer.pdf',
                    'mime_type' => 'application/pdf',
                    'file_size' => 45_678,
                ],
            ],
        ];

        $message = app(BotIncomingMessageNormalizer::class)->normalize($channel, $payload);

        $this->assertInstanceOf(IncomingBotMessage::class, $message);
        $this->assertSame('Коммерческое предложение', $message->text);
        $this->assertSame('94', $message->externalMessageId);
        $this->assertSame('83', $message->providerEventKey);
        $this->assertCount(1, $message->media);
        $this->assertSame('document', $message->media[0]['media_kind']);
        $this->assertSame('document', $message->media[0]['type']);
        $this->assertSame('telegram-document-unique-id', $message->media[0]['provider_attachment_key']);
        $this->assertSame('telegram-document-file-id', $message->media[0]['provider_file_id']);
        $this->assertSame('telegram-document-unique-id', $message->media[0]['provider_file_unique_id']);
        $this->assertSame('offer.pdf', $message->media[0]['file_name']);
        $this->assertSame('application/pdf', $message->media[0]['mime_type']);
        $this->assertSame('pdf', $message->media[0]['extension']);
        $this->assertSame(45_678, $message->media[0]['file_size_bytes']);
    }

    public function test_telegram_video_payload_is_normalized_as_video_media_item(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $payload = [
            'update_id' => 84,
            'message' => [
                'message_id' => 95,
                'date' => 1_711_539_200,
                'caption' => 'Видео объекта',
                'media_group_id' => 'telegram-video-group-84',
                'from' => [
                    'id' => 200,
                    'username' => 'telegram_user',
                    'is_bot' => false,
                ],
                'chat' => [
                    'id' => 300,
                    'type' => 'private',
                ],
                'video' => [
                    'file_id' => 'telegram-video-file-id',
                    'file_unique_id' => 'telegram-video-unique-id',
                    'file_name' => 'room-tour.mp4',
                    'mime_type' => 'video/mp4',
                    'duration' => 12,
                    'width' => 1280,
                    'height' => 720,
                    'file_size' => 987_654,
                ],
            ],
        ];

        $message = app(BotIncomingMessageNormalizer::class)->normalize($channel, $payload);

        $this->assertInstanceOf(IncomingBotMessage::class, $message);
        $this->assertSame('Видео объекта', $message->text);
        $this->assertSame('telegram-video-group-84', $message->providerGroupKey);
        $this->assertCount(1, $message->media);
        $this->assertSame('video', $message->media[0]['media_kind']);
        $this->assertSame('video', $message->media[0]['type']);
        $this->assertSame('telegram-video-unique-id', $message->media[0]['provider_attachment_key']);
        $this->assertSame('telegram-video-file-id', $message->media[0]['provider_file_id']);
        $this->assertSame('telegram-video-unique-id', $message->media[0]['provider_file_unique_id']);
        $this->assertSame('room-tour.mp4', $message->media[0]['file_name']);
        $this->assertSame('video/mp4', $message->media[0]['mime_type']);
        $this->assertSame('mp4', $message->media[0]['extension']);
        $this->assertSame(12, $message->media[0]['duration']);
        $this->assertSame(1280, $message->media[0]['width']);
        $this->assertSame(720, $message->media[0]['height']);
        $this->assertSame(987_654, $message->media[0]['file_size_bytes']);
        $this->assertSame('telegram-video-group-84', $message->media[0]['media_group_id']);
    }

    public function test_telegram_video_note_payload_is_normalized_as_video_note_media_item(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $payload = [
            'update_id' => 85,
            'message' => [
                'message_id' => 96,
                'date' => 1_711_539_200,
                'from' => [
                    'id' => 200,
                    'username' => 'telegram_user',
                    'is_bot' => false,
                ],
                'chat' => [
                    'id' => 300,
                    'type' => 'private',
                ],
                'video_note' => [
                    'file_id' => 'telegram-video-note-file-id',
                    'file_unique_id' => 'telegram-video-note-unique-id',
                    'duration' => 21,
                    'length' => 384,
                    'file_size' => 456_789,
                ],
            ],
        ];

        $message = app(BotIncomingMessageNormalizer::class)->normalize($channel, $payload);

        $this->assertInstanceOf(IncomingBotMessage::class, $message);
        $this->assertCount(1, $message->media);
        $this->assertSame(MessageAttachment::MEDIA_KIND_VIDEO_NOTE, $message->media[0]['media_kind']);
        $this->assertSame('video_note', $message->media[0]['type']);
        $this->assertSame('telegram-video-note-unique-id', $message->media[0]['provider_attachment_key']);
        $this->assertSame('telegram-video-note-file-id', $message->media[0]['provider_file_id']);
        $this->assertSame('telegram-video-note-unique-id', $message->media[0]['provider_file_unique_id']);
        $this->assertSame('mp4', $message->media[0]['extension']);
        $this->assertSame(21, $message->media[0]['duration']);
        $this->assertSame(384, $message->media[0]['width']);
        $this->assertSame(384, $message->media[0]['height']);
        $this->assertSame(456_789, $message->media[0]['file_size_bytes']);
        $this->assertTrue($message->media[0]['is_video_note']);
    }

    public function test_telegram_age_range_callback_query_is_normalized(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $payload = [
            'update_id' => 91,
            'callback_query' => [
                'id' => 'callback-91',
                'data' => 'age_range:24_29',
                'from' => [
                    'id' => 200,
                    'username' => 'telegram_user',
                    'first_name' => 'Герман',
                    'is_bot' => false,
                ],
                'message' => [
                    'message_id' => 88,
                    'date' => 1_711_539_200,
                    'chat' => [
                        'id' => 300,
                        'type' => 'private',
                    ],
                ],
            ],
        ];

        $message = app(BotIncomingMessageNormalizer::class)->normalize($channel, $payload);

        $this->assertInstanceOf(IncomingBotMessage::class, $message);
        $this->assertSame(IncomingBotMessage::KIND_INBOUND_USER, $message->inboundKind);
        $this->assertSame('24_29', $message->text);
        $this->assertNull($message->messageParameter);
        $this->assertSame('callback-91', $message->externalMessageId);
        $this->assertSame('91', $message->providerEventKey);
    }

    public function test_telegram_russian_region_confirm_callback_query_is_normalized(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $payload = [
            'update_id' => 92,
            'callback_query' => [
                'id' => 'callback-92',
                'data' => 'russian_region_confirm:2',
                'from' => [
                    'id' => 200,
                    'username' => 'telegram_user',
                    'first_name' => 'Герман',
                    'is_bot' => false,
                ],
                'message' => [
                    'message_id' => 89,
                    'date' => 1_711_539_200,
                    'chat' => [
                        'id' => 300,
                        'type' => 'private',
                    ],
                ],
            ],
        ];

        $message = app(BotIncomingMessageNormalizer::class)->normalize($channel, $payload);

        $this->assertInstanceOf(IncomingBotMessage::class, $message);
        $this->assertSame(IncomingBotMessage::KIND_INBOUND_USER, $message->inboundKind);
        $this->assertSame('russian_region_confirm:2', $message->text);
        $this->assertNull($message->messageParameter);
        $this->assertSame('callback-92', $message->externalMessageId);
        $this->assertSame('92', $message->providerEventKey);
    }

    public function test_telegram_warmup_scenario_callback_query_is_normalized(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $payload = [
            'update_id' => 93,
            'callback_query' => [
                'id' => 'callback-93',
                'data' => 'scenario:warmup:17:positive',
                'from' => [
                    'id' => 200,
                    'username' => 'telegram_user',
                    'first_name' => 'Герман',
                    'is_bot' => false,
                ],
                'message' => [
                    'message_id' => 90,
                    'date' => 1_711_539_200,
                    'chat' => [
                        'id' => 300,
                        'type' => 'private',
                    ],
                ],
            ],
        ];

        $message = app(BotIncomingMessageNormalizer::class)->normalize($channel, $payload);

        $this->assertInstanceOf(IncomingBotMessage::class, $message);
        $this->assertSame(IncomingBotMessage::KIND_INBOUND_USER, $message->inboundKind);
        $this->assertSame('warmup:positive', $message->text);
        $this->assertNull($message->messageParameter);
        $this->assertSame('callback-93', $message->externalMessageId);
        $this->assertSame('93', $message->providerEventKey);
    }

    public function test_telegram_generic_scenario_callback_query_is_normalized(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $payload = [
            'update_id' => 94,
            'callback_query' => [
                'id' => 'callback-94',
                'data' => 'scenario:17:start_selection',
                'from' => [
                    'id' => 200,
                    'username' => 'telegram_user',
                    'first_name' => 'Герман',
                    'is_bot' => false,
                ],
                'message' => [
                    'message_id' => 91,
                    'date' => 1_711_539_200,
                    'chat' => [
                        'id' => 300,
                        'type' => 'private',
                    ],
                ],
            ],
        ];

        $message = app(BotIncomingMessageNormalizer::class)->normalize($channel, $payload);

        $this->assertInstanceOf(IncomingBotMessage::class, $message);
        $this->assertSame(IncomingBotMessage::KIND_INBOUND_USER, $message->inboundKind);
        $this->assertSame('scenario:start_selection', $message->text);
        $this->assertNull($message->messageParameter);
        $this->assertSame('callback-94', $message->externalMessageId);
        $this->assertSame('94', $message->providerEventKey);
    }

    public function test_telegram_v3_button_callback_query_is_normalized(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $payload = [
            'update_id' => 941,
            'callback_query' => [
                'id' => 'callback-v3-1',
                'data' => 'v3b:start:btn_catalog',
                'from' => [
                    'id' => 200,
                    'username' => 'telegram_user',
                    'first_name' => 'Герман',
                    'is_bot' => false,
                ],
                'message' => [
                    'message_id' => 911,
                    'date' => 1_711_539_200,
                    'chat' => [
                        'id' => 300,
                        'type' => 'private',
                    ],
                ],
            ],
        ];

        $message = app(BotIncomingMessageNormalizer::class)->normalize($channel, $payload);

        $this->assertInstanceOf(IncomingBotMessage::class, $message);
        $this->assertSame(IncomingBotMessage::KIND_INBOUND_USER, $message->inboundKind);
        $this->assertSame('v3b:start:btn_catalog', $message->text);
        $this->assertNull($message->messageParameter);
        $this->assertSame('callback-v3-1', $message->externalMessageId);
        $this->assertSame('941', $message->providerEventKey);
    }

    public function test_telegram_my_chat_member_block_event_is_normalized(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $payload = [
            'update_id' => 95,
            'my_chat_member' => [
                'date' => 1_711_539_200,
                'from' => [
                    'id' => 200,
                    'username' => 'telegram_user',
                    'first_name' => 'Герман',
                    'is_bot' => false,
                ],
                'chat' => [
                    'id' => 200,
                    'type' => 'private',
                ],
                'old_chat_member' => [
                    'status' => 'member',
                ],
                'new_chat_member' => [
                    'status' => 'kicked',
                ],
            ],
        ];

        $message = app(BotIncomingMessageNormalizer::class)->normalize($channel, $payload);

        $this->assertInstanceOf(IncomingBotMessage::class, $message);
        $this->assertSame(IncomingBotMessage::KIND_INBOUND_SYSTEM_EVENT, $message->inboundKind);
        $this->assertSame(IncomingBotMessage::SYSTEM_EVENT_BOT_BLOCKED_BY_USER, $message->systemEventCode);
        $this->assertSame('200', $message->externalChatId);
        $this->assertSame('200', $message->externalUserId);
        $this->assertNull($message->externalMessageId);
        $this->assertNull($message->text);
        $this->assertSame('95', $message->providerEventKey);
    }

    public function test_telegram_my_chat_member_unblock_event_is_normalized(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $payload = [
            'update_id' => 96,
            'my_chat_member' => [
                'date' => 1_711_539_200,
                'from' => [
                    'id' => 200,
                    'username' => 'telegram_user',
                    'first_name' => 'Герман',
                    'is_bot' => false,
                ],
                'chat' => [
                    'id' => 200,
                    'type' => 'private',
                ],
                'old_chat_member' => [
                    'status' => 'kicked',
                ],
                'new_chat_member' => [
                    'status' => 'member',
                ],
            ],
        ];

        $message = app(BotIncomingMessageNormalizer::class)->normalize($channel, $payload);

        $this->assertInstanceOf(IncomingBotMessage::class, $message);
        $this->assertSame(IncomingBotMessage::KIND_INBOUND_SYSTEM_EVENT, $message->inboundKind);
        $this->assertSame(IncomingBotMessage::SYSTEM_EVENT_BOT_UNBLOCKED_BY_USER, $message->systemEventCode);
        $this->assertSame('96', $message->providerEventKey);
    }

    public function test_telegram_my_chat_member_ignores_unsupported_transition(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $payload = [
            'update_id' => 97,
            'my_chat_member' => [
                'date' => 1_711_539_200,
                'from' => [
                    'id' => 200,
                    'is_bot' => false,
                ],
                'chat' => [
                    'id' => 200,
                    'type' => 'private',
                ],
                'old_chat_member' => [
                    'status' => 'member',
                ],
                'new_chat_member' => [
                    'status' => 'administrator',
                ],
            ],
        ];

        $this->assertNull(app(BotIncomingMessageNormalizer::class)->normalize($channel, $payload));
    }

    public function test_telegram_my_chat_member_ignores_non_private_or_mismatched_identity(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $groupPayload = [
            'update_id' => 98,
            'my_chat_member' => [
                'date' => 1_711_539_200,
                'from' => [
                    'id' => 200,
                    'is_bot' => false,
                ],
                'chat' => [
                    'id' => -1001,
                    'type' => 'group',
                ],
                'old_chat_member' => [
                    'status' => 'member',
                ],
                'new_chat_member' => [
                    'status' => 'kicked',
                ],
            ],
        ];

        $mismatchedPrivatePayload = [
            'update_id' => 99,
            'my_chat_member' => [
                'date' => 1_711_539_200,
                'from' => [
                    'id' => 200,
                    'is_bot' => false,
                ],
                'chat' => [
                    'id' => 201,
                    'type' => 'private',
                ],
                'old_chat_member' => [
                    'status' => 'member',
                ],
                'new_chat_member' => [
                    'status' => 'kicked',
                ],
            ],
        ];

        $this->assertNull(app(BotIncomingMessageNormalizer::class)->normalize($channel, $groupPayload));
        $this->assertNull(app(BotIncomingMessageNormalizer::class)->normalize($channel, $mismatchedPrivatePayload));
    }

    public function test_max_bot_started_payload_is_normalized_as_store_only_inbound_event(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
        ]);

        $payload = [
            'update_type' => 'bot_started',
            'chat_id' => 700,
            'payload' => 'promo_123',
            'timestamp' => '2026-04-03T10:00:00+03:00',
            'user' => [
                'user_id' => 500,
                'username' => 'max_user',
                'name' => 'Герман',
            ],
        ];

        $message = app(BotIncomingMessageNormalizer::class)->normalize($channel, $payload);

        $this->assertInstanceOf(IncomingBotMessage::class, $message);
        $this->assertSame(IncomingBotMessage::KIND_INBOUND_USER, $message->inboundKind);
        $this->assertSame('700', $message->externalChatId);
        $this->assertSame('500', $message->externalUserId);
        $this->assertSame('max_user', $message->externalUsername);
        $this->assertSame('Герман', $message->contactName);
        $this->assertNull($message->externalMessageId);
        $this->assertNull($message->text);
        $this->assertSame('promo_123', $message->messageParameter);
        $this->assertSame('bot_started', data_get($message->rawPayload, 'update_type'));
        $this->assertStringStartsWith('max-bot-started:', $message->providerEventKey ?? '');
        $this->assertSame('2026-04-03 07:00:00', $message->receivedAt->utc()->format('Y-m-d H:i:s'));
    }

    public function test_max_bot_started_payload_without_deep_link_payload_is_normalized(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
        ]);

        $payload = [
            'update_type' => 'bot_started',
            'chat_id' => 701,
            'timestamp' => '2026-04-03T10:05:00+03:00',
            'user' => [
                'user_id' => 501,
                'is_bot' => false,
            ],
        ];

        $message = app(BotIncomingMessageNormalizer::class)->normalize($channel, $payload);

        $this->assertInstanceOf(IncomingBotMessage::class, $message);
        $this->assertSame(IncomingBotMessage::KIND_INBOUND_USER, $message->inboundKind);
        $this->assertSame('701', $message->externalChatId);
        $this->assertSame('501', $message->externalUserId);
        $this->assertNull($message->text);
        $this->assertNull($message->messageParameter);
        $this->assertStringStartsWith('max-bot-started:', $message->providerEventKey ?? '');
    }

    public function test_max_bot_started_payload_with_blank_deep_link_payload_does_not_produce_message_parameter(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
        ]);

        $payload = [
            'update_type' => 'bot_started',
            'chat_id' => 702,
            'payload' => '   ',
            'timestamp' => '2026-04-03T10:06:00+03:00',
            'user' => [
                'user_id' => 502,
                'is_bot' => false,
            ],
        ];

        $message = app(BotIncomingMessageNormalizer::class)->normalize($channel, $payload);

        $this->assertInstanceOf(IncomingBotMessage::class, $message);
        $this->assertSame('702', $message->externalChatId);
        $this->assertSame('502', $message->externalUserId);
        $this->assertNull($message->text);
        $this->assertNull($message->messageParameter);
        $this->assertStringStartsWith('max-bot-started:', $message->providerEventKey ?? '');
    }

    public function test_max_poll_attachment_payload_is_normalized_as_readable_text(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
        ]);

        $payload = [
            'update_type' => 'message_created',
            'user_locale' => 'ru',
            'message' => [
                'sender' => [
                    'user_id' => 500,
                    'username' => 'max_user',
                    'is_bot' => false,
                ],
                'recipient' => [
                    'chat_id' => 700,
                ],
                'body' => [
                    'mid' => 'max-poll-77',
                    'text' => null,
                    'attachments' => [[
                        'type' => 'poll',
                        'payload' => [
                            'question' => 'Что делаем дальше?',
                            'options' => [
                                ['text' => 'Проверяем локально'],
                                ['text' => 'Откладываем'],
                            ],
                        ],
                    ]],
                ],
            ],
        ];

        $message = app(BotIncomingMessageNormalizer::class)->normalize($channel, $payload);

        $this->assertInstanceOf(IncomingBotMessage::class, $message);
        $this->assertSame("Опрос: Что делаем дальше?\n• Проверяем локально\n• Откладываем", $message->text);
        $this->assertSame(IncomingBotMessage::KIND_INBOUND_USER, $message->inboundKind);
        $this->assertSame([], $message->media);
    }

    public function test_max_contact_share_payload_is_normalized(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
        ]);

        $payload = [
            'update_type' => 'message_created',
            'user_locale' => 'ru',
            'message' => [
                'sender' => [
                    'user_id' => 500,
                    'username' => 'max_user',
                    'is_bot' => false,
                ],
                'recipient' => [
                    'chat_id' => 700,
                ],
                'body' => [
                    'mid' => 'max-contact-77',
                    'contact' => [
                        'phone' => '+7 999 123 45 67',
                        'user_id' => 500,
                    ],
                ],
            ],
        ];

        $message = app(BotIncomingMessageNormalizer::class)->normalize($channel, $payload);

        $this->assertInstanceOf(IncomingBotMessage::class, $message);
        $this->assertSame(IncomingBotMessage::KIND_INBOUND_CONTACT_SHARE, $message->inboundKind);
        $this->assertSame('+7 999 123 45 67', $message->sharedPhoneNumber);
        $this->assertSame('500', $message->sharedContactUserId);
        $this->assertNull($message->text);
        $this->assertNull($message->messageParameter);
        $this->assertSame('max-contact-77', $message->externalMessageId);
        $this->assertSame('max-contact-77', $message->providerEventKey);
    }

    public function test_max_contact_share_vcf_attachment_payload_is_normalized(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
        ]);

        $payload = [
            'update_type' => 'message_created',
            'user_locale' => 'ru',
            'message' => [
                'sender' => [
                    'user_id' => 228532008,
                    'first_name' => 'German',
                    'last_name' => 'Abrikosov',
                    'is_bot' => false,
                ],
                'recipient' => [
                    'chat_id' => 66552012,
                ],
                'body' => [
                    'mid' => 'max-contact-vcf-1',
                    'text' => null,
                    'attachments' => [[
                        'type' => 'contact',
                        'payload' => [
                            'max_info' => [
                                'user_id' => 228532008,
                            ],
                            'vcf_info' => "BEGIN:VCARD\r\nVERSION:3.0\r\nTEL;TYPE=cell:79263527111\r\nFN:German Abrikosov\r\nEND:VCARD",
                        ],
                    ]],
                ],
            ],
        ];

        $message = app(BotIncomingMessageNormalizer::class)->normalize($channel, $payload);

        $this->assertInstanceOf(IncomingBotMessage::class, $message);
        $this->assertSame(IncomingBotMessage::KIND_INBOUND_CONTACT_SHARE, $message->inboundKind);
        $this->assertSame('79263527111', $message->sharedPhoneNumber);
        $this->assertSame('228532008', $message->sharedContactUserId);
        $this->assertNull($message->text);
        $this->assertNull($message->messageParameter);
        $this->assertSame('max-contact-vcf-1', $message->externalMessageId);
        $this->assertSame('max-contact-vcf-1', $message->providerEventKey);
    }

    public function test_max_text_markup_is_normalized_to_ab_rich_text(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
        ]);

        $text = "😀 обычный\nЖирный\nКурсив\nПодчеркнутый\nЗачеркнутый\nЦитата\nПодсветка\nМоно\nСсылка";
        $offsetOf = fn (string $needle): int => (int) mb_strpos($text, $needle);
        $lengthOf = fn (string $needle): int => mb_strlen($needle);

        $payload = [
            'update_type' => 'message_created',
            'user_locale' => 'ru',
            'message' => [
                'sender' => [
                    'user_id' => 500,
                    'username' => 'max_user',
                    'is_bot' => false,
                ],
                'recipient' => [
                    'chat_id' => 700,
                ],
                'body' => [
                    'mid' => 'max-rich-text-77',
                    'text' => $text,
                    'markup' => [
                        ['type' => 'strong', 'from' => $offsetOf('Жирный'), 'length' => $lengthOf('Жирный')],
                        ['type' => 'emphasized', 'from' => $offsetOf('Курсив'), 'length' => $lengthOf('Курсив')],
                        ['type' => 'underline', 'from' => $offsetOf('Подчеркнутый'), 'length' => $lengthOf('Подчеркнутый')],
                        ['type' => 'strikethrough', 'from' => $offsetOf('Зачеркнутый'), 'length' => $lengthOf('Зачеркнутый')],
                        ['type' => 'quote', 'from' => $offsetOf('Цитата'), 'length' => $lengthOf('Цитата')],
                        ['type' => 'highlighted', 'from' => $offsetOf('Подсветка'), 'length' => $lengthOf('Подсветка')],
                        ['type' => 'monospaced', 'from' => $offsetOf('Моно'), 'length' => $lengthOf('Моно')],
                        ['type' => 'link', 'from' => $offsetOf('Ссылка'), 'length' => $lengthOf('Ссылка'), 'url' => 'https://example.test/max'],
                    ],
                ],
            ],
        ];

        $message = app(BotIncomingMessageNormalizer::class)->normalize($channel, $payload);

        $this->assertInstanceOf(IncomingBotMessage::class, $message);
        $this->assertSame($text, $message->text);
        $this->assertIsArray($message->richText);

        $markedRuns = collect($message->richText['runs'])
            ->filter(fn (array $run): bool => $run['marks'] !== [])
            ->mapWithKeys(fn (array $run): array => [$run['text'] => $run['marks']])
            ->all();

        $this->assertSame([['type' => 'bold']], $markedRuns['Жирный']);
        $this->assertSame([['type' => 'italic']], $markedRuns['Курсив']);
        $this->assertSame([['type' => 'underline']], $markedRuns['Подчеркнутый']);
        $this->assertSame([['type' => 'strikethrough']], $markedRuns['Зачеркнутый']);
        $this->assertSame([['type' => 'quote']], $markedRuns['Цитата']);
        $this->assertSame([['type' => 'highlight']], $markedRuns['Подсветка']);
        $this->assertSame([['type' => 'code']], $markedRuns['Моно']);
        $this->assertSame([['type' => 'link', 'href' => 'https://example.test/max']], $markedRuns['Ссылка']);
    }

    public function test_max_forwarded_message_uses_nested_body_text_and_markup(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
        ]);

        $text = "Пересланный текст\nЖирная подпись";
        $offset = (int) mb_strpos($text, 'Жирная подпись');

        $payload = [
            'update_type' => 'message_created',
            'user_locale' => 'ru',
            'message' => [
                'sender' => [
                    'user_id' => 500,
                    'username' => 'max_user',
                    'is_bot' => false,
                ],
                'recipient' => [
                    'chat_id' => 700,
                ],
                'body' => [
                    'mid' => 'max-forwarded-rich-text-77',
                    'text' => null,
                ],
                'link' => [
                    'type' => 'forward',
                    'sender' => [
                        'name' => 'Tanya',
                        'is_bot' => false,
                    ],
                    'message' => [
                        'body' => [
                            'text' => $text,
                            'markup' => [
                                [
                                    'type' => 'strong',
                                    'from' => $offset,
                                    'length' => mb_strlen('Жирная подпись'),
                                ],
                            ],
                            'attachments' => [
                                [
                                    'type' => 'image',
                                    'payload' => [
                                        'photo_id' => 25852958504,
                                        'url' => 'https://i.oneme.ru/i?r=secret-url',
                                        'token' => 'secret-token',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $message = app(BotIncomingMessageNormalizer::class)->normalize($channel, $payload);

        $this->assertInstanceOf(IncomingBotMessage::class, $message);
        $this->assertSame($text, $message->text);
        $this->assertSame([['type' => 'bold']], collect($message->richText['runs'])
            ->firstWhere('text', 'Жирная подпись')['marks']);
        $this->assertCount(1, $message->media);
        $this->assertSame('image', $message->media[0]['media_kind']);
    }

    public function test_max_image_and_file_attachments_are_normalized_without_secret_url_or_token(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
        ]);

        $payload = [
            'update_type' => 'message_created',
            'user_locale' => 'ru',
            'message' => [
                'sender' => [
                    'user_id' => 500,
                    'username' => 'max_user',
                    'is_bot' => false,
                ],
                'recipient' => [
                    'chat_id' => 700,
                ],
                'body' => [
                    'mid' => 'max-media-77',
                    'text' => null,
                    'attachments' => [
                        [
                            'type' => 'image',
                            'payload' => [
                                'photo_id' => 25852958504,
                                'url' => 'https://i.oneme.ru/i?r=secret-url',
                                'token' => 'secret-token',
                                'width' => 538,
                                'height' => 1280,
                            ],
                        ],
                        [
                            'type' => 'file',
                            'payload' => [
                                'file_id' => 'max-file-id',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $message = app(BotIncomingMessageNormalizer::class)->normalize($channel, $payload);

        $this->assertInstanceOf(IncomingBotMessage::class, $message);
        $this->assertSame('max-media-77', $message->providerEventKey);
        $this->assertCount(2, $message->media);
        $this->assertSame('image', $message->media[0]['media_kind']);
        $this->assertSame('25852958504', $message->media[0]['provider_attachment_key']);
        $this->assertSame('25852958504', $message->media[0]['provider_file_reference']);
        $this->assertSame(538, $message->media[0]['width']);
        $this->assertSame(1280, $message->media[0]['height']);
        $this->assertSame('document', $message->media[1]['media_kind']);
        $this->assertSame('max-file-id', $message->media[1]['provider_attachment_key']);
        $this->assertSame('max-file-id', $message->media[1]['provider_file_reference']);
        $this->assertStringNotContainsString('secret-url', json_encode($message->media[0], JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('secret-token', json_encode($message->media[0], JSON_THROW_ON_ERROR));
    }

    public function test_max_sticker_attachment_is_normalized_as_sticker_without_secret_url(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
        ]);

        $payload = [
            'update_type' => 'message_created',
            'user_locale' => 'ru',
            'message' => [
                'sender' => [
                    'user_id' => 500,
                    'username' => 'max_user',
                    'is_bot' => false,
                ],
                'recipient' => [
                    'chat_id' => 700,
                ],
                'body' => [
                    'mid' => 'max-sticker-77',
                    'text' => null,
                    'attachments' => [
                        [
                            'type' => 'sticker',
                            'width' => 144,
                            'height' => 144,
                            'payload' => [
                                'url' => 'https://st.mycdn.me/static/messages/res/images/stub/sticker_31856a27@2x.png',
                                'code' => '429b5',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $message = app(BotIncomingMessageNormalizer::class)->normalize($channel, $payload);

        $this->assertInstanceOf(IncomingBotMessage::class, $message);
        $this->assertSame('max-sticker-77', $message->providerEventKey);
        $this->assertCount(1, $message->media);
        $this->assertSame(MessageAttachment::MEDIA_KIND_STICKER, $message->media[0]['media_kind']);
        $this->assertSame('sticker', $message->media[0]['type']);
        $this->assertSame('429b5', $message->media[0]['provider_attachment_key']);
        $this->assertSame('429b5', $message->media[0]['provider_file_reference']);
        $this->assertSame(144, $message->media[0]['width']);
        $this->assertSame(144, $message->media[0]['height']);
        $this->assertSame('429b5', data_get($message->media[0], 'raw_payload_excerpt.sticker_code'));
        $this->assertStringNotContainsString('st.mycdn.me', json_encode($message->media[0], JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('sticker_31856a27', json_encode($message->media[0], JSON_THROW_ON_ERROR));
    }

    public function test_max_round_video_attachment_is_normalized_as_video_note_when_payload_has_explicit_marker(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
        ]);

        $payload = [
            'update_type' => 'message_created',
            'user_locale' => 'ru',
            'message' => [
                'sender' => [
                    'user_id' => 500,
                    'username' => 'max_user',
                    'is_bot' => false,
                ],
                'recipient' => [
                    'chat_id' => 700,
                ],
                'body' => [
                    'mid' => 'max-round-video-77',
                    'text' => null,
                    'attachments' => [
                        [
                            'type' => 'video',
                            'payload' => [
                                'token' => 'secret-video-token',
                                'url' => 'https://max.example/private/round.mp4?access_token=secret-token',
                                'is_video_note' => true,
                                'duration' => 21,
                                'width' => 384,
                                'height' => 384,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $message = app(BotIncomingMessageNormalizer::class)->normalize($channel, $payload);

        $this->assertInstanceOf(IncomingBotMessage::class, $message);
        $this->assertSame('max-round-video-77', $message->providerEventKey);
        $this->assertCount(1, $message->media);
        $this->assertSame(MessageAttachment::MEDIA_KIND_VIDEO_NOTE, $message->media[0]['media_kind']);
        $this->assertSame('video', $message->media[0]['type']);
        $this->assertSame('token:'.sha1('secret-video-token'), $message->media[0]['provider_attachment_key']);
        $this->assertSame(21, $message->media[0]['duration']);
        $this->assertSame(384, $message->media[0]['width']);
        $this->assertSame(384, $message->media[0]['height']);
        $this->assertTrue($message->media[0]['is_video_note']);
        $this->assertStringNotContainsString('secret-video-token', json_encode($message->media[0], JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('access_token', json_encode($message->media[0], JSON_THROW_ON_ERROR));
    }

    public function test_max_forwarded_video_attachment_is_normalized_from_link_message(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
        ]);

        $payload = [
            'update_type' => 'message_created',
            'user_locale' => 'ru',
            'message' => [
                'sender' => [
                    'user_id' => 500,
                    'username' => 'max_user',
                    'is_bot' => false,
                ],
                'recipient' => [
                    'chat_id' => 700,
                ],
                'body' => [
                    'mid' => 'max-forwarded-video-77',
                    'text' => null,
                ],
                'link' => [
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
                                    'id' => 14979531367945,
                                    'token' => 'forwarded-video-token',
                                    'url' => 'https://max.example/private/forwarded.mp4?access_token=secret-token',
                                ],
                                'duration' => 23,
                                'thumbnail' => [
                                    'url' => 'https://max.example/private/thumb.jpg?access_token=secret-token',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $message = app(BotIncomingMessageNormalizer::class)->normalize($channel, $payload);

        $this->assertInstanceOf(IncomingBotMessage::class, $message);
        $this->assertSame('max-forwarded-video-77', $message->providerEventKey);
        $this->assertNull($message->text);
        $this->assertCount(1, $message->media);
        $this->assertSame(MessageAttachment::MEDIA_KIND_VIDEO, $message->media[0]['media_kind']);
        $this->assertSame('video', $message->media[0]['type']);
        $this->assertSame('token:'.sha1('forwarded-video-token'), $message->media[0]['provider_attachment_key']);
        $this->assertSame('token:'.sha1('forwarded-video-token'), $message->media[0]['provider_file_reference']);
        $this->assertSame(23, $message->media[0]['duration']);
        $this->assertStringNotContainsString('forwarded-video-token', json_encode($message->media[0], JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('access_token', json_encode($message->media[0], JSON_THROW_ON_ERROR));
    }

    public function test_max_forwarded_media_message_uses_link_text_and_markup(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
        ]);

        $payload = [
            'update_type' => 'message_created',
            'user_locale' => 'ru',
            'message' => [
                'sender' => [
                    'user_id' => 500,
                    'username' => 'max_user',
                    'is_bot' => false,
                ],
                'recipient' => [
                    'chat_id' => 700,
                ],
                'body' => [
                    'mid' => 'max-forwarded-gallery-77',
                    'text' => null,
                ],
                'link' => [
                    'type' => 'forward',
                    'sender' => [
                        'name' => 'Герман Абрикосов',
                        'is_bot' => false,
                        'user_id' => 228532008,
                    ],
                    'message' => [
                        'mid' => 'forwarded-source-2',
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
                                    'photo_id' => 'forwarded-photo-1',
                                    'url' => 'https://max.example/private/photo.jpg?access_token=secret-token',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $message = app(BotIncomingMessageNormalizer::class)->normalize($channel, $payload);

        $this->assertInstanceOf(IncomingBotMessage::class, $message);
        $this->assertSame("plain\nbold\nitalic under\nstrike", $message->text);
        $this->assertSame([
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
        ], $message->richText);
        $this->assertCount(1, $message->media);
        $this->assertSame(MessageAttachment::MEDIA_KIND_IMAGE, $message->media[0]['media_kind']);
        $this->assertSame('forwarded-photo-1', $message->media[0]['provider_attachment_key']);
        $this->assertStringNotContainsString('secret-token', json_encode($message->media[0], JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('access_token', json_encode($message->media[0], JSON_THROW_ON_ERROR));
    }

    public function test_max_reply_link_message_content_is_not_ingested_as_own(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
        ]);

        $payload = [
            'update_type' => 'message_created',
            'user_locale' => 'ru',
            'message' => [
                'sender' => [
                    'user_id' => 500,
                    'username' => 'max_user',
                    'is_bot' => false,
                ],
                'recipient' => [
                    'chat_id' => 700,
                ],
                'body' => [
                    'mid' => 'max-reply-quote-1',
                    'text' => null,
                ],
                'link' => [
                    'type' => 'reply',
                    'message' => [
                        'mid' => 'quoted-source-1',
                        'text' => 'Текст цитируемого сообщения',
                        'attachments' => [
                            [
                                'type' => 'image',
                                'payload' => [
                                    'photo_id' => 'quoted-photo-1',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $message = app(BotIncomingMessageNormalizer::class)->normalize($channel, $payload);

        $this->assertInstanceOf(IncomingBotMessage::class, $message);
        // Текст цитаты — не наш текст.
        $this->assertNull($message->text);
        $this->assertNull($message->richText);
        // Медиа цитаты — не наше медиа.
        $this->assertSame([], $message->media);
    }

    public function test_max_reply_with_own_text_keeps_own_text_not_quote(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
        ]);

        $payload = [
            'update_type' => 'message_created',
            'user_locale' => 'ru',
            'message' => [
                'sender' => [
                    'user_id' => 501,
                    'username' => 'max_user',
                    'is_bot' => false,
                ],
                'recipient' => [
                    'chat_id' => 701,
                ],
                'body' => [
                    'mid' => 'max-reply-own-text-1',
                    'text' => 'Да, подходит',
                ],
                'link' => [
                    'type' => 'reply',
                    'message' => [
                        'mid' => 'quoted-source-2',
                        'text' => 'Какой вариант выбираете?',
                    ],
                ],
            ],
        ];

        $message = app(BotIncomingMessageNormalizer::class)->normalize($channel, $payload);

        $this->assertInstanceOf(IncomingBotMessage::class, $message);
        $this->assertSame('Да, подходит', $message->text);
    }

    public function test_max_plain_video_attachment_stays_regular_video_without_round_marker(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
        ]);

        $payload = [
            'update_type' => 'message_created',
            'user_locale' => 'ru',
            'message' => [
                'sender' => [
                    'user_id' => 500,
                    'username' => 'max_user',
                    'is_bot' => false,
                ],
                'recipient' => [
                    'chat_id' => 700,
                ],
                'body' => [
                    'mid' => 'max-plain-video-77',
                    'text' => null,
                    'attachments' => [
                        [
                            'type' => 'video',
                            'payload' => [
                                'token' => 'plain-video-token',
                                'url' => 'https://max.example/private/video.mp4?access_token=secret-token',
                                'duration' => 14,
                                'width' => 1280,
                                'height' => 720,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $message = app(BotIncomingMessageNormalizer::class)->normalize($channel, $payload);

        $this->assertInstanceOf(IncomingBotMessage::class, $message);
        $this->assertCount(1, $message->media);
        $this->assertSame(MessageAttachment::MEDIA_KIND_VIDEO, $message->media[0]['media_kind']);
        $this->assertArrayNotHasKey('is_video_note', $message->media[0]);
    }

    public function test_max_contact_share_with_unknown_format_is_still_marked_as_contact_share(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
        ]);

        $payload = [
            'update_type' => 'message_created',
            'user_locale' => 'ru',
            'message' => [
                'sender' => [
                    'user_id' => 500,
                    'is_bot' => false,
                ],
                'recipient' => [
                    'chat_id' => 700,
                ],
                'body' => [
                    'mid' => 'max-contact-unknown',
                    'contact' => [],
                ],
            ],
        ];

        $message = app(BotIncomingMessageNormalizer::class)->normalize($channel, $payload);

        $this->assertInstanceOf(IncomingBotMessage::class, $message);
        $this->assertSame(IncomingBotMessage::KIND_INBOUND_CONTACT_SHARE, $message->inboundKind);
        $this->assertNull($message->sharedPhoneNumber);
        $this->assertNull($message->text);
    }
}
