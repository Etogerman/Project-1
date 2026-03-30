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

    public function test_job_saves_first_name_and_asks_country(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');
        config()->set('bots.data_collection.country.question', 'В какой стране вы находитесь?');

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse([
                'decision' => 'accept',
                'first_name' => 'Герман',
            ])),
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 9931],
            ]),
        ]);

        $channel = $this->createTelegramChannel();
        $message = $this->createInboundUserMessage($channel, [
            'text' => 'Меня зовут Герман',
        ]);

        ProcessDataCollectionResponseJob::dispatchSync($message->id);

        $contact = $message->contact()->firstOrFail()->fresh();

        $this->assertSame('Герман', $contact->first_name);
        $this->assertSame(Contact::DATA_COLLECTION_STATUS_ACTIVE, $contact->data_collection_status);
        $this->assertSame(Contact::DATA_COLLECTION_FIELD_COUNTRY, $contact->data_collection_current_field);
        $this->assertSame(0, $contact->data_collection_attempts_count);
        $this->assertDatabaseHas('messages', [
            'contact_id' => $contact->id,
            'message_kind' => Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION,
            'reply_to_message_id' => $message->id,
            'text' => 'В какой стране вы находитесь?',
        ]);
    }

    public function test_job_sends_retry_message_for_blank_first_name_answer_and_keeps_first_name_step_active(): void
    {
        config()->set('bots.data_collection.first_name.retry_message', 'Подскажите, пожалуйста, как к вам обращаться? Можно только имя.');
        config()->set('bots.data_collection.first_name.max_attempts', 2);

        Http::fake([
            'https://platform-api.max.ru/*' => Http::response([
                'message' => ['message_id' => 'max-repeat-question'],
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => ['token' => 'max-token'],
        ]);

        $message = $this->createInboundUserMessage($channel, [
            'external_chat_id' => '700',
            'text' => '   ',
        ], [
            'external_user_id' => '500',
        ]);

        ProcessDataCollectionResponseJob::dispatchSync($message->id);

        $contact = $message->contact()->firstOrFail()->fresh();

        $this->assertNull($contact->first_name);
        $this->assertSame(Contact::DATA_COLLECTION_STATUS_ACTIVE, $contact->data_collection_status);
        $this->assertSame(Contact::DATA_COLLECTION_FIELD_FIRST_NAME, $contact->data_collection_current_field);
        $this->assertSame(1, $contact->data_collection_attempts_count);
        $this->assertDatabaseHas('messages', [
            'contact_id' => $contact->id,
            'message_kind' => Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION,
            'reply_to_message_id' => $message->id,
            'text' => 'Подскажите, пожалуйста, как к вам обращаться? Можно только имя.',
        ]);
    }

    public function test_job_moves_to_country_after_second_invalid_first_name_attempt(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');
        config()->set('bots.data_collection.first_name.max_attempts', 2);
        config()->set('bots.data_collection.country.question', 'В какой стране вы находитесь?');

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse([
                'decision' => 'retry',
                'first_name' => null,
            ])),
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 9932],
            ]),
        ]);

        $channel = $this->createTelegramChannel();
        $message = $this->createInboundUserMessage($channel, [
            'text' => 'Не скажу',
        ], [], [
            'data_collection_attempts_count' => 1,
        ]);

        ProcessDataCollectionResponseJob::dispatchSync($message->id);

        $contact = $message->contact()->firstOrFail()->fresh();

        $this->assertNull($contact->first_name);
        $this->assertSame(Contact::DATA_COLLECTION_STATUS_ACTIVE, $contact->data_collection_status);
        $this->assertSame(Contact::DATA_COLLECTION_FIELD_COUNTRY, $contact->data_collection_current_field);
        $this->assertSame(0, $contact->data_collection_attempts_count);
        $this->assertDatabaseHas('messages', [
            'contact_id' => $contact->id,
            'message_kind' => Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION,
            'reply_to_message_id' => $message->id,
            'text' => 'В какой стране вы находитесь?',
        ]);
    }

    public function test_job_moves_to_country_after_first_name_skip_without_calling_gemini(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');
        config()->set('bots.data_collection.country.question', 'В какой стране вы находитесь?');

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse([
                'decision' => 'accept',
                'first_name' => 'Не должно использоваться',
            ])),
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 9933],
            ]),
        ]);

        $channel = $this->createTelegramChannel();
        $message = $this->createInboundUserMessage($channel, [
            'text' => 'пропустить',
        ]);

        ProcessDataCollectionResponseJob::dispatchSync($message->id);

        Http::assertNotSent(fn ($request): bool => str_starts_with($request->url(), 'https://generativelanguage.googleapis.com/'));

        $contact = $message->contact()->firstOrFail()->fresh();

        $this->assertNull($contact->first_name);
        $this->assertSame(Contact::DATA_COLLECTION_STATUS_ACTIVE, $contact->data_collection_status);
        $this->assertSame(Contact::DATA_COLLECTION_FIELD_COUNTRY, $contact->data_collection_current_field);
        $this->assertSame(0, $contact->data_collection_attempts_count);
    }

    public function test_job_saves_country_and_asks_city_instead_of_completing(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');
        config()->set('bots.data_collection.city.question', 'В каком городе вы находитесь?');

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse([
                'decision' => 'accept',
                'country' => 'Россия',
            ])),
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 9934],
            ]),
        ]);

        $channel = $this->createTelegramChannel();
        $message = $this->createInboundUserMessage($channel, [
            'text' => 'Я из России',
        ], [], [
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_COUNTRY,
            'first_name' => 'Герман',
        ]);

        ProcessDataCollectionResponseJob::dispatchSync($message->id);

        $contact = $message->contact()->firstOrFail()->fresh();

        $this->assertSame('Россия', $contact->country);
        $this->assertSame(Contact::DATA_COLLECTION_STATUS_ACTIVE, $contact->data_collection_status);
        $this->assertSame(Contact::DATA_COLLECTION_FIELD_CITY, $contact->data_collection_current_field);
        $this->assertSame(0, $contact->data_collection_attempts_count);
        $this->assertDatabaseHas('messages', [
            'contact_id' => $contact->id,
            'message_kind' => Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION,
            'reply_to_message_id' => $message->id,
            'text' => 'В каком городе вы находитесь?',
        ]);
    }

    public function test_job_accepts_exact_country_name_without_calling_gemini(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');
        config()->set('bots.data_collection.city.question', 'В каком городе вы находитесь?');

        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 9943],
            ]),
        ]);

        $channel = $this->createTelegramChannel();
        $message = $this->createInboundUserMessage($channel, [
            'text' => 'Мозамбик',
        ], [], [
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_COUNTRY,
            'first_name' => 'Герман',
        ]);

        ProcessDataCollectionResponseJob::dispatchSync($message->id);

        Http::assertNotSent(fn ($request): bool => str_starts_with($request->url(), 'https://generativelanguage.googleapis.com/'));

        $contact = $message->contact()->firstOrFail()->fresh();

        $this->assertSame('Мозамбик', $contact->country);
        $this->assertSame(Contact::DATA_COLLECTION_STATUS_ACTIVE, $contact->data_collection_status);
        $this->assertSame(Contact::DATA_COLLECTION_FIELD_CITY, $contact->data_collection_current_field);
        $this->assertSame(0, $contact->data_collection_attempts_count);
        $this->assertDatabaseHas('messages', [
            'contact_id' => $contact->id,
            'message_kind' => Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION,
            'reply_to_message_id' => $message->id,
            'text' => 'В каком городе вы находитесь?',
        ]);
    }

    public function test_job_moves_to_city_after_second_invalid_country_attempt(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');
        config()->set('bots.data_collection.country.max_attempts', 2);
        config()->set('bots.data_collection.city.question', 'В каком городе вы находитесь?');

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse([
                'decision' => 'retry',
                'country' => null,
            ])),
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 9935],
            ]),
        ]);

        $channel = $this->createTelegramChannel();
        $message = $this->createInboundUserMessage($channel, [
            'text' => 'Не скажу',
        ], [], [
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_COUNTRY,
            'data_collection_attempts_count' => 1,
            'first_name' => 'Герман',
        ]);

        ProcessDataCollectionResponseJob::dispatchSync($message->id);

        $contact = $message->contact()->firstOrFail()->fresh();

        $this->assertNull($contact->country);
        $this->assertSame(Contact::DATA_COLLECTION_STATUS_ACTIVE, $contact->data_collection_status);
        $this->assertSame(Contact::DATA_COLLECTION_FIELD_CITY, $contact->data_collection_current_field);
        $this->assertSame(0, $contact->data_collection_attempts_count);
        $this->assertDatabaseHas('messages', [
            'contact_id' => $contact->id,
            'message_kind' => Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION,
            'reply_to_message_id' => $message->id,
            'text' => 'В каком городе вы находитесь?',
        ]);
    }

    public function test_job_moves_to_city_after_country_skip_without_calling_gemini(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');
        config()->set('bots.data_collection.city.question', 'В каком городе вы находитесь?');

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse([
                'decision' => 'accept',
                'country' => 'Не должно использоваться',
            ])),
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 9936],
            ]),
        ]);

        $channel = $this->createTelegramChannel();
        $message = $this->createInboundUserMessage($channel, [
            'text' => 'пропустить',
        ], [], [
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_COUNTRY,
            'first_name' => 'Герман',
        ]);

        ProcessDataCollectionResponseJob::dispatchSync($message->id);

        Http::assertNotSent(fn ($request): bool => str_starts_with($request->url(), 'https://generativelanguage.googleapis.com/'));

        $contact = $message->contact()->firstOrFail()->fresh();

        $this->assertNull($contact->country);
        $this->assertSame(Contact::DATA_COLLECTION_STATUS_ACTIVE, $contact->data_collection_status);
        $this->assertSame(Contact::DATA_COLLECTION_FIELD_CITY, $contact->data_collection_current_field);
        $this->assertSame(0, $contact->data_collection_attempts_count);
    }

    public function test_job_saves_city_sends_completion_and_completes_data_collection(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');
        config()->set('bots.data_collection.completion_message', 'Спасибо, данные сохранили.');

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse([
                'decision' => 'accept',
                'city' => 'Москва',
            ])),
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 9937],
            ]),
        ]);

        $channel = $this->createTelegramChannel();
        $message = $this->createInboundUserMessage($channel, [
            'text' => 'Москва',
        ], [], [
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_CITY,
            'first_name' => 'Герман',
            'country' => 'Россия',
        ]);

        ProcessDataCollectionResponseJob::dispatchSync($message->id);

        $contact = $message->contact()->firstOrFail()->fresh();

        $this->assertSame('Москва', $contact->city);
        $this->assertSame(Contact::DATA_COLLECTION_STATUS_COMPLETED, $contact->data_collection_status);
        $this->assertNull($contact->data_collection_current_field);
        $this->assertDatabaseHas('messages', [
            'contact_id' => $contact->id,
            'message_kind' => Message::KIND_OUTBOUND_DATA_COLLECTION_COMPLETION,
            'reply_to_message_id' => $message->id,
            'text' => 'Спасибо, данные сохранили.',
        ]);
    }

    public function test_job_retries_for_invalid_city_and_keeps_city_step_active(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');
        config()->set('bots.data_collection.city.retry_message', 'Подскажите, пожалуйста, город. Например: Москва, Алматы, Берлин.');

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse([
                'decision' => 'retry',
                'city' => null,
            ])),
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 9938],
            ]),
        ]);

        $channel = $this->createTelegramChannel();
        $message = $this->createInboundUserMessage($channel, [
            'text' => 'Не скажу',
        ], [], [
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_CITY,
            'first_name' => 'Герман',
            'country' => 'Россия',
        ]);

        ProcessDataCollectionResponseJob::dispatchSync($message->id);

        $contact = $message->contact()->firstOrFail()->fresh();

        $this->assertNull($contact->city);
        $this->assertSame(Contact::DATA_COLLECTION_STATUS_ACTIVE, $contact->data_collection_status);
        $this->assertSame(Contact::DATA_COLLECTION_FIELD_CITY, $contact->data_collection_current_field);
        $this->assertSame(1, $contact->data_collection_attempts_count);
        $this->assertDatabaseHas('messages', [
            'contact_id' => $contact->id,
            'message_kind' => Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION,
            'reply_to_message_id' => $message->id,
            'text' => 'Подскажите, пожалуйста, город. Например: Москва, Алматы, Берлин.',
        ]);
    }

    public function test_job_retries_for_city_that_does_not_match_country_and_passes_country_to_extractor(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');
        config()->set('bots.data_collection.city.retry_message', 'Подскажите, пожалуйста, город. Например: Москва, Алматы, Берлин.');

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse([
                'decision' => 'retry',
                'city' => null,
            ])),
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 9941],
            ]),
        ]);

        $channel = $this->createTelegramChannel();
        $message = $this->createInboundUserMessage($channel, [
            'text' => 'Берлин',
        ], [], [
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_CITY,
            'first_name' => 'Герман',
            'country' => 'Россия',
        ]);

        ProcessDataCollectionResponseJob::dispatchSync($message->id);

        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://generativelanguage.googleapis.com/')
            && str_contains((string) data_get($request->data(), 'systemInstruction.parts.0.text'), 'Контакт уже указал страну: Россия'));

        $contact = $message->contact()->firstOrFail()->fresh();

        $this->assertNull($contact->city);
        $this->assertSame(Contact::DATA_COLLECTION_STATUS_ACTIVE, $contact->data_collection_status);
        $this->assertSame(Contact::DATA_COLLECTION_FIELD_CITY, $contact->data_collection_current_field);
        $this->assertSame(1, $contact->data_collection_attempts_count);
    }

    public function test_job_handles_city_skip_without_calling_gemini_and_completes(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');
        config()->set('bots.data_collection.city.skip_message', 'Хорошо, город пока пропустим.');

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse([
                'decision' => 'accept',
                'city' => 'Не должно использоваться',
            ])),
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 9939],
            ]),
        ]);

        $channel = $this->createTelegramChannel();
        $message = $this->createInboundUserMessage($channel, [
            'text' => 'пропустить',
        ], [], [
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_CITY,
            'first_name' => 'Герман',
            'country' => 'Россия',
        ]);

        ProcessDataCollectionResponseJob::dispatchSync($message->id);

        Http::assertNotSent(fn ($request): bool => str_starts_with($request->url(), 'https://generativelanguage.googleapis.com/'));

        $contact = $message->contact()->firstOrFail()->fresh();

        $this->assertNull($contact->city);
        $this->assertSame(Contact::DATA_COLLECTION_STATUS_COMPLETED, $contact->data_collection_status);
        $this->assertSame(0, $contact->data_collection_attempts_count);
        $this->assertDatabaseHas('messages', [
            'contact_id' => $contact->id,
            'message_kind' => Message::KIND_OUTBOUND_DATA_COLLECTION_COMPLETION,
            'reply_to_message_id' => $message->id,
            'text' => 'Хорошо, город пока пропустим.',
        ]);
    }

    public function test_job_sends_fallback_message_when_city_extraction_fails_and_keeps_attempts_unchanged(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');
        config()->set('bots.data_collection.city.fallback_error_message', 'Не смогли распознать город. Напишите, пожалуйста, только название города.');

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'bad gateway']], 500),
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 9940],
            ]),
        ]);

        $channel = $this->createTelegramChannel();
        $message = $this->createInboundUserMessage($channel, [
            'text' => 'Москва',
        ], [], [
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_CITY,
            'first_name' => 'Герман',
            'country' => 'Россия',
        ]);

        ProcessDataCollectionResponseJob::dispatchSync($message->id);

        $contact = $message->contact()->firstOrFail()->fresh();

        $this->assertNull($contact->city);
        $this->assertSame(Contact::DATA_COLLECTION_STATUS_ACTIVE, $contact->data_collection_status);
        $this->assertSame(Contact::DATA_COLLECTION_FIELD_CITY, $contact->data_collection_current_field);
        $this->assertSame(0, $contact->data_collection_attempts_count);
        $this->assertDatabaseHas('messages', [
            'contact_id' => $contact->id,
            'message_kind' => Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION,
            'reply_to_message_id' => $message->id,
            'text' => 'Не смогли распознать город. Напишите, пожалуйста, только название города.',
        ]);
    }

    public function test_job_moves_back_to_country_when_city_step_has_no_country(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');
        config()->set('bots.data_collection.country.question', 'В какой стране вы находитесь?');

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse([
                'decision' => 'accept',
                'city' => 'Не должно использоваться',
            ])),
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 9942],
            ]),
        ]);

        $channel = $this->createTelegramChannel();
        $message = $this->createInboundUserMessage($channel, [
            'text' => 'Москва',
        ], [], [
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_CITY,
            'data_collection_attempts_count' => 1,
            'first_name' => 'Герман',
            'country' => null,
        ]);

        ProcessDataCollectionResponseJob::dispatchSync($message->id);

        Http::assertNotSent(fn ($request): bool => str_starts_with($request->url(), 'https://generativelanguage.googleapis.com/'));

        $contact = $message->contact()->firstOrFail()->fresh();

        $this->assertNull($contact->city);
        $this->assertSame(Contact::DATA_COLLECTION_STATUS_ACTIVE, $contact->data_collection_status);
        $this->assertSame(Contact::DATA_COLLECTION_FIELD_COUNTRY, $contact->data_collection_current_field);
        $this->assertSame(0, $contact->data_collection_attempts_count);
        $this->assertDatabaseHas('messages', [
            'contact_id' => $contact->id,
            'message_kind' => Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION,
            'reply_to_message_id' => $message->id,
            'text' => 'В какой стране вы находитесь?',
        ]);
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
     * @param  array<string, mixed>  $identityOverrides
     * @param  array<string, mixed>  $contactOverrides
     */
    protected function createInboundUserMessage(
        Channel $channel,
        array $messageOverrides = [],
        array $identityOverrides = [],
        array $contactOverrides = [],
    ): Message {
        $contact = Contact::factory()->create(array_merge([
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_ACTIVE,
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_FIRST_NAME,
            'data_collection_started_at' => now(),
        ], $contactOverrides));

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
