<?php

namespace Tests\Feature;

use App\Jobs\ProcessDataCollectionQuestionJob;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Dialog;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProcessDataCollectionQuestionJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_uses_current_dialog_route_source_for_stale_source_message(): void
    {
        config()->set('bots.data_collection.first_question', 'Как вас зовут?');

        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 9951,
                ],
            ]),
        ]);

        $channel = $this->createTelegramChannel();
        $contact = Contact::factory()->create([
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_ACTIVE,
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_FIRST_NAME,
            'data_collection_started_at' => now()->subHour(),
        ]);
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
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => '300',
            'external_message_id' => 'collector-question-stale',
            'provider_event_key' => 'collector-question-stale',
            'received_at' => now()->subMinutes(5),
        ]);

        ProcessDataCollectionQuestionJob::dispatchSync($message->id);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === '399'
            && $request['text'] === 'Как вас зовут?');

        $questionMessage = Message::query()
            ->where('reply_to_message_id', $message->id)
            ->where('message_kind', Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION)
            ->firstOrFail();

        $this->assertSame($dialog->id, $questionMessage->dialog_id);
        $this->assertSame($currentIdentity->id, $questionMessage->contact_identity_id);
        $this->assertSame('399', $questionMessage->external_chat_id);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.data_collection_question_sent',
        ]);
    }

    public function test_job_can_fallback_to_legacy_message_route_source_when_dialog_is_missing(): void
    {
        config()->set('bots.data_collection.first_question', 'Как вас зовут?');

        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 9952,
                ],
            ]),
        ]);

        $channel = $this->createTelegramChannel();
        $message = $this->createInboundUserMessage($channel, [
            'dialog_id' => null,
            'external_chat_id' => '301',
            'external_message_id' => 'collector-question-fallback',
            'provider_event_key' => 'collector-question-fallback',
        ]);

        ProcessDataCollectionQuestionJob::dispatchSync($message->id);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === '301'
            && $request['text'] === 'Как вас зовут?');

        $questionMessage = Message::query()
            ->where('reply_to_message_id', $message->id)
            ->where('message_kind', Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION)
            ->firstOrFail();

        $this->assertNotNull($questionMessage->dialog_id);
        $this->assertSame($message->contact_identity_id, $questionMessage->contact_identity_id);
        $this->assertSame('301', $questionMessage->external_chat_id);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.dialog_route_fallback_used',
        ]);
    }

    public function test_job_uses_max_user_route_when_dialog_has_cleared_stale_chat_id(): void
    {
        config()->set('bots.data_collection.first_question', 'Как вас зовут?');

        Http::fake([
            'https://platform-api.max.ru/*' => Http::response([
                'message' => [
                    'message_id' => 'max-question-user-route-fresh',
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
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_ACTIVE,
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_FIRST_NAME,
            'data_collection_started_at' => now()->subHour(),
        ]);
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
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => '700',
            'external_message_id' => 'max-collector-question-stale',
            'provider_event_key' => 'max-collector-question-stale',
            'received_at' => now()->subMinutes(5),
        ]);

        ProcessDataCollectionQuestionJob::dispatchSync($message->id);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://platform-api.max.ru/messages?')
            && str_contains($request->url(), 'user_id=228532008')
            && ! str_contains($request->url(), 'chat_id=')
            && $request['text'] === 'Как вас зовут?');

        $questionMessage = Message::query()
            ->where('reply_to_message_id', $message->id)
            ->where('message_kind', Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION)
            ->firstOrFail();

        $this->assertSame($dialog->id, $questionMessage->dialog_id);
        $this->assertSame($currentIdentity->id, $questionMessage->contact_identity_id);
        $this->assertSame('', $questionMessage->external_chat_id);
    }

    protected function createTelegramChannel(): Channel
    {
        return Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $messageOverrides
     */
    protected function createInboundUserMessage(Channel $channel, array $messageOverrides = []): Message
    {
        $contact = Contact::factory()->create([
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_ACTIVE,
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_FIRST_NAME,
            'data_collection_started_at' => now()->subHour(),
        ]);

        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '200',
        ]);

        return Message::factory()->create(array_merge([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => '300',
            'external_message_id' => 'collector-question-source',
            'provider_event_key' => 'collector-question-source',
            'text' => 'Привет',
            'received_at' => now()->subMinute(),
        ], $messageOverrides));
    }
}
