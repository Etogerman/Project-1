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

        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 9911,
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

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
                && $request['chat_id'] === '300'
                && $request['text'] === 'Спасибо, номер получили.'
                && data_get($request->data(), 'reply_markup.remove_keyboard') === true;
        });

        $message->refresh();
        $channel->refresh();

        $this->assertNotNull($message->auto_reply_sent_at);
        $this->assertNotNull($channel->last_reply_sent_at);
        $this->assertDatabaseHas('messages', [
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_PHONE_CAPTURE_CONFIRMATION,
            'reply_to_message_id' => $message->id,
            'external_message_id' => '9911',
            'text' => 'Спасибо, номер получили.',
        ]);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.phone_capture_confirmed',
        ]);
    }

    public function test_job_sends_max_phone_capture_confirmation(): void
    {
        config()->set('bots.phone_capture_confirmation_text', 'Спасибо, номер получили.');

        Http::fake([
            'https://platform-api.max.ru/*' => Http::response([
                'message' => [
                    'message_id' => 'max-confirm-1',
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

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://platform-api.max.ru/messages?chat_id=700'
                && $request['text'] === 'Спасибо, номер получили.'
                && data_get($request->data(), 'attachments') === null;
        });

        $this->assertDatabaseHas('messages', [
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_PHONE_CAPTURE_CONFIRMATION,
            'reply_to_message_id' => $message->id,
            'external_message_id' => 'max-confirm-1',
        ]);
    }

    public function test_repeated_job_execution_for_same_contact_share_creates_one_confirmation(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
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
            'provider_event_key' => 'phone-share-repeat',
        ]);

        ProcessPhoneCaptureFollowUpJob::dispatchSync($message->id);
        ProcessPhoneCaptureFollowUpJob::dispatchSync($message->id);

        $this->assertDatabaseCount('messages', 2);
        $this->assertDatabaseHas('messages', [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_PHONE_CAPTURE_CONFIRMATION,
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
