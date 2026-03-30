<?php

namespace Tests\Feature;

use App\Jobs\ProcessPhoneCaptureFollowUpJob;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProcessPhoneCaptureFollowUpJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_sends_telegram_phone_capture_confirmation_and_removes_keyboard(): void
    {
        config()->set('bots.phone_capture_confirmation_text', 'Спасибо, номер получили.');
        config()->set('bots.data_collection.first_question', 'Как вас зовут?');

        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push([
                    'ok' => true,
                    'result' => [
                        'message_id' => 9911,
                    ],
                ])
                ->push([
                    'ok' => true,
                    'result' => [
                        'message_id' => 9912,
                    ],
                ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
            ],
        ]);

        $message = $this->createContactShareInboundMessage($channel, [
            'external_chat_id' => '300',
            'external_message_id' => 'phone-share-1',
            'provider_event_key' => 'phone-share-1',
        ]);

        ProcessPhoneCaptureFollowUpJob::dispatchSync($message->id);

        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === '300'
            && $request['text'] === 'Спасибо, номер получили.'
            && data_get($request->data(), 'reply_markup.remove_keyboard') === true);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === '300'
            && $request['text'] === 'Как вас зовут?');

        $message->refresh();
        $channel->refresh();

        $this->assertNotNull($message->auto_reply_sent_at);
        $this->assertNotNull($channel->last_reply_sent_at);
        $this->assertSame(Contact::DATA_COLLECTION_STATUS_ACTIVE, $message->contact->fresh()->data_collection_status);
        $this->assertSame(Contact::DATA_COLLECTION_FIELD_FIRST_NAME, $message->contact->fresh()->data_collection_current_field);
        $this->assertDatabaseHas('messages', [
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_PHONE_CAPTURE_CONFIRMATION,
            'reply_to_message_id' => $message->id,
            'external_message_id' => '9911',
            'text' => 'Спасибо, номер получили.',
        ]);
        $this->assertDatabaseHas('messages', [
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION,
            'reply_to_message_id' => $message->id,
            'external_message_id' => '9912',
            'text' => 'Как вас зовут?',
        ]);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.phone_capture_confirmed',
        ]);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.data_collection_started',
        ]);
    }

    public function test_job_sends_max_phone_capture_confirmation(): void
    {
        config()->set('bots.phone_capture_confirmation_text', 'Спасибо, номер получили.');
        config()->set('bots.data_collection.first_question', 'Как вас зовут?');

        Http::fake([
            'https://platform-api.max.ru/*' => Http::sequence()
                ->push([
                    'message' => [
                        'message_id' => 'max-confirm-1',
                    ],
                ])
                ->push([
                    'message' => [
                        'message_id' => 'max-question-1',
                    ],
                ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
            ],
        ]);

        $message = $this->createContactShareInboundMessage($channel, [
            'external_chat_id' => '700',
            'external_message_id' => 'max-phone-share-1',
            'provider_event_key' => 'max-phone-share-1',
        ], [
            'external_user_id' => '500',
        ]);

        ProcessPhoneCaptureFollowUpJob::dispatchSync($message->id);

        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://platform-api.max.ru/messages?chat_id=700'
            && $request['text'] === 'Спасибо, номер получили.'
            && data_get($request->data(), 'attachments') === null);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://platform-api.max.ru/messages?chat_id=700'
            && $request['text'] === 'Как вас зовут?');

        $this->assertDatabaseHas('messages', [
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_PHONE_CAPTURE_CONFIRMATION,
            'reply_to_message_id' => $message->id,
            'external_message_id' => 'max-confirm-1',
        ]);
        $this->assertDatabaseHas('messages', [
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION,
            'reply_to_message_id' => $message->id,
            'external_message_id' => 'max-question-1',
            'text' => 'Как вас зовут?',
        ]);
    }

    public function test_repeated_job_execution_for_same_contact_share_creates_one_confirmation(): void
    {
        config()->set('bots.data_collection.first_question', 'Как вас зовут?');

        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push([
                    'ok' => true,
                    'result' => [
                        'message_id' => 9912,
                    ],
                ])
                ->push([
                    'ok' => true,
                    'result' => [
                        'message_id' => 9913,
                    ],
                ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
            ],
        ]);

        $message = $this->createContactShareInboundMessage($channel, [
            'provider_event_key' => 'phone-share-repeat',
        ]);

        ProcessPhoneCaptureFollowUpJob::dispatchSync($message->id);
        ProcessPhoneCaptureFollowUpJob::dispatchSync($message->id);

        $this->assertDatabaseCount('messages', 3);
        $this->assertDatabaseHas('messages', [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_PHONE_CAPTURE_CONFIRMATION,
            'reply_to_message_id' => $message->id,
        ]);
        $this->assertDatabaseHas('messages', [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION,
            'reply_to_message_id' => $message->id,
        ]);
    }

    public function test_job_does_not_send_confirmation_for_non_contact_share_message(): void
    {
        Http::fake();

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
            'platform' => $channel->platform,
            'external_user_id' => '200',
        ]);

        $message = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
        ]);

        ProcessPhoneCaptureFollowUpJob::dispatchSync($message->id);

        Http::assertNothingSent();
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_job_starts_data_collection_from_country_when_first_name_is_already_filled(): void
    {
        config()->set('bots.phone_capture_confirmation_text', 'Спасибо, номер получили.');
        config()->set('bots.data_collection.country.question', 'В какой стране вы находитесь?');

        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push([
                    'ok' => true,
                    'result' => [
                        'message_id' => 9914,
                    ],
                ])
                ->push([
                    'ok' => true,
                    'result' => [
                        'message_id' => 9915,
                    ],
                ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
            ],
        ]);

        $contact = Contact::factory()->create([
            'first_name' => 'Герман',
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '200',
            'external_username' => 'telegram_user',
        ]);
        $message = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_CONTACT_SHARE,
            'external_chat_id' => '300',
            'external_message_id' => 'phone-share-has-name',
            'provider_event_key' => 'phone-share-has-name',
            'text' => null,
            'raw_payload' => ['message' => 'payload'],
            'received_at' => now(),
        ]);

        ProcessPhoneCaptureFollowUpJob::dispatchSync($message->id);

        Http::assertSentCount(2);
        $this->assertDatabaseHas('messages', [
            'message_kind' => Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION,
            'reply_to_message_id' => $message->id,
            'text' => 'В какой стране вы находитесь?',
        ]);
        $this->assertSame(Contact::DATA_COLLECTION_STATUS_ACTIVE, $contact->fresh()->data_collection_status);
        $this->assertSame(Contact::DATA_COLLECTION_FIELD_COUNTRY, $contact->fresh()->data_collection_current_field);
    }

    public function test_job_starts_data_collection_from_city_when_first_name_and_country_are_already_filled(): void
    {
        config()->set('bots.phone_capture_confirmation_text', 'Спасибо, номер получили.');
        config()->set('bots.data_collection.city.question', 'В каком городе вы находитесь?');

        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push([
                    'ok' => true,
                    'result' => [
                        'message_id' => 9916,
                    ],
                ])
                ->push([
                    'ok' => true,
                    'result' => [
                        'message_id' => 9917,
                    ],
                ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
            ],
        ]);

        $contact = Contact::factory()->create([
            'first_name' => 'Герман',
            'country' => 'Россия',
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '200',
            'external_username' => 'telegram_user',
        ]);
        $message = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_CONTACT_SHARE,
            'external_chat_id' => '300',
            'external_message_id' => 'phone-share-has-name-country',
            'provider_event_key' => 'phone-share-has-name-country',
            'text' => null,
            'raw_payload' => ['message' => 'payload'],
            'received_at' => now(),
        ]);

        ProcessPhoneCaptureFollowUpJob::dispatchSync($message->id);

        Http::assertSentCount(2);
        $this->assertDatabaseHas('messages', [
            'message_kind' => Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION,
            'reply_to_message_id' => $message->id,
            'text' => 'В каком городе вы находитесь?',
        ]);
        $this->assertSame(Contact::DATA_COLLECTION_STATUS_ACTIVE, $contact->fresh()->data_collection_status);
        $this->assertSame(Contact::DATA_COLLECTION_FIELD_CITY, $contact->fresh()->data_collection_current_field);
    }

    public function test_job_starts_data_collection_from_age_range_when_profile_is_filled_without_age_range(): void
    {
        config()->set('bots.phone_capture_confirmation_text', 'Спасибо, номер получили.');
        config()->set('bots.data_collection.age_range.question', "Укажите ваш возраст:\n1. Еще нет 18 лет\n2. 18 - 23 года\n3. 24 - 29 лет\n4. 30 - 39 лет\n5. Больше 40 лет");

        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push([
                    'ok' => true,
                    'result' => [
                        'message_id' => 9918,
                    ],
                ])
                ->push([
                    'ok' => true,
                    'result' => [
                        'message_id' => 9919,
                    ],
                ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
            ],
        ]);

        $contact = Contact::factory()->create([
            'first_name' => 'Герман',
            'country' => 'Россия',
            'city' => 'Москва',
            'age_range' => null,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '201',
            'external_username' => 'telegram_user_age',
        ]);
        $message = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_CONTACT_SHARE,
            'external_chat_id' => '301',
            'external_message_id' => 'phone-share-has-profile',
            'provider_event_key' => 'phone-share-has-profile',
            'text' => null,
            'raw_payload' => ['message' => 'payload'],
            'received_at' => now(),
        ]);

        ProcessPhoneCaptureFollowUpJob::dispatchSync($message->id);

        Http::assertSentCount(2);
        $this->assertDatabaseHas('messages', [
            'message_kind' => Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION,
            'reply_to_message_id' => $message->id,
            'text' => "Укажите ваш возраст:\n1. Еще нет 18 лет\n2. 18 - 23 года\n3. 24 - 29 лет\n4. 30 - 39 лет\n5. Больше 40 лет",
        ]);
        $this->assertSame(Contact::DATA_COLLECTION_STATUS_ACTIVE, $contact->fresh()->data_collection_status);
        $this->assertSame(Contact::DATA_COLLECTION_FIELD_AGE_RANGE, $contact->fresh()->data_collection_current_field);
    }

    /**
     * @param  array<string, mixed>  $messageOverrides
     * @param  array<string, mixed>  $identityOverrides
     */
    protected function createContactShareInboundMessage(
        Channel $channel,
        array $messageOverrides = [],
        array $identityOverrides = [],
    ): Message {
        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create(array_merge([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '200',
            'external_username' => 'telegram_user',
        ], $identityOverrides));

        return Message::factory()->create(array_merge([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_CONTACT_SHARE,
            'reply_to_message_id' => null,
            'provider_event_key' => 'provider-event-key',
            'external_chat_id' => $channel->platform === Channel::PLATFORM_MAX ? '700' : '300',
            'external_message_id' => '10',
            'text' => null,
            'raw_payload' => ['message' => 'payload'],
            'received_at' => now(),
            'auto_reply_sent_at' => null,
        ], $messageOverrides));
    }
}
