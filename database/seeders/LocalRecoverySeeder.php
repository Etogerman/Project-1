<?php

namespace Database\Seeders;

use App\Models\AutoReplyRule;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Dialog;
use App\Models\Message;
use App\Models\MessageAttachment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class LocalRecoverySeeder extends Seeder
{
    /**
     * Seed a small local-only recovery dataset for UI and media smoke checks.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('LocalRecoverySeeder is allowed only in local/testing environments.');

            return;
        }

        $this->call(AdminUserSeeder::class);
        $this->call(FieldDictionaryFieldSeeder::class);

        $channels = $this->seedChannels();
        $this->seedAutoReplyRules($channels);
        $this->seedDialogs($channels);

        $this->command?->info('Local recovery demo dataset is ready.');
    }

    /**
     * @return array<string, Channel>
     */
    private function seedChannels(): array
    {
        $telegramBot = $this->updateChannel('Local Demo Telegram Bot', [
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'credentials' => [
                Channel::CREDENTIAL_TOKEN => 'local-demo-telegram-bot-token',
                Channel::CREDENTIAL_WEBHOOK_SECRET => 'local-demo-webhook-secret',
            ],
            'bot_token_present' => true,
            'bot_external_id' => '9001001',
            'bot_username' => 'ab_local_demo_bot',
            'bot_name' => 'AB Local Demo Bot',
            'bot_profile_url' => 'https://t.me/ab_local_demo_bot',
            'auto_reply_mode' => Channel::AUTO_REPLY_MODE_RULES_ONLY,
            'last_webhook_received_at' => Carbon::now()->subMinutes(7),
            'last_reply_sent_at' => Carbon::now()->subMinutes(5),
            'is_active' => true,
            'is_hidden' => false,
            'sync_external_outgoing_enabled' => false,
            'connection_status' => Channel::CONNECTION_STATUS_CONNECTED,
            'webhook_status' => Channel::WEBHOOK_STATUS_INSTALLED,
            'connection_checked_at' => Carbon::now()->subMinutes(4),
            'connection_error_message' => null,
            'provider_webhook_url' => 'https://local.example.test/webhooks/telegram/local-demo',
            'expected_webhook_url' => 'https://local.example.test/webhooks/telegram/local-demo',
        ]);

        $telegramAccount = $this->updateChannel('Local Demo Telegram Account', [
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_ACCOUNT,
            'credentials' => [],
            'bot_token_present' => false,
            'bot_external_id' => null,
            'bot_username' => 'ab_local_account',
            'bot_name' => 'AB Local Account',
            'bot_profile_url' => 'https://t.me/ab_local_account',
            'auto_reply_mode' => Channel::AUTO_REPLY_MODE_RULES_ONLY,
            'last_webhook_received_at' => Carbon::now()->subMinutes(11),
            'last_reply_sent_at' => Carbon::now()->subMinutes(3),
            'is_active' => true,
            'is_hidden' => false,
            'sync_external_outgoing_enabled' => true,
            'connection_status' => Channel::CONNECTION_STATUS_UNSUPPORTED,
            'webhook_status' => Channel::WEBHOOK_STATUS_UNSUPPORTED,
            'connection_checked_at' => null,
            'connection_error_message' => Channel::CONNECTION_ERROR_UNSUPPORTED,
            'provider_webhook_url' => null,
            'expected_webhook_url' => null,
        ]);

        $maxBot = $this->updateChannel('Local Demo MAX Bot', [
            'platform' => Channel::PLATFORM_MAX,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'credentials' => [
                Channel::CREDENTIAL_TOKEN => 'local-demo-max-token',
                Channel::CREDENTIAL_WEBHOOK_SECRET => 'local-demo-max-webhook-secret',
            ],
            'bot_token_present' => true,
            'bot_external_id' => 'max-local-demo',
            'bot_username' => 'ab_local_max',
            'bot_name' => 'AB Local MAX',
            'bot_profile_url' => 'https://max.ru/ab_local_max',
            'auto_reply_mode' => Channel::AUTO_REPLY_MODE_RULES_ONLY,
            'last_webhook_received_at' => Carbon::now()->subMinutes(13),
            'last_reply_sent_at' => Carbon::now()->subMinutes(9),
            'is_active' => true,
            'is_hidden' => false,
            'sync_external_outgoing_enabled' => false,
            'connection_status' => Channel::CONNECTION_STATUS_CONNECTED,
            'webhook_status' => Channel::WEBHOOK_STATUS_INSTALLED,
            'connection_checked_at' => Carbon::now()->subMinutes(8),
            'connection_error_message' => null,
            'provider_webhook_url' => 'https://local.example.test/webhooks/max/local-demo',
            'expected_webhook_url' => 'https://local.example.test/webhooks/max/local-demo',
        ]);

        return [
            'telegram_bot' => $telegramBot,
            'telegram_account' => $telegramAccount,
            'max_bot' => $maxBot,
        ];
    }

    /**
     * @param  array<string, Channel>  $channels
     */
    private function seedAutoReplyRules(array $channels): void
    {
        $this->updateAutoReplyRule($channels['telegram_bot'], 'привет', 'Здравствуйте! Чем можем помочь?');
        $this->updateAutoReplyRule($channels['max_bot'], 'старт', 'Добро пожаловать в локальный MAX-канал.');
    }

    /**
     * @param  array<string, Channel>  $channels
     */
    private function seedDialogs(array $channels): void
    {
        $this->seedDialogScenario($channels['telegram_bot'], [
            'external_user_id' => 'local-demo-tg-1001',
            'external_chat_id' => 'local-demo-chat-1001',
            'external_username' => 'elena_demo',
            'display_name' => 'Елена Смирнова',
            'contact' => [
                'name' => 'Local Demo: Елена Смирнова',
                'first_name' => 'Елена',
                'last_name' => 'Смирнова',
                'gender' => 'female',
                'gender_source' => Contact::GENDER_SOURCE_CLIENT,
                'age_range' => '30_39',
                'city' => 'Казань',
                'region' => 'Республика Татарстан',
                'region_status' => Contact::REGION_STATUS_RESOLVED,
                'region_source' => Contact::REGION_SOURCE_MANUAL,
                'data_collection_status' => Contact::DATA_COLLECTION_STATUS_COMPLETED,
            ],
            'dialog' => [
                'stage' => Dialog::STAGE_QUESTIONNAIRE_COMPLETED,
                'confirmed_phone_raw' => '+7 999 100-10-01',
                'confirmed_phone_normalized' => '79991001001',
                'phone_confirmed_at' => Carbon::now()->subHours(3),
                'phone_confirmed_via' => Dialog::PHONE_CONFIRMED_VIA_PHONE_CAPTURE,
                'fields_payload' => [
                    'local_recovery' => true,
                    'intent' => 'consultation',
                ],
            ],
            'messages' => [
                [
                    'key' => 'local-demo:tgbot:1001:inbound:1',
                    'direction' => Message::DIRECTION_INBOUND,
                    'message_kind' => Message::KIND_INBOUND_USER,
                    'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
                    'external_message_id' => '1001',
                    'text' => 'Здравствуйте, хочу уточнить условия.',
                    'received_at' => Carbon::now()->subHours(4),
                    'raw_payload' => ['message' => ['text' => 'Здравствуйте, хочу уточнить условия.']],
                ],
                [
                    'key' => 'local-demo:tgbot:1001:outbound:1',
                    'direction' => Message::DIRECTION_OUTBOUND,
                    'message_kind' => Message::KIND_OUTBOUND_AUTO_REPLY,
                    'sent_by_type' => Message::SENT_BY_TYPE_AUTO_REPLY,
                    'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_AUTO_REPLY_RULE,
                    'external_message_id' => '1002',
                    'text' => 'Здравствуйте! Чем можем помочь?',
                    'received_at' => Carbon::now()->subHours(4)->addMinute(),
                    'raw_payload' => ['result' => ['message_id' => 1002]],
                ],
                [
                    'key' => 'local-demo:tgbot:1001:inbound:photo',
                    'direction' => Message::DIRECTION_INBOUND,
                    'message_kind' => Message::KIND_INBOUND_USER,
                    'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
                    'external_message_id' => '1003',
                    'text' => 'Прикрепила фото документа.',
                    'received_at' => Carbon::now()->subHours(3)->subMinutes(30),
                    'raw_payload' => [
                        'message' => [
                            'caption' => 'Прикрепила фото документа.',
                            'media' => [[
                                'type' => 'photo',
                                'file_id' => 'local-demo-photo-file-id',
                                'file_unique_id' => 'local-demo-photo-unique-id',
                                'download_status' => Message::MEDIA_DOWNLOAD_STATUS_PENDING,
                            ]],
                        ],
                    ],
                    'attachments' => [[
                        'provider_attachment_key' => 'photo:0',
                        'media_kind' => MessageAttachment::MEDIA_KIND_IMAGE,
                        'mime_type' => 'image/jpeg',
                        'extension' => 'jpg',
                        'original_filename' => 'document-photo.jpg',
                        'file_size_bytes' => 248000,
                        'provider_file_id' => 'local-demo-photo-file-id',
                        'provider_file_unique_id' => 'local-demo-photo-unique-id',
                        'download_status' => MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD,
                    ]],
                ],
            ],
        ]);

        $this->seedDialogScenario($channels['telegram_account'], [
            'external_user_id' => 'local-demo-tga-2001',
            'external_chat_id' => 'local-demo-account-chat-2001',
            'external_username' => 'ivan_media_demo',
            'display_name' => 'Иван Петров',
            'contact' => [
                'name' => 'Local Demo: Иван Петров',
                'first_name' => 'Иван',
                'last_name' => 'Петров',
                'gender' => 'male',
                'gender_source' => Contact::GENDER_SOURCE_OPERATOR,
                'age_range' => '24_29',
                'city' => 'Москва',
                'region' => 'Москва',
                'region_status' => Contact::REGION_STATUS_RESOLVED,
                'region_source' => Contact::REGION_SOURCE_MANUAL,
                'data_collection_status' => Contact::DATA_COLLECTION_STATUS_ACTIVE,
                'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_AGE_RANGE,
            ],
            'dialog' => [
                'stage' => Dialog::STAGE_PHONE_RECEIVED,
                'confirmed_phone_raw' => '+7 999 200-20-01',
                'confirmed_phone_normalized' => '79992002001',
                'phone_confirmed_at' => Carbon::now()->subHours(2),
                'phone_confirmed_via' => Dialog::PHONE_CONFIRMED_VIA_PHONE_CAPTURE,
                'fields_payload' => [
                    'local_recovery' => true,
                    'source' => 'telegram_account',
                ],
            ],
            'messages' => [
                [
                    'key' => 'local-demo:tga:2001:inbound:1',
                    'direction' => Message::DIRECTION_INBOUND,
                    'message_kind' => Message::KIND_INBOUND_USER,
                    'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
                    'external_message_id' => '2001',
                    'text' => 'Отправляю файл для проверки.',
                    'received_at' => Carbon::now()->subHours(2)->subMinutes(15),
                    'raw_payload' => ['account_message' => ['text' => 'Отправляю файл для проверки.']],
                ],
                [
                    'key' => 'local-demo:tga:2001:inbound:document',
                    'direction' => Message::DIRECTION_INBOUND,
                    'message_kind' => Message::KIND_INBOUND_USER,
                    'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
                    'external_message_id' => '2002',
                    'text' => 'Файл во вложении.',
                    'received_at' => Carbon::now()->subHours(2)->subMinutes(10),
                    'raw_payload' => [
                        'account_message' => [
                            'caption' => 'Файл во вложении.',
                            'document' => [
                                'file_id' => 'local-demo-document-file-id',
                                'file_unique_id' => 'local-demo-document-unique-id',
                                'file_name' => 'arrival-checklist.txt',
                            ],
                        ],
                    ],
                    'attachments' => [[
                        'provider_attachment_key' => 'document:0',
                        'media_kind' => MessageAttachment::MEDIA_KIND_DOCUMENT,
                        'mime_type' => 'text/plain',
                        'extension' => 'txt',
                        'original_filename' => 'arrival-checklist.txt',
                        'file_size_bytes' => 120,
                        'provider_file_id' => 'local-demo-document-file-id',
                        'provider_file_unique_id' => 'local-demo-document-unique-id',
                        'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED,
                        'local_path' => 'message-attachments/local-recovery/arrival-checklist.txt',
                    ]],
                ],
                [
                    'key' => 'local-demo:tga:2001:outbound:external',
                    'direction' => Message::DIRECTION_OUTBOUND,
                    'message_kind' => Message::KIND_OUTBOUND_EXTERNAL_ACCOUNT_MESSAGE,
                    'sent_by_type' => Message::SENT_BY_TYPE_SYSTEM,
                    'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_TELEGRAM_EXTERNAL_ACCOUNT,
                    'external_message_id' => '2003',
                    'text' => 'Спасибо, файл получили.',
                    'received_at' => Carbon::now()->subHours(2)->subMinutes(5),
                    'raw_payload' => ['gateway' => ['outgoing_message_id' => 'local-demo-outgoing-2003']],
                ],
            ],
        ]);

        $this->seedDialogScenario($channels['max_bot'], [
            'external_user_id' => 'local-demo-max-3001',
            'external_chat_id' => 'local-demo-max-chat-3001',
            'external_username' => 'max_demo_client',
            'display_name' => 'Мария Волкова',
            'contact' => [
                'name' => 'Local Demo: Мария Волкова',
                'first_name' => 'Мария',
                'last_name' => 'Волкова',
                'gender' => 'female',
                'gender_source' => Contact::GENDER_SOURCE_CLIENT,
                'city' => 'Санкт-Петербург',
                'region' => 'Санкт-Петербург',
                'region_status' => Contact::REGION_STATUS_RESOLVED,
                'region_source' => Contact::REGION_SOURCE_MANUAL,
                'data_collection_status' => Contact::DATA_COLLECTION_STATUS_ACTIVE,
            ],
            'dialog' => [
                'stage' => Dialog::STAGE_NEW_DIALOG,
                'fields_payload' => [
                    'local_recovery' => true,
                    'source' => 'max',
                ],
            ],
            'messages' => [
                [
                    'key' => 'local-demo:max:3001:inbound:1',
                    'direction' => Message::DIRECTION_INBOUND,
                    'message_kind' => Message::KIND_INBOUND_USER,
                    'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
                    'external_message_id' => '3001',
                    'text' => 'Добрый день, можно консультацию?',
                    'received_at' => Carbon::now()->subMinutes(45),
                    'raw_payload' => ['message_created' => ['text' => 'Добрый день, можно консультацию?']],
                ],
                [
                    'key' => 'local-demo:max:3001:inbound:video',
                    'direction' => Message::DIRECTION_INBOUND,
                    'message_kind' => Message::KIND_INBOUND_USER,
                    'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
                    'external_message_id' => '3002',
                    'text' => 'Видео не скачалось, нужна повторная попытка.',
                    'received_at' => Carbon::now()->subMinutes(40),
                    'raw_payload' => [
                        'message_created' => [
                            'caption' => 'Видео не скачалось, нужна повторная попытка.',
                            'media' => [[
                                'type' => 'video',
                                'file_id' => 'local-demo-video-file-id',
                                'download_status' => Message::MEDIA_DOWNLOAD_STATUS_FAILED,
                            ]],
                        ],
                    ],
                    'attachments' => [[
                        'provider_attachment_key' => 'video:0',
                        'media_kind' => MessageAttachment::MEDIA_KIND_VIDEO,
                        'mime_type' => 'video/mp4',
                        'extension' => 'mp4',
                        'original_filename' => 'intro-video.mp4',
                        'file_size_bytes' => 10485760,
                        'provider_file_id' => 'local-demo-video-file-id',
                        'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED,
                        'safe_error_code' => 'local_demo_download_failed',
                        'safe_error_message' => 'Synthetic failed download for local UI checks.',
                    ]],
                ],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function updateChannel(string $name, array $attributes): Channel
    {
        $channel = Channel::query()->firstOrNew(['name' => $name]);
        $channel->fill($attributes + ['name' => $name]);
        $channel->save();

        return $channel;
    }

    private function updateAutoReplyRule(Channel $channel, string $keyword, string $replyText): void
    {
        $rule = AutoReplyRule::query()->updateOrCreate(
            [
                'channel_id' => $channel->id,
                'normalized_keyword' => AutoReplyRule::normalizeKeyword($keyword),
            ],
            [
                'keyword' => $keyword,
                'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD,
                'contact_phone_condition' => null,
                'reply_text' => $replyText,
                'telegram_button_type' => null,
                'max_button_type' => null,
                'is_active' => true,
                'priority' => 10,
            ],
        );

        $rule->channels()->syncWithoutDetaching([(int) $channel->id]);
    }

    /**
     * @param  array{
     *     external_user_id:string,
     *     external_chat_id:string,
     *     external_username:string,
     *     display_name:string,
     *     contact:array<string, mixed>,
     *     dialog:array<string, mixed>,
     *     messages:list<array<string, mixed>>
     * }  $scenario
     */
    private function seedDialogScenario(Channel $channel, array $scenario): void
    {
        $contact = $this->updateContact($scenario['contact']);

        $identity = ContactIdentity::query()->updateOrCreate(
            [
                'channel_id' => $channel->id,
                'external_user_id' => $scenario['external_user_id'],
            ],
            [
                'contact_id' => $contact->id,
                'platform' => $channel->platform,
                'display_name' => $scenario['display_name'],
                'external_username' => $scenario['external_username'],
                'avatar_path' => null,
                'avatar_updated_at' => null,
            ],
        );

        $dialog = Dialog::query()->updateOrCreate(
            [
                'contact_id' => $contact->id,
                'channel_id' => $channel->id,
            ],
            $scenario['dialog'] + [
                'current_contact_identity_id' => $identity->id,
                'external_chat_id' => $scenario['external_chat_id'],
                'bitrix24_live_status' => Dialog::BITRIX24_LIVE_STATUS_NOT_LINKED,
            ],
        );

        $messages = collect($scenario['messages'])
            ->map(fn (array $message): Message => $this->updateMessage($channel, $contact, $identity, $dialog, $message))
            ->values();

        $lastMessage = $messages->sortByDesc(fn (Message $message): int => $message->received_at?->getTimestamp() ?? 0)->first();
        $lastInbound = $messages
            ->where('direction', Message::DIRECTION_INBOUND)
            ->sortByDesc(fn (Message $message): int => $message->received_at?->getTimestamp() ?? 0)
            ->first();
        $lastOutbound = $messages
            ->where('direction', Message::DIRECTION_OUTBOUND)
            ->sortByDesc(fn (Message $message): int => $message->received_at?->getTimestamp() ?? 0)
            ->first();

        $dialog->forceFill([
            'last_message_at' => $lastMessage?->received_at,
            'last_inbound_at' => $lastInbound?->received_at,
            'last_outbound_at' => $lastOutbound?->received_at,
            'last_message_id' => $lastMessage?->id,
            'last_inbound_message_id' => $lastInbound?->id,
            'last_outbound_message_id' => $lastOutbound?->id,
            'last_message_preview' => $lastMessage?->text,
            'last_inbound_message_preview' => $lastInbound?->text,
            'last_outbound_message_preview' => $lastOutbound?->text,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function updateContact(array $attributes): Contact
    {
        $contact = Contact::query()->firstOrNew(['name' => $attributes['name']]);
        $contact->fill($attributes + [
            'first_name_source' => Contact::FIRST_NAME_SOURCE_MANUAL,
            'first_name_resolution_method' => Contact::FIRST_NAME_RESOLUTION_METHOD_OPERATOR_MANUAL,
            'is_auto_reply_enabled' => true,
            'duplicate_review_status' => Contact::DUPLICATE_REVIEW_STATUS_NONE,
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_NOT_SYNCED,
            'bitrix24_deal_sync_status' => Contact::BITRIX24_DEAL_SYNC_STATUS_NOT_SYNCED,
            'bitrix24_history_sync_status' => Contact::BITRIX24_HISTORY_SYNC_STATUS_NOT_SYNCED,
        ]);
        $contact->save();

        return $contact;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function updateMessage(
        Channel $channel,
        Contact $contact,
        ContactIdentity $identity,
        Dialog $dialog,
        array $payload,
    ): Message {
        $attachments = $payload['attachments'] ?? [];
        $providerEventKey = (string) $payload['key'];
        $direction = (string) ($payload['direction'] ?? '');
        unset($payload['attachments']);
        unset($payload['key']);

        $message = Message::query()->firstOrNew([
            'channel_id' => $channel->id,
            'direction' => $direction,
            'provider_event_key' => $providerEventKey,
        ]);
        $message->fill([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => $direction,
            'provider_event_key' => $providerEventKey,
            'external_chat_id' => $dialog->external_chat_id,
            'text_format' => Message::TEXT_FORMAT_PLAIN_TEXT,
            'auto_reply_sent_at' => null,
        ] + $payload);
        $message->save();

        foreach ($attachments as $sortOrder => $attachment) {
            $this->updateAttachment($channel, $message, $providerEventKey, $attachment, $sortOrder);
        }

        return $message->fresh() ?? $message;
    }

    /**
     * @param  array<string, mixed>  $attachment
     */
    private function updateAttachment(
        Channel $channel,
        Message $message,
        string $providerEventKey,
        array $attachment,
        int $sortOrder,
    ): void {
        if (! class_exists(MessageAttachment::class) || ! Schema::hasTable('message_attachments')) {
            return;
        }

        if (($attachment['download_status'] ?? null) === MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED && isset($attachment['local_path'])) {
            Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->put(
                (string) $attachment['local_path'],
                "Local recovery sample file.\nGenerated for AB Connector local UI checks.\n",
            );
        }

        MessageAttachment::query()->updateOrCreate(
            [
                'provider' => 'local_recovery',
                'channel_id' => $channel->id,
                'provider_event_key' => $providerEventKey,
                'provider_attachment_key' => $attachment['provider_attachment_key'],
            ],
            [
                'message_id' => $message->id,
                'outbound_attachment_key' => null,
                'media_kind' => $attachment['media_kind'] ?? MessageAttachment::MEDIA_KIND_UNKNOWN,
                'mime_type' => $attachment['mime_type'] ?? null,
                'extension' => $attachment['extension'] ?? null,
                'original_filename' => $attachment['original_filename'] ?? null,
                'file_size_bytes' => $attachment['file_size_bytes'] ?? null,
                'provider_file_id' => $attachment['provider_file_id'] ?? null,
                'provider_file_unique_id' => $attachment['provider_file_unique_id'] ?? null,
                'provider_file_reference' => null,
                'provider_metadata' => ['local_recovery' => true],
                'download_status' => $attachment['download_status'] ?? MessageAttachment::DOWNLOAD_STATUS_METADATA_ONLY,
                'send_status' => MessageAttachment::SEND_STATUS_NOT_APPLICABLE,
                'local_disk' => isset($attachment['local_path']) ? MessageAttachment::LOCAL_DISK_PRIVATE : null,
                'local_path' => $attachment['local_path'] ?? null,
                'safe_error_code' => $attachment['safe_error_code'] ?? null,
                'safe_error_message' => $attachment['safe_error_message'] ?? null,
                'raw_payload_excerpt' => ['local_recovery' => true],
                'sort_order' => $sortOrder,
            ],
        );
    }
}
