<?php

namespace Tests\Feature;

use App\Jobs\ProcessDataCollectionResponseJob;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProcessDataCollectionResponseJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_saves_first_name_sends_completion_and_completes_data_collection(): void
    {
        config()->set('bots.data_collection.completion_message', 'Спасибо, имя сохранили.');
        config()->set('bots.gemini.api_key', 'gemini-key');

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse([
                'decision' => 'accept',
                'first_name' => 'Герман',
            ])),
            'https://api.telegram.org/*' => Http::response([
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

        $message = $this->createInboundUserMessage($channel, [
            'text' => 'Герман',
        ]);

        ProcessDataCollectionResponseJob::dispatchSync($message->id);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === '300'
            && $request['text'] === 'Спасибо, имя сохранили.');

        $message->refresh();
        $contact = $message->contact()->firstOrFail();

        $this->assertSame('Герман', $contact->first_name);
        $this->assertSame(Contact::DATA_COLLECTION_STATUS_COMPLETED, $contact->data_collection_status);
        $this->assertNull($contact->data_collection_current_field);
        $this->assertNotNull($contact->data_collection_completed_at);
        $this->assertNotNull($message->auto_reply_sent_at);
        $this->assertDatabaseHas('messages', [
            'contact_id' => $contact->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_DATA_COLLECTION_COMPLETION,
            'reply_to_message_id' => $message->id,
            'external_message_id' => '9931',
            'text' => 'Спасибо, имя сохранили.',
        ]);
    }

    public function test_job_sends_retry_message_for_blank_answer_and_keeps_collection_active(): void
    {
        config()->set('bots.data_collection.first_name.retry_message', 'Подскажите, пожалуйста, как к вам обращаться? Можно только имя.');
        config()->set('bots.data_collection.first_name.max_attempts', 2);

        Http::fake([
            'https://platform-api.max.ru/*' => Http::response([
                'message' => [
                    'message_id' => 'max-repeat-question',
                ],
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
            ],
        ]);

        $message = $this->createInboundUserMessage($channel, [
            'external_chat_id' => '700',
            'text' => '   ',
        ], [
            'external_user_id' => '500',
        ]);

        ProcessDataCollectionResponseJob::dispatchSync($message->id);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://platform-api.max.ru/messages?chat_id=700'
            && $request['text'] === 'Подскажите, пожалуйста, как к вам обращаться? Можно только имя.');

        $message->refresh();
        $contact = $message->contact()->firstOrFail();

        $this->assertNull($contact->first_name);
        $this->assertSame(Contact::DATA_COLLECTION_STATUS_ACTIVE, $contact->data_collection_status);
        $this->assertSame(Contact::DATA_COLLECTION_FIELD_FIRST_NAME, $contact->data_collection_current_field);
        $this->assertSame(1, $contact->data_collection_attempts_count);
        $this->assertNotNull($message->auto_reply_sent_at);
        $this->assertDatabaseHas('messages', [
            'contact_id' => $contact->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION,
            'reply_to_message_id' => $message->id,
            'external_message_id' => 'max-repeat-question',
            'text' => 'Подскажите, пожалуйста, как к вам обращаться? Можно только имя.',
        ]);
    }

    public function test_job_retries_for_invalid_name_reply_and_keeps_collection_active(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');
        config()->set('bots.data_collection.first_name.retry_message', 'Подскажите, пожалуйста, как к вам обращаться? Можно только имя.');
        config()->set('bots.data_collection.first_name.max_attempts', 2);

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse([
                'decision' => 'retry',
                'first_name' => null,
            ])),
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 9932,
                ],
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
            ],
        ]);

        $message = $this->createInboundUserMessage($channel, [
            'text' => 'Не скажу как меня зовут',
        ]);

        ProcessDataCollectionResponseJob::dispatchSync($message->id);

        $contact = $message->contact()->firstOrFail()->fresh();

        $this->assertNull($contact->first_name);
        $this->assertSame(Contact::DATA_COLLECTION_STATUS_ACTIVE, $contact->data_collection_status);
        $this->assertSame(1, $contact->data_collection_attempts_count);
        $this->assertDatabaseHas('messages', [
            'contact_id' => $contact->id,
            'message_kind' => Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION,
            'reply_to_message_id' => $message->id,
            'text' => 'Подскажите, пожалуйста, как к вам обращаться? Можно только имя.',
        ]);
    }

    public function test_job_skips_after_second_invalid_attempt(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');
        config()->set('bots.data_collection.first_name.max_attempts', 2);
        config()->set('bots.data_collection.first_name.skip_message', 'Хорошо, имя пока пропустим.');

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse([
                'decision' => 'retry',
                'first_name' => null,
            ])),
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 9933,
                ],
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
            ],
        ]);

        $message = $this->createInboundUserMessage($channel, [
            'text' => 'Не скажу',
        ]);

        $message->contact()->firstOrFail()->forceFill([
            'data_collection_attempts_count' => 1,
        ])->save();

        ProcessDataCollectionResponseJob::dispatchSync($message->id);

        $contact = $message->contact()->firstOrFail()->fresh();

        $this->assertNull($contact->first_name);
        $this->assertSame(Contact::DATA_COLLECTION_STATUS_COMPLETED, $contact->data_collection_status);
        $this->assertNull($contact->data_collection_current_field);
        $this->assertSame(0, $contact->data_collection_attempts_count);
        $this->assertDatabaseHas('messages', [
            'contact_id' => $contact->id,
            'message_kind' => Message::KIND_OUTBOUND_DATA_COLLECTION_COMPLETION,
            'reply_to_message_id' => $message->id,
            'text' => 'Хорошо, имя пока пропустим.',
        ]);
    }

    public function test_job_handles_local_skip_without_calling_gemini(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');
        config()->set('bots.data_collection.first_name.skip_message', 'Хорошо, имя пока пропустим.');

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse([
                'decision' => 'accept',
                'first_name' => 'Не должно использоваться',
            ])),
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 9934,
                ],
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
            ],
        ]);

        $message = $this->createInboundUserMessage($channel, [
            'text' => 'пропустить',
        ]);

        ProcessDataCollectionResponseJob::dispatchSync($message->id);

        Http::assertNotSent(fn ($request): bool => str_starts_with($request->url(), 'https://generativelanguage.googleapis.com/'));

        $contact = $message->contact()->firstOrFail()->fresh();

        $this->assertNull($contact->first_name);
        $this->assertSame(Contact::DATA_COLLECTION_STATUS_COMPLETED, $contact->data_collection_status);
        $this->assertSame(0, $contact->data_collection_attempts_count);
    }

    public function test_job_sends_fallback_message_when_gemini_fails_and_keeps_attempts_unchanged(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');
        config()->set('bots.data_collection.first_name.fallback_error_message', 'Не смогли распознать имя. Напишите, пожалуйста, только имя.');

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'bad gateway']], 500),
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 9935,
                ],
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
            ],
        ]);

        $message = $this->createInboundUserMessage($channel, [
            'text' => 'Герман',
        ]);

        ProcessDataCollectionResponseJob::dispatchSync($message->id);

        $contact = $message->contact()->firstOrFail()->fresh();

        $this->assertNull($contact->first_name);
        $this->assertSame(Contact::DATA_COLLECTION_STATUS_ACTIVE, $contact->data_collection_status);
        $this->assertSame(0, $contact->data_collection_attempts_count);
        $this->assertDatabaseHas('messages', [
            'contact_id' => $contact->id,
            'message_kind' => Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION,
            'reply_to_message_id' => $message->id,
            'text' => 'Не смогли распознать имя. Напишите, пожалуйста, только имя.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $messageOverrides
     * @param  array<string, mixed>  $identityOverrides
     */
    protected function createInboundUserMessage(
        Channel $channel,
        array $messageOverrides = [],
        array $identityOverrides = [],
    ): Message {
        $contact = Contact::factory()->create([
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_ACTIVE,
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_FIRST_NAME,
            'data_collection_started_at' => now(),
        ]);

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
            'message_kind' => Message::KIND_INBOUND_USER,
            'provider_event_key' => 'collector-reply-key',
            'external_chat_id' => $channel->platform === Channel::PLATFORM_MAX ? '700' : '300',
            'external_message_id' => 'collector-reply-1',
            'text' => 'Герман',
            'raw_payload' => ['message' => 'payload'],
            'received_at' => now(),
        ], $messageOverrides));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function geminiResponse(array $payload): array
    {
        return [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            [
                                'text' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
