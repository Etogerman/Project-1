<?php

namespace Tests\Feature;

use App\Jobs\InferContactGenderFromFirstNameJob;
use App\Jobs\ProcessDataCollectionResponseJob;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ProcessDataCollectionResponseJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_accepts_exact_first_name_without_calling_gemini_and_asks_residence_city(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');
        config()->set('bots.data_collection.residence_city.question', 'В каком городе вы живёте?');

        Queue::fake([InferContactGenderFromFirstNameJob::class]);

        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 9944],
            ]),
        ]);

        $channel = $this->createTelegramChannel();
        $message = $this->createInboundUserMessage($channel, [
            'text' => 'Николай',
        ]);

        ProcessDataCollectionResponseJob::dispatchSync($message->id);

        Http::assertNotSent(fn ($request): bool => str_starts_with($request->url(), 'https://generativelanguage.googleapis.com/'));

        $contact = $message->contact()->firstOrFail()->fresh();

        $this->assertSame('Николай', $contact->first_name);
        $this->assertSame(Contact::DATA_COLLECTION_STATUS_ACTIVE, $contact->data_collection_status);
        $this->assertSame(Contact::DATA_COLLECTION_FIELD_RESIDENCE_CITY, $contact->data_collection_current_field);
        $this->assertSame(0, $contact->data_collection_attempts_count);
        $this->assertDatabaseHas('messages', [
            'contact_id' => $contact->id,
            'message_kind' => Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION,
            'reply_to_message_id' => $message->id,
            'text' => 'В каком городе вы живёте?',
        ]);

        Queue::assertPushed(InferContactGenderFromFirstNameJob::class, function (InferContactGenderFromFirstNameJob $job) use ($contact): bool {
            return $job->contactId === $contact->id
                && $job->expectedFirstName === 'Николай';
        });
    }

    public function test_job_saves_first_name_and_asks_residence_city(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');
        config()->set('bots.data_collection.residence_city.question', 'В каком городе вы живёте?');

        Queue::fake([InferContactGenderFromFirstNameJob::class]);

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
        $this->assertSame(Contact::DATA_COLLECTION_FIELD_RESIDENCE_CITY, $contact->data_collection_current_field);
        $this->assertSame(0, $contact->data_collection_attempts_count);
        $this->assertDatabaseHas('messages', [
            'contact_id' => $contact->id,
            'message_kind' => Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION,
            'reply_to_message_id' => $message->id,
            'text' => 'В каком городе вы живёте?',
        ]);
    }

    public function test_job_accepts_phrase_first_name_without_calling_gemini_and_asks_residence_city(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');
        config()->set('bots.data_collection.residence_city.question', 'В каком городе вы живёте?');

        Queue::fake([InferContactGenderFromFirstNameJob::class]);

        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 9945],
            ]),
        ]);

        $channel = $this->createTelegramChannel();
        $message = $this->createInboundUserMessage($channel, [
            'text' => 'Меня зовут Николай',
        ]);

        ProcessDataCollectionResponseJob::dispatchSync($message->id);

        Http::assertNotSent(fn ($request): bool => str_starts_with($request->url(), 'https://generativelanguage.googleapis.com/'));

        $contact = $message->contact()->firstOrFail()->fresh();

        $this->assertSame('Николай', $contact->first_name);
        $this->assertSame(Contact::DATA_COLLECTION_STATUS_ACTIVE, $contact->data_collection_status);
        $this->assertSame(Contact::DATA_COLLECTION_FIELD_RESIDENCE_CITY, $contact->data_collection_current_field);
        $this->assertSame(0, $contact->data_collection_attempts_count);
    }

    public function test_job_prioritizes_explicit_full_name_without_calling_gemini(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');
        config()->set('bots.data_collection.residence_city.question', 'В каком городе вы живёте?');

        Queue::fake([InferContactGenderFromFirstNameJob::class]);

        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 9946],
            ]),
        ]);

        $channel = $this->createTelegramChannel();
        $message = $this->createInboundUserMessage($channel, [
            'text' => 'Обычно меня зовут Колян, а полное имя Николай',
        ]);

        ProcessDataCollectionResponseJob::dispatchSync($message->id);

        Http::assertNotSent(fn ($request): bool => str_starts_with($request->url(), 'https://generativelanguage.googleapis.com/'));

        $contact = $message->contact()->firstOrFail()->fresh();

        $this->assertSame('Николай', $contact->first_name);
        $this->assertSame(Contact::DATA_COLLECTION_FIELD_RESIDENCE_CITY, $contact->data_collection_current_field);
    }

    public function test_job_accepts_short_multitoken_first_name_reply_without_calling_gemini(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');
        config()->set('bots.data_collection.residence_city.question', 'В каком городе вы живёте?');

        Queue::fake([InferContactGenderFromFirstNameJob::class]);

        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 9947],
            ]),
        ]);

        $channel = $this->createTelegramChannel();
        $message = $this->createInboundUserMessage($channel, [
            'text' => 'Николай Первый',
        ]);

        ProcessDataCollectionResponseJob::dispatchSync($message->id);

        Http::assertNotSent(fn ($request): bool => str_starts_with($request->url(), 'https://generativelanguage.googleapis.com/'));

        $contact = $message->contact()->firstOrFail()->fresh();

        $this->assertSame('Николай', $contact->first_name);
        $this->assertSame(Contact::DATA_COLLECTION_FIELD_RESIDENCE_CITY, $contact->data_collection_current_field);
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
        config()->set('bots.data_collection.residence_city.question', 'В каком городе вы живёте?');

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
        $this->assertSame(Contact::DATA_COLLECTION_FIELD_RESIDENCE_CITY, $contact->data_collection_current_field);
        $this->assertSame(0, $contact->data_collection_attempts_count);
        $this->assertDatabaseHas('messages', [
            'contact_id' => $contact->id,
            'message_kind' => Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION,
            'reply_to_message_id' => $message->id,
            'text' => 'В каком городе вы живёте?',
        ]);
    }

    public function test_job_moves_to_residence_city_after_first_name_skip_without_calling_gemini(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');
        config()->set('bots.data_collection.residence_city.question', 'В каком городе вы живёте?');

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
        $this->assertSame(Contact::DATA_COLLECTION_FIELD_RESIDENCE_CITY, $contact->data_collection_current_field);
        $this->assertSame(0, $contact->data_collection_attempts_count);
    }

    public function test_job_saves_residence_city_and_country_on_high_confidence_and_asks_age_range(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');
        config()->set('bots.data_collection.age_range.telegram_question', 'Укажите ваш возраст:');

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse([
                'decision' => 'accept',
                'city' => 'Будапешт',
                'country' => 'Венгрия',
                'country_confidence' => 'high',
            ])),
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 9954],
            ]),
        ]);

        $channel = $this->createTelegramChannel();
        $message = $this->createInboundUserMessage($channel, [
            'text' => 'Будапешт',
        ], [], [
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_RESIDENCE_CITY,
            'first_name' => 'Герман',
        ]);

        ProcessDataCollectionResponseJob::dispatchSync($message->id);

        $contact = $message->contact()->firstOrFail()->fresh();

        $this->assertSame('Будапешт', $contact->city);
        $this->assertSame('Венгрия', $contact->country);
        $this->assertSame(Contact::DATA_COLLECTION_FIELD_AGE_RANGE, $contact->data_collection_current_field);
    }

    public function test_job_saves_resolved_region_after_residence_city_for_russia(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');
        config()->set('bots.data_collection.age_range.telegram_question', 'Укажите ваш возраст:');
        config()->set('bots.data_collection.russian_region.allowed_regions', [
            'Московская область',
            'Республика Татарстан',
        ]);
        config()->set('russian_region_cities.cities', [
            'москва' => [
                'city' => 'Москва',
                'aliases' => [],
                'regions' => ['Московская область'],
            ],
        ]);

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse([
                'decision' => 'accept',
                'city' => 'Москва',
                'country' => 'Россия',
                'country_confidence' => 'high',
            ])),
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 9954],
            ]),
        ]);

        $channel = $this->createTelegramChannel();
        $message = $this->createInboundUserMessage($channel, [
            'text' => 'Москва',
        ], [], [
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_RESIDENCE_CITY,
            'first_name' => 'Герман',
        ]);

        ProcessDataCollectionResponseJob::dispatchSync($message->id);

        $contact = $message->contact()->firstOrFail()->fresh();

        $this->assertSame('Москва', $contact->city);
        $this->assertSame('Россия', $contact->country);
        $this->assertSame('Московская область', $contact->region);
        $this->assertSame(Contact::REGION_STATUS_RESOLVED, $contact->region_status);
        $this->assertSame(Contact::REGION_SOURCE_AI, $contact->region_source);
        $this->assertSame(Contact::DATA_COLLECTION_FIELD_AGE_RANGE, $contact->data_collection_current_field);
    }

    public function test_job_saves_residence_city_and_asks_country_on_low_confidence(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');
        config()->set('bots.data_collection.country.question', 'В какой стране вы живёте?');
        config()->set('bots.data_collection.country.after_residence_city_question', 'Подскажите, пожалуйста, страну, где вы живёте. Для города «{city}» это нужно уточнить.');

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse([
                'decision' => 'accept',
                'city' => 'Сан-Хосе',
                'country' => null,
                'country_confidence' => 'low',
            ])),
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 9955],
            ]),
        ]);

        $channel = $this->createTelegramChannel();
        $message = $this->createInboundUserMessage($channel, [
            'text' => 'Сан-Хосе',
        ], [], [
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_RESIDENCE_CITY,
            'first_name' => 'Герман',
        ]);

        ProcessDataCollectionResponseJob::dispatchSync($message->id);

        $contact = $message->contact()->firstOrFail()->fresh();

        $this->assertSame('Сан-Хосе', $contact->city);
        $this->assertNull($contact->country);
        $this->assertSame(Contact::DATA_COLLECTION_FIELD_COUNTRY, $contact->data_collection_current_field);
        $this->assertDatabaseHas('messages', [
            'contact_id' => $contact->id,
            'message_kind' => Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION,
            'reply_to_message_id' => $message->id,
            'text' => 'Подскажите, пожалуйста, страну, где вы живёте. Для города «Сан-Хосе» это нужно уточнить.',
        ]);
    }

    public function test_job_shortcuts_to_resolved_russian_region_after_low_confidence_residence_city(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');
        config()->set('bots.data_collection.age_range.telegram_question', 'Укажите ваш возраст:');
        config()->set('bots.data_collection.russian_region.allowed_regions', [
            'Московская область',
        ]);
        config()->set('russian_region_cities.cities', [
            'москва' => [
                'city' => 'Москва',
                'aliases' => [],
                'regions' => ['Московская область'],
            ],
        ]);

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse([
                'decision' => 'accept',
                'city' => 'Москва',
                'country' => null,
                'country_confidence' => 'low',
            ])),
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 9955],
            ]),
        ]);

        $channel = $this->createTelegramChannel();
        $message = $this->createInboundUserMessage($channel, [
            'text' => 'Москва',
        ], [], [
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_RESIDENCE_CITY,
            'first_name' => 'Герман',
        ]);

        ProcessDataCollectionResponseJob::dispatchSync($message->id);

        $contact = $message->contact()->firstOrFail()->fresh();

        $this->assertSame('Москва', $contact->city);
        $this->assertSame('Россия', $contact->country);
        $this->assertSame('Московская область', $contact->region);
        $this->assertSame(Contact::REGION_STATUS_RESOLVED, $contact->region_status);
        $this->assertSame(Contact::DATA_COLLECTION_FIELD_AGE_RANGE, $contact->data_collection_current_field);
        $this->assertDatabaseMissing('messages', [
            'contact_id' => $contact->id,
            'text' => 'Подскажите, пожалуйста, страну, где вы живёте. Для города «Москва» это нужно уточнить.',
        ]);
    }

    public function test_job_shortcuts_to_russian_region_confirm_after_low_confidence_residence_city(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');
        config()->set('bots.data_collection.russian_region.allowed_regions', [
            'Свердловская область',
            'Ставропольский край',
        ]);
        config()->set('bots.data_collection.russian_region_confirm.question', 'Уточните ваш регион:');
        config()->set('russian_region_cities.cities', [
            'михайловск' => [
                'city' => 'Михайловск',
                'aliases' => [],
                'regions' => ['Свердловская область', 'Ставропольский край'],
            ],
        ]);

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse([
                'decision' => 'accept',
                'city' => 'Михайловск',
                'country' => null,
                'country_confidence' => 'low',
            ])),
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 9955],
            ]),
        ]);

        $channel = $this->createTelegramChannel();
        $message = $this->createInboundUserMessage($channel, [
            'text' => 'Михайловск',
        ], [], [
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_RESIDENCE_CITY,
            'first_name' => 'Герман',
        ]);

        ProcessDataCollectionResponseJob::dispatchSync($message->id);

        $contact = $message->contact()->firstOrFail()->fresh();

        $this->assertSame('Михайловск', $contact->city);
        $this->assertSame('Россия', $contact->country);
        $this->assertSame(Contact::REGION_STATUS_CLARIFICATION_PENDING, $contact->region_status);
        $this->assertSame(Contact::DATA_COLLECTION_FIELD_RUSSIAN_REGION_CONFIRM, $contact->data_collection_current_field);
        $this->assertSame(['Свердловская область', 'Ставропольский край'], $contact->pending_region_candidates);
        $this->assertDatabaseMissing('messages', [
            'contact_id' => $contact->id,
            'text' => 'Подскажите, пожалуйста, страну, где вы живёте. Для города «Михайловск» это нужно уточнить.',
        ]);
    }

    public function test_job_marks_russian_city_as_ambiguous_without_asking_country_on_low_confidence(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');
        config()->set('bots.data_collection.age_range.telegram_question', 'Укажите ваш возраст:');
        config()->set('bots.data_collection.russian_region.allowed_regions', [
            'Волгоградская область',
            'Приморский край',
            'Воронежская область',
            'Тульская область',
            'Калужская область',
        ]);
        config()->set('russian_region_cities.cities', [
            'александровка' => [
                'city' => 'Александровка',
                'aliases' => [],
                'regions' => [
                    'Волгоградская область',
                    'Приморский край',
                    'Воронежская область',
                    'Тульская область',
                    'Калужская область',
                ],
            ],
        ]);

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse([
                'decision' => 'accept',
                'city' => 'Александровка',
                'country' => null,
                'country_confidence' => 'low',
            ])),
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 9955],
            ]),
        ]);

        $channel = $this->createTelegramChannel();
        $message = $this->createInboundUserMessage($channel, [
            'text' => 'Александровка',
        ], [], [
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_RESIDENCE_CITY,
            'first_name' => 'Герман',
        ]);

        ProcessDataCollectionResponseJob::dispatchSync($message->id);

        $contact = $message->contact()->firstOrFail()->fresh();

        $this->assertSame('Александровка', $contact->city);
        $this->assertSame('Россия', $contact->country);
        $this->assertNull($contact->region);
        $this->assertSame(Contact::REGION_STATUS_AMBIGUOUS, $contact->region_status);
        $this->assertSame(Contact::DATA_COLLECTION_FIELD_AGE_RANGE, $contact->data_collection_current_field);
        $this->assertDatabaseMissing('messages', [
            'contact_id' => $contact->id,
            'text' => 'Подскажите, пожалуйста, страну, где вы живёте. Для города «Александровка» это нужно уточнить.',
        ]);
    }

    public function test_job_keeps_residence_city_and_asks_country_when_country_confidence_is_malformed(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');
        config()->set('bots.data_collection.country.question', 'В какой стране вы живёте?');
        config()->set('bots.data_collection.country.after_residence_city_question', 'Подскажите, пожалуйста, страну, где вы живёте. Для города «{city}» это нужно уточнить.');

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse([
                'decision' => 'accept',
                'city' => 'Мапуто',
                'country' => 'Мозамбик',
                'country_confidence' => 'medium',
            ])),
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 9960],
            ]),
        ]);

        $channel = $this->createTelegramChannel();
        $message = $this->createInboundUserMessage($channel, [
            'text' => 'Мапуто',
        ], [], [
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_RESIDENCE_CITY,
            'first_name' => 'Герман',
        ]);

        ProcessDataCollectionResponseJob::dispatchSync($message->id);

        $contact = $message->contact()->firstOrFail()->fresh();

        $this->assertSame('Мапуто', $contact->city);
        $this->assertNull($contact->country);
        $this->assertSame(0, $contact->data_collection_attempts_count);
        $this->assertSame(Contact::DATA_COLLECTION_FIELD_COUNTRY, $contact->data_collection_current_field);
        $this->assertDatabaseHas('messages', [
            'contact_id' => $contact->id,
            'message_kind' => Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION,
            'reply_to_message_id' => $message->id,
            'text' => 'Подскажите, пожалуйста, страну, где вы живёте. Для города «Мапуто» это нужно уточнить.',
        ]);
    }

    public function test_job_does_not_save_country_when_it_does_not_match_saved_residence_city(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');
        config()->set('bots.data_collection.country.city_mismatch_message', 'Похоже, город «{city}» не относится к стране «{country}». Подскажите, пожалуйста, страну, где вы живёте.');

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse([
                'decision' => 'retry',
                'city' => null,
            ])),
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 9956],
            ]),
        ]);

        $channel = $this->createTelegramChannel();
        $message = $this->createInboundUserMessage($channel, [
            'text' => 'Кения',
        ], [], [
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_COUNTRY,
            'first_name' => 'Герман',
            'city' => 'Будапешт',
            'country' => null,
        ]);

        ProcessDataCollectionResponseJob::dispatchSync($message->id);

        $contact = $message->contact()->firstOrFail()->fresh();

        $this->assertSame('Будапешт', $contact->city);
        $this->assertNull($contact->country);
        $this->assertSame(Contact::DATA_COLLECTION_FIELD_COUNTRY, $contact->data_collection_current_field);
        $this->assertSame(1, $contact->data_collection_attempts_count);
        $this->assertDatabaseHas('messages', [
            'contact_id' => $contact->id,
            'message_kind' => Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION,
            'reply_to_message_id' => $message->id,
            'text' => 'Похоже, город «Будапешт» не относится к стране «Кения». Подскажите, пожалуйста, страну, где вы живёте.',
        ]);
    }

    public function test_job_saves_country_and_asks_city_instead_of_completing(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');
        config()->set('bots.data_collection.city.question', 'В каком городе вы живёте?');

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
            'text' => 'В каком городе вы живёте?',
        ]);
    }

    public function test_job_accepts_exact_country_name_without_calling_gemini(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');
        config()->set('bots.data_collection.city.question', 'В каком городе вы живёте?');

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
            'text' => 'В каком городе вы живёте?',
        ]);
    }

    public function test_job_moves_to_city_after_second_invalid_country_attempt(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');
        config()->set('bots.data_collection.country.max_attempts', 2);
        config()->set('bots.data_collection.city.question', 'В каком городе вы живёте?');

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
            'text' => 'В каком городе вы живёте?',
        ]);
    }

    public function test_job_moves_to_city_after_country_skip_without_calling_gemini(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');
        config()->set('bots.data_collection.city.question', 'В каком городе вы живёте?');

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

    public function test_job_saves_city_and_asks_age_range_instead_of_completing(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');
        config()->set('bots.data_collection.age_range.telegram_question', 'Укажите ваш возраст:');

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
        $this->assertSame(Contact::DATA_COLLECTION_STATUS_ACTIVE, $contact->data_collection_status);
        $this->assertSame(Contact::DATA_COLLECTION_FIELD_AGE_RANGE, $contact->data_collection_current_field);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['text'] === 'Укажите ваш возраст:'
            && data_get($request->data(), 'reply_markup.inline_keyboard.0.0.text') === 'До 18 лет'
            && data_get($request->data(), 'reply_markup.inline_keyboard.0.1.text') === '18 - 23 года'
            && data_get($request->data(), 'reply_markup.inline_keyboard.1.0.text') === '24 - 29 лет'
            && data_get($request->data(), 'reply_markup.inline_keyboard.1.1.text') === '30 - 39 лет'
            && data_get($request->data(), 'reply_markup.inline_keyboard.2.0.text') === 'Больше 40 лет');
        $this->assertDatabaseHas('messages', [
            'contact_id' => $contact->id,
            'message_kind' => Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION,
            'reply_to_message_id' => $message->id,
            'text' => 'Укажите ваш возраст:',
        ]);
    }

    public function test_job_marks_region_as_ambiguous_without_breaking_city_flow(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');
        config()->set('bots.data_collection.age_range.telegram_question', 'Укажите ваш возраст:');
        config()->set('bots.data_collection.russian_region.allowed_regions', [
            'Волгоградская область',
            'Приморский край',
            'Воронежская область',
            'Тульская область',
            'Калужская область',
        ]);
        config()->set('russian_region_cities.cities', [
            'александровка' => [
                'city' => 'Александровка',
                'aliases' => [],
                'regions' => [
                    'Волгоградская область',
                    'Приморский край',
                    'Воронежская область',
                    'Тульская область',
                    'Калужская область',
                ],
            ],
        ]);

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse([
                'decision' => 'accept',
                'city' => 'Александровка',
            ])),
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 9937],
            ]),
        ]);

        $channel = $this->createTelegramChannel();
        $message = $this->createInboundUserMessage($channel, [
            'text' => 'Александровка',
        ], [], [
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_CITY,
            'first_name' => 'Герман',
            'country' => 'Россия',
        ]);

        ProcessDataCollectionResponseJob::dispatchSync($message->id);

        $contact = $message->contact()->firstOrFail()->fresh();

        $this->assertSame('Александровка', $contact->city);
        $this->assertNull($contact->region);
        $this->assertSame(Contact::REGION_STATUS_AMBIGUOUS, $contact->region_status);
        $this->assertNull($contact->region_source);
        $this->assertSame(Contact::DATA_COLLECTION_FIELD_AGE_RANGE, $contact->data_collection_current_field);
    }

    public function test_job_moves_to_russian_region_confirm_when_region_clarification_is_needed(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');
        config()->set('bots.data_collection.russian_region.allowed_regions', [
            'Волгоградская область',
            'Приморский край',
            'Воронежская область',
        ]);
        config()->set('bots.data_collection.russian_region_confirm.question', 'Уточните ваш регион:');
        config()->set('russian_region_cities.cities', [
            'михайловка' => [
                'city' => 'Михайловка',
                'aliases' => [],
                'regions' => ['Волгоградская область', 'Приморский край', 'Воронежская область'],
            ],
        ]);

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse([
                'decision' => 'accept',
                'city' => 'Михайловка',
            ])),
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 9937],
            ]),
        ]);

        $channel = $this->createTelegramChannel();
        $message = $this->createInboundUserMessage($channel, [
            'text' => 'Михайловка',
        ], [], [
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_CITY,
            'first_name' => 'Герман',
            'country' => 'Россия',
        ]);

        ProcessDataCollectionResponseJob::dispatchSync($message->id);

        $contact = $message->contact()->firstOrFail()->fresh();

        $this->assertSame(Contact::DATA_COLLECTION_FIELD_RUSSIAN_REGION_CONFIRM, $contact->data_collection_current_field);
        $this->assertSame(Contact::REGION_STATUS_CLARIFICATION_PENDING, $contact->region_status);
        $this->assertSame([
            'Волгоградская область',
            'Приморский край',
            'Воронежская область',
        ], $contact->pending_region_candidates);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && str_contains((string) $request['text'], 'Уточните ваш регион:')
            && str_contains((string) $request['text'], '1. Волгоградская область')
            && str_contains((string) $request['text'], '2. Приморский край')
            && str_contains((string) $request['text'], '3. Воронежская область')
            && data_get($request->data(), 'reply_markup.inline_keyboard.0.0.text') === 'Волгоградская область'
            && data_get($request->data(), 'reply_markup.inline_keyboard.0.1.text') === 'Приморский край'
            && data_get($request->data(), 'reply_markup.inline_keyboard.1.0.text') === 'Воронежская область'
            && data_get($request->data(), 'reply_markup.inline_keyboard.2.0.text') === 'Пропустить');
    }

    public function test_job_saves_russian_region_from_numeric_reply_and_moves_to_age_range(): void
    {
        config()->set('bots.data_collection.age_range.telegram_question', 'Укажите ваш возраст:');

        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 9954],
            ]),
        ]);

        $channel = $this->createTelegramChannel();
        $message = $this->createInboundUserMessage($channel, [
            'text' => '2',
        ], [], [
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_RUSSIAN_REGION_CONFIRM,
            'first_name' => 'Герман',
            'country' => 'Россия',
            'city' => 'Михайловка',
            'region_status' => Contact::REGION_STATUS_CLARIFICATION_PENDING,
            'pending_region_candidates' => ['Волгоградская область', 'Приморский край'],
        ]);

        ProcessDataCollectionResponseJob::dispatchSync($message->id);

        $contact = $message->contact()->firstOrFail()->fresh();

        $this->assertSame('Приморский край', $contact->region);
        $this->assertSame(Contact::REGION_STATUS_RESOLVED, $contact->region_status);
        $this->assertSame(Contact::REGION_SOURCE_CONFIRMED_BY_CONTACT, $contact->region_source);
        $this->assertNull($contact->pending_region_candidates);
        $this->assertSame(Contact::DATA_COLLECTION_FIELD_AGE_RANGE, $contact->data_collection_current_field);
    }

    public function test_job_retries_for_invalid_russian_region_and_keeps_same_buttons(): void
    {
        config()->set('bots.data_collection.russian_region_confirm.retry_message', 'Уточните ваш регион:');

        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 9954],
            ]),
        ]);

        $channel = $this->createTelegramChannel();
        $message = $this->createInboundUserMessage($channel, [
            'text' => '99',
        ], [], [
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_RUSSIAN_REGION_CONFIRM,
            'first_name' => 'Герман',
            'country' => 'Россия',
            'city' => 'Михайловка',
            'region_status' => Contact::REGION_STATUS_CLARIFICATION_PENDING,
            'pending_region_candidates' => ['Волгоградская область', 'Приморский край'],
        ]);

        ProcessDataCollectionResponseJob::dispatchSync($message->id);

        $contact = $message->contact()->firstOrFail()->fresh();

        $this->assertNull($contact->region);
        $this->assertSame(Contact::DATA_COLLECTION_FIELD_RUSSIAN_REGION_CONFIRM, $contact->data_collection_current_field);
        $this->assertSame(1, $contact->data_collection_attempts_count);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && str_contains((string) $request['text'], 'Уточните ваш регион:')
            && data_get($request->data(), 'reply_markup.inline_keyboard.0.0.text') === 'Волгоградская область'
            && data_get($request->data(), 'reply_markup.inline_keyboard.0.1.text') === 'Приморский край'
            && data_get($request->data(), 'reply_markup.inline_keyboard.1.0.text') === 'Пропустить');
    }

    public function test_job_skips_russian_region_confirm_and_moves_to_age_range(): void
    {
        config()->set('bots.data_collection.age_range.telegram_question', 'Укажите ваш возраст:');

        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 9954],
            ]),
        ]);

        $channel = $this->createTelegramChannel();
        $message = $this->createInboundUserMessage($channel, [
            'text' => 'пропустить',
        ], [], [
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_RUSSIAN_REGION_CONFIRM,
            'first_name' => 'Герман',
            'country' => 'Россия',
            'city' => 'Михайловка',
            'region_status' => Contact::REGION_STATUS_CLARIFICATION_PENDING,
            'pending_region_candidates' => ['Волгоградская область', 'Приморский край'],
        ]);

        ProcessDataCollectionResponseJob::dispatchSync($message->id);

        $contact = $message->contact()->firstOrFail()->fresh();

        $this->assertNull($contact->region);
        $this->assertSame(Contact::REGION_STATUS_AMBIGUOUS, $contact->region_status);
        $this->assertNull($contact->pending_region_candidates);
        $this->assertSame(Contact::DATA_COLLECTION_FIELD_AGE_RANGE, $contact->data_collection_current_field);
    }

    public function test_job_sends_russian_region_confirm_question_with_max_inline_buttons(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');
        config()->set('bots.data_collection.russian_region.allowed_regions', [
            'Волгоградская область',
            'Приморский край',
            'Воронежская область',
        ]);
        config()->set('bots.data_collection.russian_region_confirm.question', 'Уточните ваш регион:');
        config()->set('russian_region_cities.cities', [
            'михайловка' => [
                'city' => 'Михайловка',
                'aliases' => [],
                'regions' => ['Волгоградская область', 'Приморский край', 'Воронежская область'],
            ],
        ]);

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse([
                'decision' => 'accept',
                'city' => 'Михайловка',
            ])),
            'https://platform-api.max.ru/*' => Http::response([
                'message' => ['message_id' => 'max-region-confirm-1'],
            ]),
        ]);

        $channel = $this->createMaxChannel();
        $message = $this->createInboundUserMessage($channel, [
            'text' => 'Михайловка',
        ], [
            'external_user_id' => '500',
        ], [
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_CITY,
            'first_name' => 'Герман',
            'country' => 'Россия',
        ]);

        ProcessDataCollectionResponseJob::dispatchSync($message->id);

        $contact = $message->contact()->firstOrFail()->fresh();

        $this->assertSame(Contact::DATA_COLLECTION_FIELD_RUSSIAN_REGION_CONFIRM, $contact->data_collection_current_field);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://platform-api.max.ru/messages?chat_id=700'
            && str_contains((string) $request['text'], 'Уточните ваш регион:')
            && data_get($request->data(), 'attachments.0.type') === 'inline_keyboard'
            && data_get($request->data(), 'attachments.0.payload.buttons.0.0.text') === 'Волгоградская область'
            && data_get($request->data(), 'attachments.0.payload.buttons.0.1.text') === 'Приморский край'
            && data_get($request->data(), 'attachments.0.payload.buttons.1.0.text') === 'Воронежская область'
            && data_get($request->data(), 'attachments.0.payload.buttons.2.0.text') === 'Пропустить');
    }

    public function test_job_after_country_answer_uses_exact_candidates_without_mixing_similar_city_names(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');
        config()->set('bots.data_collection.russian_region.allowed_regions', [
            'Свердловская область',
            'Ставропольский край',
            'Волгоградская область',
            'Приморский край',
            'Воронежская область',
        ]);
        config()->set('bots.data_collection.russian_region_confirm.question', 'Уточните ваш регион:');
        config()->set('russian_region_cities.cities', [
            'михайловск' => [
                'city' => 'Михайловск',
                'aliases' => [],
                'regions' => ['Свердловская область', 'Ставропольский край'],
            ],
            'михайловка' => [
                'city' => 'Михайловка',
                'aliases' => [],
                'regions' => ['Волгоградская область', 'Приморский край', 'Воронежская область'],
            ],
        ]);

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse([
                'decision' => 'accept',
                'city' => 'Михайловск',
            ])),
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 9963],
            ]),
        ]);

        $channel = $this->createTelegramChannel();
        $message = $this->createInboundUserMessage($channel, [
            'text' => 'Россия',
        ], [], [
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_COUNTRY,
            'first_name' => 'Герман',
            'city' => 'Михайловск',
        ]);

        ProcessDataCollectionResponseJob::dispatchSync($message->id);

        $contact = $message->contact()->firstOrFail()->fresh();

        $this->assertSame('Россия', $contact->country);
        $this->assertSame(Contact::DATA_COLLECTION_FIELD_RUSSIAN_REGION_CONFIRM, $contact->data_collection_current_field);
        $this->assertSame(Contact::REGION_STATUS_CLARIFICATION_PENDING, $contact->region_status);
        $this->assertSame(['Свердловская область', 'Ставропольский край'], $contact->pending_region_candidates);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && str_contains((string) $request['text'], '1. Свердловская область')
            && str_contains((string) $request['text'], '2. Ставропольский край')
            && ! str_contains((string) $request['text'], 'Волгоградская область')
            && ! str_contains((string) $request['text'], 'Приморский край')
            && data_get($request->data(), 'reply_markup.inline_keyboard.0.0.text') === 'Свердловская область'
            && data_get($request->data(), 'reply_markup.inline_keyboard.0.1.text') === 'Ставропольский край');
    }

    public function test_job_saves_russian_region_from_max_button_label_and_moves_to_age_range(): void
    {
        config()->set('bots.data_collection.age_range.max_question', 'Укажите ваш возраст:');

        Http::fake([
            'https://platform-api.max.ru/*' => Http::response([
                'message' => ['message_id' => 'max-region-complete-1'],
            ]),
        ]);

        $channel = $this->createMaxChannel();
        $message = $this->createInboundUserMessage($channel, [
            'text' => 'Приморский край',
        ], [
            'external_user_id' => '500',
        ], [
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_RUSSIAN_REGION_CONFIRM,
            'first_name' => 'Герман',
            'country' => 'Россия',
            'city' => 'Михайловка',
            'region_status' => Contact::REGION_STATUS_CLARIFICATION_PENDING,
            'pending_region_candidates' => ['Волгоградская область', 'Приморский край'],
        ]);

        ProcessDataCollectionResponseJob::dispatchSync($message->id);

        $contact = $message->contact()->firstOrFail()->fresh();

        $this->assertSame('Приморский край', $contact->region);
        $this->assertSame(Contact::REGION_SOURCE_CONFIRMED_BY_CONTACT, $contact->region_source);
        $this->assertSame(Contact::DATA_COLLECTION_FIELD_AGE_RANGE, $contact->data_collection_current_field);
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

    public function test_job_handles_city_skip_without_calling_gemini_and_moves_to_age_range(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');
        config()->set('bots.data_collection.age_range.telegram_question', 'Укажите ваш возраст:');

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
        $this->assertSame(Contact::DATA_COLLECTION_STATUS_ACTIVE, $contact->data_collection_status);
        $this->assertSame(Contact::DATA_COLLECTION_FIELD_AGE_RANGE, $contact->data_collection_current_field);
        $this->assertSame(0, $contact->data_collection_attempts_count);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['text'] === 'Укажите ваш возраст:'
            && data_get($request->data(), 'reply_markup.inline_keyboard.0.0.text') === 'До 18 лет');
        $this->assertDatabaseHas('messages', [
            'contact_id' => $contact->id,
            'message_kind' => Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION,
            'reply_to_message_id' => $message->id,
            'text' => 'Укажите ваш возраст:',
        ]);
    }

    public function test_job_moves_to_age_range_after_second_invalid_city_attempt(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');
        config()->set('bots.data_collection.city.max_attempts', 2);
        config()->set('bots.data_collection.age_range.telegram_question', 'Укажите ваш возраст:');

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse([
                'decision' => 'retry',
                'city' => null,
            ])),
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 9948],
            ]),
        ]);

        $channel = $this->createTelegramChannel();
        $message = $this->createInboundUserMessage($channel, [
            'text' => 'Не скажу',
        ], [], [
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_CITY,
            'data_collection_attempts_count' => 1,
            'first_name' => 'Герман',
            'country' => 'Россия',
        ]);

        ProcessDataCollectionResponseJob::dispatchSync($message->id);

        $contact = $message->contact()->firstOrFail()->fresh();

        $this->assertNull($contact->city);
        $this->assertSame(Contact::DATA_COLLECTION_STATUS_ACTIVE, $contact->data_collection_status);
        $this->assertSame(Contact::DATA_COLLECTION_FIELD_AGE_RANGE, $contact->data_collection_current_field);
        $this->assertSame(0, $contact->data_collection_attempts_count);
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
        config()->set('bots.data_collection.country.question', 'В какой стране вы живёте?');

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
            'text' => 'В какой стране вы живёте?',
        ]);
    }

    public function test_job_saves_age_range_from_numeric_option_and_completes(): void
    {
        config()->set('bots.data_collection.completion_message', 'Спасибо, данные сохранили.');

        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 9949],
            ]),
        ]);

        $channel = $this->createTelegramChannel();
        $message = $this->createInboundUserMessage($channel, [
            'text' => '3',
        ], [], [
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_AGE_RANGE,
            'first_name' => 'Герман',
            'country' => 'Россия',
            'city' => 'Москва',
        ]);

        ProcessDataCollectionResponseJob::dispatchSync($message->id);

        $contact = $message->contact()->firstOrFail()->fresh();

        $this->assertSame('24_29', $contact->age_range);
        $this->assertSame(Contact::DATA_COLLECTION_STATUS_COMPLETED, $contact->data_collection_status);
        $this->assertNull($contact->data_collection_current_field);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && data_get($request->data(), 'reply_markup.remove_keyboard') === true);
        $this->assertDatabaseHas('messages', [
            'contact_id' => $contact->id,
            'message_kind' => Message::KIND_OUTBOUND_DATA_COLLECTION_COMPLETION,
            'reply_to_message_id' => $message->id,
            'text' => 'Спасибо, данные сохранили.',
        ]);
    }

    public function test_job_saves_age_range_from_label_and_completes(): void
    {
        config()->set('bots.data_collection.completion_message', 'Спасибо, данные сохранили.');

        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 9950],
            ]),
        ]);

        $channel = $this->createTelegramChannel();
        $message = $this->createInboundUserMessage($channel, [
            'text' => '24 - 29 лет',
        ], [], [
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_AGE_RANGE,
            'first_name' => 'Герман',
            'country' => 'Россия',
            'city' => 'Москва',
        ]);

        ProcessDataCollectionResponseJob::dispatchSync($message->id);

        $contact = $message->contact()->firstOrFail()->fresh();

        $this->assertSame('24_29', $contact->age_range);
        $this->assertSame(Contact::DATA_COLLECTION_STATUS_COMPLETED, $contact->data_collection_status);
    }

    public function test_job_saves_age_range_from_canonical_value_and_completes(): void
    {
        config()->set('bots.data_collection.completion_message', 'Спасибо, данные сохранили.');

        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 9954],
            ]),
        ]);

        $channel = $this->createTelegramChannel();
        $message = $this->createInboundUserMessage($channel, [
            'text' => '24_29',
        ], [], [
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_AGE_RANGE,
            'first_name' => 'Герман',
            'country' => 'Россия',
            'city' => 'Москва',
        ]);

        ProcessDataCollectionResponseJob::dispatchSync($message->id);

        $contact = $message->contact()->firstOrFail()->fresh();

        $this->assertSame('24_29', $contact->age_range);
        $this->assertSame(Contact::DATA_COLLECTION_STATUS_COMPLETED, $contact->data_collection_status);
    }

    public function test_job_retries_for_invalid_age_range_and_keeps_age_range_step_active(): void
    {
        config()->set('bots.data_collection.age_range.retry_message', 'Пожалуйста, выберите один из вариантов: 1, 2, 3, 4 или 5.');

        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 9951],
            ]),
        ]);

        $channel = $this->createTelegramChannel();
        $message = $this->createInboundUserMessage($channel, [
            'text' => '31',
        ], [], [
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_AGE_RANGE,
            'first_name' => 'Герман',
            'country' => 'Россия',
            'city' => 'Москва',
        ]);

        ProcessDataCollectionResponseJob::dispatchSync($message->id);

        $contact = $message->contact()->firstOrFail()->fresh();

        $this->assertNull($contact->age_range);
        $this->assertSame(Contact::DATA_COLLECTION_STATUS_ACTIVE, $contact->data_collection_status);
        $this->assertSame(Contact::DATA_COLLECTION_FIELD_AGE_RANGE, $contact->data_collection_current_field);
        $this->assertSame(1, $contact->data_collection_attempts_count);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && data_get($request->data(), 'reply_markup.inline_keyboard.0.0.text') === 'До 18 лет'
            && data_get($request->data(), 'reply_markup.inline_keyboard.0.1.text') === '18 - 23 года'
            && data_get($request->data(), 'reply_markup.inline_keyboard.1.0.text') === '24 - 29 лет'
            && data_get($request->data(), 'reply_markup.inline_keyboard.1.1.text') === '30 - 39 лет'
            && data_get($request->data(), 'reply_markup.inline_keyboard.2.0.text') === 'Больше 40 лет');
        $this->assertDatabaseHas('messages', [
            'contact_id' => $contact->id,
            'message_kind' => Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION,
            'reply_to_message_id' => $message->id,
            'text' => 'Пожалуйста, выберите один из вариантов: 1, 2, 3, 4 или 5.',
        ]);
    }

    public function test_job_retries_for_invalid_age_range_in_max_and_keeps_inline_buttons(): void
    {
        config()->set('bots.data_collection.age_range.retry_message', 'Пожалуйста, выберите один из вариантов: 1, 2, 3, 4 или 5.');

        Http::fake([
            'https://platform-api.max.ru/*' => Http::response([
                'message' => ['message_id' => 'max-age-retry-1'],
            ]),
        ]);

        $channel = $this->createMaxChannel();
        $message = $this->createInboundUserMessage($channel, [
            'text' => '31',
        ], [
            'external_user_id' => '500',
        ], [
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_AGE_RANGE,
            'first_name' => 'Герман',
            'country' => 'Россия',
            'city' => 'Москва',
        ]);

        ProcessDataCollectionResponseJob::dispatchSync($message->id);

        $contact = $message->contact()->firstOrFail()->fresh();

        $this->assertNull($contact->age_range);
        $this->assertSame(Contact::DATA_COLLECTION_STATUS_ACTIVE, $contact->data_collection_status);
        $this->assertSame(Contact::DATA_COLLECTION_FIELD_AGE_RANGE, $contact->data_collection_current_field);
        $this->assertSame(1, $contact->data_collection_attempts_count);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://platform-api.max.ru/messages?chat_id=700'
            && $request['text'] === 'Пожалуйста, выберите один из вариантов: 1, 2, 3, 4 или 5.'
            && data_get($request->data(), 'attachments.0.type') === 'inline_keyboard'
            && data_get($request->data(), 'attachments.0.payload.buttons.0.0.type') === 'message'
            && data_get($request->data(), 'attachments.0.payload.buttons.0.0.text') === 'До 18 лет'
            && data_get($request->data(), 'attachments.0.payload.buttons.0.1.text') === '18 - 23 года'
            && data_get($request->data(), 'attachments.0.payload.buttons.1.0.text') === '24 - 29 лет'
            && data_get($request->data(), 'attachments.0.payload.buttons.1.1.text') === '30 - 39 лет'
            && data_get($request->data(), 'attachments.0.payload.buttons.2.0.text') === 'Больше 40 лет'
            && data_get($request->data(), 'attachments.0.payload.buttons.2.1') === null);
        $this->assertDatabaseHas('messages', [
            'contact_id' => $contact->id,
            'message_kind' => Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION,
            'reply_to_message_id' => $message->id,
            'text' => 'Пожалуйста, выберите один из вариантов: 1, 2, 3, 4 или 5.',
        ]);
    }

    public function test_job_saves_age_range_from_max_button_label_and_completes(): void
    {
        config()->set('bots.data_collection.completion_message', 'Спасибо, данные сохранили.');

        Http::fake([
            'https://platform-api.max.ru/*' => Http::response([
                'message' => ['message_id' => 'max-age-complete-1'],
            ]),
        ]);

        $channel = $this->createMaxChannel();
        $message = $this->createInboundUserMessage($channel, [
            'text' => '24 - 29 лет',
        ], [
            'external_user_id' => '500',
        ], [
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_AGE_RANGE,
            'first_name' => 'Герман',
            'country' => 'Россия',
            'city' => 'Москва',
        ]);

        ProcessDataCollectionResponseJob::dispatchSync($message->id);

        $contact = $message->contact()->firstOrFail()->fresh();

        $this->assertSame('24_29', $contact->age_range);
        $this->assertSame(Contact::DATA_COLLECTION_STATUS_COMPLETED, $contact->data_collection_status);
        $this->assertNull($contact->data_collection_current_field);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://platform-api.max.ru/messages?chat_id=700'
            && $request['text'] === 'Спасибо, данные сохранили.'
            && data_get($request->data(), 'attachments') === null);
        $this->assertDatabaseHas('messages', [
            'contact_id' => $contact->id,
            'message_kind' => Message::KIND_OUTBOUND_DATA_COLLECTION_COMPLETION,
            'reply_to_message_id' => $message->id,
            'text' => 'Спасибо, данные сохранили.',
        ]);
    }

    public function test_job_handles_age_range_skip_and_completes(): void
    {
        config()->set('bots.data_collection.age_range.skip_message', 'Хорошо, возраст пропустим.');

        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 9952],
            ]),
        ]);

        $channel = $this->createTelegramChannel();
        $message = $this->createInboundUserMessage($channel, [
            'text' => 'пропустить',
        ], [], [
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_AGE_RANGE,
            'first_name' => 'Герман',
            'country' => 'Россия',
            'city' => 'Москва',
        ]);

        ProcessDataCollectionResponseJob::dispatchSync($message->id);

        $contact = $message->contact()->firstOrFail()->fresh();

        $this->assertNull($contact->age_range);
        $this->assertSame(Contact::DATA_COLLECTION_STATUS_COMPLETED, $contact->data_collection_status);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && data_get($request->data(), 'reply_markup.remove_keyboard') === true);
        $this->assertDatabaseHas('messages', [
            'contact_id' => $contact->id,
            'message_kind' => Message::KIND_OUTBOUND_DATA_COLLECTION_COMPLETION,
            'reply_to_message_id' => $message->id,
            'text' => 'Хорошо, возраст пропустим.',
        ]);
    }

    public function test_job_completes_after_second_invalid_age_range_attempt(): void
    {
        config()->set('bots.data_collection.age_range.max_attempts', 2);
        config()->set('bots.data_collection.age_range.skip_message', 'Хорошо, возраст пропустим.');

        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 9953],
            ]),
        ]);

        $channel = $this->createTelegramChannel();
        $message = $this->createInboundUserMessage($channel, [
            'text' => 'мне 31',
        ], [], [
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_AGE_RANGE,
            'data_collection_attempts_count' => 1,
            'first_name' => 'Герман',
            'country' => 'Россия',
            'city' => 'Москва',
        ]);

        ProcessDataCollectionResponseJob::dispatchSync($message->id);

        $contact = $message->contact()->firstOrFail()->fresh();

        $this->assertNull($contact->age_range);
        $this->assertSame(Contact::DATA_COLLECTION_STATUS_COMPLETED, $contact->data_collection_status);
        $this->assertSame(0, $contact->data_collection_attempts_count);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && data_get($request->data(), 'reply_markup.remove_keyboard') === true);
        $this->assertDatabaseHas('messages', [
            'contact_id' => $contact->id,
            'message_kind' => Message::KIND_OUTBOUND_DATA_COLLECTION_COMPLETION,
            'reply_to_message_id' => $message->id,
            'text' => 'Хорошо, возраст пропустим.',
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

    protected function createMaxChannel(): Channel
    {
        return Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
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
