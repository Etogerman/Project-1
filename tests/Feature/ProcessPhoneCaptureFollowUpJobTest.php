<?php

namespace Tests\Feature;

use App\Jobs\ProcessDataCollectionQuestionJob;
use App\Jobs\ProcessPhoneCaptureFollowUpJob;
use App\Data\Bots\StoredInboundMessageResult;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Dialog;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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
        $confirmationMessage = Message::query()
            ->where('reply_to_message_id', $message->id)
            ->where('message_kind', Message::KIND_OUTBOUND_PHONE_CAPTURE_CONFIRMATION)
            ->firstOrFail();
        $questionMessage = Message::query()
            ->where('reply_to_message_id', $message->id)
            ->where('message_kind', Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION)
            ->firstOrFail();

        $this->assertNotNull($message->auto_reply_sent_at);
        $this->assertNotNull($channel->last_reply_sent_at);
        $this->assertSame(Contact::DATA_COLLECTION_STATUS_ACTIVE, $message->contact->fresh()->data_collection_status);
        $this->assertSame(Contact::DATA_COLLECTION_FIELD_FIRST_NAME, $message->contact->fresh()->data_collection_current_field);
        $this->assertNotNull($confirmationMessage->dialog_id);
        $this->assertSame($confirmationMessage->dialog_id, $questionMessage->dialog_id);
        $this->assertSame(Message::SENT_BY_TYPE_COLLECTOR, $confirmationMessage->sent_by_type);
        $this->assertSame(Message::SENT_BY_SYSTEM_CODE_PHONE_CAPTURE_CONFIRMATION, $confirmationMessage->sent_by_system_code);
        $this->assertSame(Message::SENT_BY_TYPE_COLLECTOR, $questionMessage->sent_by_type);
        $this->assertSame(Message::SENT_BY_SYSTEM_CODE_DATA_COLLECTION_QUESTION, $questionMessage->sent_by_system_code);
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
            'event' => 'contact.dialog_route_fallback_used',
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

    public function test_job_sends_max_phone_capture_confirmation_via_user_route_without_chat_id(): void
    {
        config()->set('bots.phone_capture_confirmation_text', 'Спасибо, номер получили.');
        config()->set('bots.data_collection.first_question', 'Как вас зовут?');

        Http::fake([
            'https://platform-api.max.ru/*' => Http::sequence()
                ->push([
                    'message' => [
                        'message_id' => 'max-confirm-user-route',
                    ],
                ])
                ->push([
                    'message' => [
                        'message_id' => 'max-question-user-route',
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
            'external_chat_id' => '',
            'external_message_id' => 'max-phone-share-user-route',
            'provider_event_key' => 'max-phone-share-user-route',
        ], [
            'external_user_id' => '228532008',
        ]);

        ProcessPhoneCaptureFollowUpJob::dispatchSync($message->id);

        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://platform-api.max.ru/messages?')
            && str_contains($request->url(), 'user_id=228532008')
            && $request['text'] === 'Спасибо, номер получили.');
        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://platform-api.max.ru/messages?')
            && str_contains($request->url(), 'user_id=228532008')
            && $request['text'] === 'Как вас зовут?');

        $confirmationMessage = Message::query()
            ->where('message_kind', Message::KIND_OUTBOUND_PHONE_CAPTURE_CONFIRMATION)
            ->where('reply_to_message_id', $message->id)
            ->firstOrFail();
        $questionMessage = Message::query()
            ->where('message_kind', Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION)
            ->where('reply_to_message_id', $message->id)
            ->firstOrFail();

        $this->assertSame('', $confirmationMessage->external_chat_id);
        $this->assertSame('', $questionMessage->external_chat_id);
    }

    public function test_job_uses_max_user_route_when_dialog_has_cleared_stale_chat_id(): void
    {
        config()->set('bots.phone_capture_confirmation_text', 'Спасибо, номер получили.');
        config()->set('bots.data_collection.first_question', 'Как вас зовут?');

        Http::fake([
            'https://platform-api.max.ru/*' => Http::sequence()
                ->push([
                    'message' => [
                        'message_id' => 'max-confirm-fresh-user-route',
                    ],
                ])
                ->push([
                    'message' => [
                        'message_id' => 'max-question-fresh-user-route',
                    ],
                ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
            ],
        ]);
        $contact = Contact::factory()->create();
        $legacyIdentity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'legacy-user',
        ]);
        $currentIdentity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '228532008',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $currentIdentity->id,
            'external_chat_id' => null,
            'last_message_at' => now(),
            'last_inbound_at' => now(),
        ]);
        $message = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $legacyIdentity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_CONTACT_SHARE,
            'external_chat_id' => '700',
            'external_message_id' => 'max-phone-share-stale-chat',
            'provider_event_key' => 'max-phone-share-stale-chat',
            'text' => null,
            'raw_payload' => ['message' => 'payload'],
            'received_at' => now()->subMinutes(5),
        ]);

        ProcessPhoneCaptureFollowUpJob::dispatchSync($message->id);

        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://platform-api.max.ru/messages?')
            && str_contains($request->url(), 'user_id=228532008')
            && ! str_contains($request->url(), 'chat_id=')
            && $request['text'] === 'Спасибо, номер получили.');
        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://platform-api.max.ru/messages?')
            && str_contains($request->url(), 'user_id=228532008')
            && ! str_contains($request->url(), 'chat_id=')
            && $request['text'] === 'Как вас зовут?');

        $confirmationMessage = Message::query()
            ->where('message_kind', Message::KIND_OUTBOUND_PHONE_CAPTURE_CONFIRMATION)
            ->where('reply_to_message_id', $message->id)
            ->firstOrFail();
        $questionMessage = Message::query()
            ->where('message_kind', Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION)
            ->where('reply_to_message_id', $message->id)
            ->firstOrFail();

        $this->assertSame($dialog->id, $confirmationMessage->dialog_id);
        $this->assertSame($currentIdentity->id, $confirmationMessage->contact_identity_id);
        $this->assertSame('', $confirmationMessage->external_chat_id);
        $this->assertSame($dialog->id, $questionMessage->dialog_id);
        $this->assertSame($currentIdentity->id, $questionMessage->contact_identity_id);
        $this->assertSame('', $questionMessage->external_chat_id);
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

    public function test_job_uses_current_dialog_route_source_for_confirmation_and_next_question(): void
    {
        config()->set('bots.phone_capture_confirmation_text', 'Спасибо, номер получили.');
        config()->set('bots.data_collection.first_question', 'Как вас зовут?');

        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push([
                    'ok' => true,
                    'result' => [
                        'message_id' => 9930,
                    ],
                ])
                ->push([
                    'ok' => true,
                    'result' => [
                        'message_id' => 9931,
                    ],
                ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
            ],
        ]);

        $contact = Contact::factory()->create();
        $legacyIdentity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'legacy-user',
        ]);
        $currentIdentity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'current-user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $currentIdentity->id,
            'external_chat_id' => '399',
            'last_message_at' => now(),
            'last_inbound_at' => now(),
        ]);
        $message = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $legacyIdentity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_CONTACT_SHARE,
            'external_chat_id' => '300',
            'external_message_id' => 'route-source-stale',
            'provider_event_key' => 'route-source-stale',
            'text' => null,
            'raw_payload' => ['message' => 'payload'],
            'received_at' => now()->subMinutes(5),
        ]);

        ProcessPhoneCaptureFollowUpJob::dispatchSync($message->id);

        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === '399'
            && $request['text'] === 'Спасибо, номер получили.');
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === '399'
            && $request['text'] === 'Как вас зовут?');

        $confirmationMessage = Message::query()
            ->where('reply_to_message_id', $message->id)
            ->where('message_kind', Message::KIND_OUTBOUND_PHONE_CAPTURE_CONFIRMATION)
            ->firstOrFail();
        $questionMessage = Message::query()
            ->where('reply_to_message_id', $message->id)
            ->where('message_kind', Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION)
            ->firstOrFail();

        $this->assertSame($dialog->id, $confirmationMessage->dialog_id);
        $this->assertSame($currentIdentity->id, $confirmationMessage->contact_identity_id);
        $this->assertSame('399', $confirmationMessage->external_chat_id);
        $this->assertSame($dialog->id, $questionMessage->dialog_id);
        $this->assertSame($currentIdentity->id, $questionMessage->contact_identity_id);
        $this->assertSame('399', $questionMessage->external_chat_id);
    }

    public function test_job_starts_data_collection_from_residence_city_when_first_name_is_already_filled(): void
    {
        config()->set('bots.phone_capture_confirmation_text', 'Спасибо, номер получили.');
        config()->set('bots.data_collection.residence_city.question', 'В каком городе вы живёте?');

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
            'text' => 'В каком городе вы живёте?',
        ]);
        $this->assertSame(Contact::DATA_COLLECTION_STATUS_ACTIVE, $contact->fresh()->data_collection_status);
        $this->assertSame(Contact::DATA_COLLECTION_FIELD_RESIDENCE_CITY, $contact->fresh()->data_collection_current_field);
    }

    public function test_job_starts_data_collection_from_first_name_when_contact_has_only_auto_first_name(): void
    {
        config()->set('bots.phone_capture_confirmation_text', 'Спасибо, номер получили.');
        config()->set('bots.data_collection.first_question', 'Как вас зовут?');

        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push([
                    'ok' => true,
                    'result' => [
                        'message_id' => 99141,
                    ],
                ])
                ->push([
                    'ok' => true,
                    'result' => [
                        'message_id' => 99142,
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
            'first_name_source' => Contact::FIRST_NAME_SOURCE_AUTO,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '202',
            'external_username' => 'telegram_user_auto_name',
        ]);
        $message = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_CONTACT_SHARE,
            'external_chat_id' => '302',
            'external_message_id' => 'phone-share-auto-name',
            'provider_event_key' => 'phone-share-auto-name',
            'text' => null,
            'raw_payload' => ['message' => 'payload'],
            'received_at' => now(),
        ]);

        ProcessPhoneCaptureFollowUpJob::dispatchSync($message->id);

        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === '302'
            && $request['text'] === 'Как вас зовут?');
        $this->assertDatabaseHas('messages', [
            'message_kind' => Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION,
            'reply_to_message_id' => $message->id,
            'text' => 'Как вас зовут?',
        ]);
        $this->assertSame(Contact::DATA_COLLECTION_STATUS_ACTIVE, $contact->fresh()->data_collection_status);
        $this->assertSame(Contact::DATA_COLLECTION_FIELD_FIRST_NAME, $contact->fresh()->data_collection_current_field);
    }

    public function test_job_starts_data_collection_from_city_when_first_name_and_country_are_already_filled(): void
    {
        config()->set('bots.phone_capture_confirmation_text', 'Спасибо, номер получили.');
        config()->set('bots.data_collection.city.question', 'В каком городе вы живёте?');

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
            'text' => 'В каком городе вы живёте?',
        ]);
        $this->assertSame(Contact::DATA_COLLECTION_STATUS_ACTIVE, $contact->fresh()->data_collection_status);
        $this->assertSame(Contact::DATA_COLLECTION_FIELD_CITY, $contact->fresh()->data_collection_current_field);
    }

    public function test_job_starts_data_collection_from_age_range_when_profile_is_filled_without_age_range(): void
    {
        config()->set('bots.phone_capture_confirmation_text', 'Спасибо, номер получили.');
        config()->set('bots.data_collection.age_range.telegram_question', 'Укажите ваш возраст:');

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
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === '301'
            && $request['text'] === 'Укажите ваш возраст:'
            && data_get($request->data(), 'reply_markup.inline_keyboard.0.0.text') === 'До 18 лет'
            && data_get($request->data(), 'reply_markup.inline_keyboard.0.1.text') === '18 - 23 года'
            && data_get($request->data(), 'reply_markup.inline_keyboard.1.0.text') === '24 - 29 лет'
            && data_get($request->data(), 'reply_markup.inline_keyboard.1.1.text') === '30 - 39 лет'
            && data_get($request->data(), 'reply_markup.inline_keyboard.2.0.text') === 'Больше 40 лет'
            && data_get($request->data(), 'reply_markup.inline_keyboard.2.1') === null);
        $this->assertDatabaseHas('messages', [
            'message_kind' => Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION,
            'reply_to_message_id' => $message->id,
            'text' => 'Укажите ваш возраст:',
        ]);
        $this->assertSame(Contact::DATA_COLLECTION_STATUS_ACTIVE, $contact->fresh()->data_collection_status);
        $this->assertSame(Contact::DATA_COLLECTION_FIELD_AGE_RANGE, $contact->fresh()->data_collection_current_field);
    }

    public function test_job_starts_max_data_collection_from_age_range_with_short_text_and_inline_buttons(): void
    {
        config()->set('bots.phone_capture_confirmation_text', 'Спасибо, номер получили.');
        config()->set('bots.data_collection.age_range.max_question', 'Укажите ваш возраст:');

        Http::fake([
            'https://platform-api.max.ru/*' => Http::sequence()
                ->push([
                    'message' => [
                        'message_id' => 'max-confirm-age',
                    ],
                ])
                ->push([
                    'message' => [
                        'message_id' => 'max-question-age',
                    ],
                ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
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
            'external_user_id' => '500',
            'external_username' => 'max_user_age',
        ]);
        $message = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_CONTACT_SHARE,
            'external_chat_id' => '700',
            'external_message_id' => 'max-phone-share-age',
            'provider_event_key' => 'max-phone-share-age',
            'text' => null,
            'raw_payload' => ['message' => 'payload'],
            'received_at' => now(),
        ]);

        ProcessPhoneCaptureFollowUpJob::dispatchSync($message->id);

        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://platform-api.max.ru/messages?chat_id=700'
            && $request['text'] === 'Укажите ваш возраст:'
            && data_get($request->data(), 'attachments.0.type') === 'inline_keyboard'
            && data_get($request->data(), 'attachments.0.payload.buttons.0.0.type') === 'message'
            && data_get($request->data(), 'attachments.0.payload.buttons.0.0.text') === 'До 18 лет'
            && data_get($request->data(), 'attachments.0.payload.buttons.0.1.text') === '18 - 23 года'
            && data_get($request->data(), 'attachments.0.payload.buttons.1.0.text') === '24 - 29 лет'
            && data_get($request->data(), 'attachments.0.payload.buttons.1.1.text') === '30 - 39 лет'
            && data_get($request->data(), 'attachments.0.payload.buttons.2.0.text') === 'Больше 40 лет'
            && data_get($request->data(), 'attachments.0.payload.buttons.2.1') === null);
        $this->assertDatabaseHas('messages', [
            'message_kind' => Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION,
            'reply_to_message_id' => $message->id,
            'external_message_id' => 'max-question-age',
            'text' => 'Укажите ваш возраст:',
        ]);
        $this->assertSame(Contact::DATA_COLLECTION_STATUS_ACTIVE, $contact->fresh()->data_collection_status);
        $this->assertSame(Contact::DATA_COLLECTION_FIELD_AGE_RANGE, $contact->fresh()->data_collection_current_field);
    }

    public function test_job_sends_recognition_text_after_merge_when_profile_is_full(): void
    {
        config()->set('bots.phone_capture_recognition_full_profile_text', 'Спасибо! Мы вас узнали, {name}.');

        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push([
                    'ok' => true,
                    'result' => [
                        'message_id' => 9920,
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
            'age_range' => '30_39',
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_COMPLETED,
            'data_collection_current_field' => null,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '205',
            'external_username' => 'telegram_merge_full',
        ]);
        $message = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_CONTACT_SHARE,
            'external_chat_id' => '305',
            'external_message_id' => 'merge-full',
            'provider_event_key' => 'merge-full',
            'text' => null,
            'raw_payload' => ['message' => 'payload'],
            'received_at' => now(),
        ]);

        ProcessPhoneCaptureFollowUpJob::dispatchSync($message->id, StoredInboundMessageResult::PHONE_CAPTURE_STATUS_MERGED_TO_ROOT);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === '305'
            && $request['text'] === 'Спасибо! Мы вас узнали, Герман.'
            && data_get($request->data(), 'reply_markup.remove_keyboard') === true);

        $this->assertDatabaseHas('messages', [
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_PHONE_CAPTURE_CONFIRMATION,
            'reply_to_message_id' => $message->id,
            'external_message_id' => '9920',
            'text' => 'Спасибо! Мы вас узнали, Герман.',
        ]);
        $this->assertDatabaseMissing('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.data_collection_continued_after_merge',
        ]);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.phone_capture_recognition_sent',
        ]);
    }

    public function test_job_continues_data_collection_in_current_chat_after_merge(): void
    {
        config()->set('bots.phone_capture_recognition_continue_text', 'Спасибо! Мы вас узнали, {name}. У нас осталось несколько вопросов.');
        config()->set('bots.data_collection.age_range.telegram_question', 'Укажите ваш возраст:');

        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
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
            ],
        ]);

        $contact = Contact::factory()->create([
            'first_name' => 'Герман',
            'country' => 'Россия',
            'city' => 'Москва',
            'age_range' => null,
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_ACTIVE,
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_AGE_RANGE,
            'data_collection_started_at' => now()->subDay(),
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '206',
            'external_username' => 'telegram_merge_continue',
        ]);
        $routeIdentity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '207',
            'external_username' => 'telegram_merge_current',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $routeIdentity->id,
            'external_chat_id' => '406',
            'last_message_at' => now(),
            'last_inbound_at' => now(),
        ]);
        $message = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_CONTACT_SHARE,
            'external_chat_id' => '306',
            'external_message_id' => 'merge-continue',
            'provider_event_key' => 'merge-continue',
            'text' => null,
            'raw_payload' => ['message' => 'payload'],
            'received_at' => now(),
        ]);

        ProcessPhoneCaptureFollowUpJob::dispatchSync($message->id, StoredInboundMessageResult::PHONE_CAPTURE_STATUS_MERGED_TO_ROOT);

        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === '406'
            && $request['text'] === 'Спасибо! Мы вас узнали, Герман. У нас осталось несколько вопросов.'
            && data_get($request->data(), 'reply_markup.remove_keyboard') === true);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === '406'
            && $request['text'] === 'Укажите ваш возраст:');

        $this->assertSame(Contact::DATA_COLLECTION_STATUS_ACTIVE, $contact->fresh()->data_collection_status);
        $this->assertSame(Contact::DATA_COLLECTION_FIELD_AGE_RANGE, $contact->fresh()->data_collection_current_field);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.phone_capture_recognition_sent',
        ]);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.data_collection_continued_after_merge',
        ]);
        $this->assertDatabaseHas('messages', [
            'message_kind' => Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION,
            'reply_to_message_id' => $message->id,
            'external_message_id' => '9922',
            'dialog_id' => $dialog->id,
            'contact_identity_id' => $routeIdentity->id,
            'external_chat_id' => '406',
            'text' => 'Укажите ваш возраст:',
        ]);
    }

    public function test_job_starts_collector_even_when_confirmation_is_skipped_for_blocked_dialog(): void
    {
        Queue::fake([ProcessDataCollectionQuestionJob::class]);
        Http::fake();

        config()->set('bots.phone_capture_confirmation_text', 'Спасибо, номер получили.');
        config()->set('bots.data_collection.first_question', 'Как вас зовут?');

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
            ],
        ]);

        $message = $this->createContactShareInboundMessage($channel, [
            'external_chat_id' => 'blocked-phone-share-chat',
            'external_message_id' => 'blocked-phone-share',
            'provider_event_key' => 'blocked-phone-share',
        ]);

        $dialog = Dialog::factory()->create([
            'contact_id' => $message->contact_id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $message->contact_identity_id,
            'external_chat_id' => 'blocked-phone-share-chat',
            'bot_subscription_status' => Dialog::BOT_SUBSCRIPTION_STATUS_BLOCKED_BY_USER,
            'bot_subscription_changed_at' => now(),
        ]);

        $message->forceFill([
            'dialog_id' => $dialog->id,
        ])->save();

        ProcessPhoneCaptureFollowUpJob::dispatchSync($message->id);

        Http::assertNothingSent();
        Queue::assertPushed(ProcessDataCollectionQuestionJob::class, function (ProcessDataCollectionQuestionJob $job) use ($message): bool {
            return $job->sourceMessageId === $message->id
                && $job->contactId === $message->contact_id
                && $job->expectedField === Contact::DATA_COLLECTION_FIELD_FIRST_NAME
                && $job->forceSend === false;
        });

        $contact = $message->contact()->firstOrFail()->fresh();

        $this->assertSame(Contact::DATA_COLLECTION_STATUS_ACTIVE, $contact->data_collection_status);
        $this->assertSame(Contact::DATA_COLLECTION_FIELD_FIRST_NAME, $contact->data_collection_current_field);
        $this->assertDatabaseMissing('messages', [
            'reply_to_message_id' => $message->id,
            'message_kind' => Message::KIND_OUTBOUND_PHONE_CAPTURE_CONFIRMATION,
        ]);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.phone_capture_confirmation_skipped_dialog_not_sendable',
        ]);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.data_collection_started',
        ]);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.phone_capture_follow_up_continued_after_skipped_confirmation',
        ]);
    }

    public function test_job_continues_active_collector_after_merge_even_when_confirmation_is_skipped_for_blocked_dialog(): void
    {
        Queue::fake([ProcessDataCollectionQuestionJob::class]);
        Http::fake();

        config()->set('bots.phone_capture_recognition_continue_text', 'Спасибо! Мы вас узнали, {name}. У нас осталось несколько вопросов.');

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
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_ACTIVE,
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_AGE_RANGE,
            'data_collection_started_at' => now()->subDay(),
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '226',
            'external_username' => 'telegram_merge_blocked_old',
        ]);
        $routeIdentity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '227',
            'external_username' => 'telegram_merge_blocked_current',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $routeIdentity->id,
            'external_chat_id' => 'blocked-merge-chat',
            'bot_subscription_status' => Dialog::BOT_SUBSCRIPTION_STATUS_BLOCKED_BY_USER,
            'bot_subscription_changed_at' => now(),
        ]);
        $message = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_CONTACT_SHARE,
            'external_chat_id' => 'legacy-merge-chat',
            'external_message_id' => 'merge-continue-blocked',
            'provider_event_key' => 'merge-continue-blocked',
            'text' => null,
            'raw_payload' => ['message' => 'payload'],
            'received_at' => now(),
        ]);

        ProcessPhoneCaptureFollowUpJob::dispatchSync($message->id, StoredInboundMessageResult::PHONE_CAPTURE_STATUS_MERGED_TO_ROOT);

        Http::assertNothingSent();
        Queue::assertPushed(ProcessDataCollectionQuestionJob::class, function (ProcessDataCollectionQuestionJob $job) use ($message, $contact): bool {
            return $job->sourceMessageId === $message->id
                && $job->contactId === $contact->id
                && $job->expectedField === Contact::DATA_COLLECTION_FIELD_AGE_RANGE
                && $job->forceSend === false;
        });

        $this->assertSame(Contact::DATA_COLLECTION_STATUS_ACTIVE, $contact->fresh()->data_collection_status);
        $this->assertSame(Contact::DATA_COLLECTION_FIELD_AGE_RANGE, $contact->fresh()->data_collection_current_field);
        $this->assertDatabaseMissing('messages', [
            'reply_to_message_id' => $message->id,
            'message_kind' => Message::KIND_OUTBOUND_PHONE_CAPTURE_CONFIRMATION,
        ]);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.phone_capture_confirmation_skipped_dialog_not_sendable',
        ]);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.data_collection_continued_after_merge',
        ]);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.phone_capture_follow_up_continued_after_skipped_confirmation',
        ]);
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
