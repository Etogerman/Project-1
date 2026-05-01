<?php

namespace Tests\Feature;

use App\Jobs\ProcessAutoReplyJob;
use App\Jobs\ProcessDataCollectionResponseJob;
use App\Jobs\ProcessScenarioStartJob;
use App\Models\AutoReplyRule;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Message;
use App\Models\ScenarioChannelBinding;
use App\Models\ScenarioRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WarmupScenarioTelegramTest extends TestCase
{
    use RefreshDatabase;

    public function test_warmup_starts_on_first_telegram_inbound_and_preempts_auto_reply(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 9001,
                ],
            ]),
        ]);

        $channel = $this->createTelegramChannel();

        AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'keyword' => null,
            'normalized_keyword' => null,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
            'reply_text' => 'Обычный автоответ.',
            'is_active' => true,
        ]);

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => 'warmup',
            'is_active' => true,
        ]);

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload(
            userId: 200,
            chatId: 300,
            messageId: 10,
            text: 'hello',
        ));

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $run = ScenarioRun::query()->where('scenario_code', 'warmup')->firstOrFail();
        $inboundMessage = Message::query()->where('direction', Message::DIRECTION_INBOUND)->firstOrFail();
        $outboundMessage = Message::query()
            ->where('direction', Message::DIRECTION_OUTBOUND)
            ->where('message_kind', Message::KIND_OUTBOUND_SCENARIO_MESSAGE)
            ->firstOrFail();

        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('awaiting_reaction', $run->current_step);
        $this->assertSame($inboundMessage->id, data_get($run->state_payload, 'trigger_message_id'));
        $this->assertSame($outboundMessage->id, data_get($run->state_payload, 'prompt_message_id'));
        $this->assertSame(Message::SENT_BY_TYPE_SYSTEM, $outboundMessage->sent_by_type);
        $this->assertSame(Message::SENT_BY_SYSTEM_CODE_SCENARIO_WARMUP, $outboundMessage->sent_by_system_code);

        Http::assertSent(function ($request) use ($run): bool {
            return $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
                && $request['chat_id'] === '300'
                && $request['text'] === config('bots.scenarios.warmup.telegram.text')
                && data_get($request->data(), 'reply_markup.inline_keyboard.0.0.callback_data') === "scenario:warmup:{$run->id}:positive";
        });

        $this->assertDatabaseCount('scenario_runs', 1);
        $this->assertDatabaseCount('messages', 2);
        $this->assertDatabaseHas('messages', [
            'id' => $outboundMessage->id,
            'message_kind' => Message::KIND_OUTBOUND_SCENARIO_MESSAGE,
            'reply_to_message_id' => $inboundMessage->id,
            'external_message_id' => '9001',
        ]);
    }

    public function test_warmup_callback_completes_run_and_answers_callback(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 9001,
                ],
            ]),
        ]);

        $channel = $this->createTelegramChannel();

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => 'warmup',
            'is_active' => true,
        ]);

        $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload());

        $run = ScenarioRun::query()->where('scenario_code', 'warmup')->firstOrFail();

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramCallbackPayload(
            callbackId: 'callback-901',
            callbackData: "scenario:warmup:{$run->id}:positive",
            messageId: 901,
        ));

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $run->refresh();
        $callbackMessage = Message::query()
            ->where('direction', Message::DIRECTION_INBOUND)
            ->where('external_message_id', 'callback-901')
            ->firstOrFail();

        $this->assertSame(ScenarioRun::STATUS_COMPLETED, $run->status);
        $this->assertNotNull($run->finished_at);
        $this->assertSame('reacted_positive', $run->exit_outcome);
        $this->assertSame($callbackMessage->id, data_get($run->state_payload, 'reaction_message_id'));
        $this->assertSame('positive', data_get($run->state_payload, 'reaction_action'));
        $this->assertSame(config('bots.scenarios.warmup.telegram.buttons.positive'), data_get($run->state_payload, 'reaction_label'));
        $this->assertSame('telegram_callback', data_get($run->state_payload, 'reaction_source'));
        $this->assertSame('warmup:positive', $callbackMessage->text);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/answerCallbackQuery'
            && $request['callback_query_id'] === 'callback-901');
    }

    public function test_stale_warmup_callback_is_answered_and_ignored(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => true,
            ]),
        ]);

        $channel = $this->createTelegramChannel();

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramCallbackPayload(
            callbackId: 'callback-902',
            callbackData: 'scenario:warmup:999:positive',
        ));

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('scenario_runs', 0);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/answerCallbackQuery'
            && $request['callback_query_id'] === 'callback-902');
    }

    public function test_active_collector_keeps_priority_over_warmup(): void
    {
        Queue::fake();
        Http::fake();

        $channel = $this->createTelegramChannel();
        $contact = Contact::factory()->create([
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_ACTIVE,
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_FIRST_NAME,
            'data_collection_last_prompted_field' => Contact::DATA_COLLECTION_FIELD_FIRST_NAME,
            'data_collection_started_at' => now(),
        ]);

        ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '200',
            'external_username' => 'telegram_user',
        ]);

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => 'warmup',
            'is_active' => true,
        ]);

        $response = $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload(
            userId: 200,
            chatId: 300,
            messageId: 903,
            text: 'Герман',
        ));

        $response->assertOk()->assertExactJson([
            'ok' => true,
        ]);

        Queue::assertPushed(ProcessDataCollectionResponseJob::class);
        Queue::assertNotPushed(ProcessScenarioStartJob::class);
        Queue::assertNotPushed(ProcessAutoReplyJob::class);
        $this->assertDatabaseCount('scenario_runs', 0);
    }

    public function test_non_callback_message_cancels_active_warmup_and_falls_back_to_auto_reply(): void
    {
        $this->fakeTelegramApiSequence(9001);

        $channel = $this->createTelegramChannel();

        AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'keyword' => null,
            'normalized_keyword' => null,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
            'reply_text' => 'Обычный автоответ.',
            'is_active' => true,
        ]);

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => 'warmup',
            'is_active' => true,
        ]);

        $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload(
            messageId: 10,
            text: 'hello',
        ))->assertOk();

        $run = ScenarioRun::query()->where('scenario_code', 'warmup')->firstOrFail();

        $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload(
            messageId: 11,
            text: 'обычное сообщение',
        ))->assertOk();

        $run->refresh();

        $secondInbound = Message::query()
            ->where('direction', Message::DIRECTION_INBOUND)
            ->where('external_message_id', '11')
            ->firstOrFail();

        $autoReply = Message::query()
            ->where('direction', Message::DIRECTION_OUTBOUND)
            ->where('message_kind', Message::KIND_OUTBOUND_AUTO_REPLY)
            ->firstOrFail();

        $this->assertSame(ScenarioRun::STATUS_CANCELLED, $run->status);
        $this->assertSame('interrupted_by_other_message', $run->exit_outcome);
        $this->assertNotNull($run->finished_at);
        $this->assertSame($secondInbound->id, $autoReply->reply_to_message_id);
        $this->assertSame('Обычный автоответ.', $autoReply->text);
        $this->assertDatabaseCount('scenario_runs', 1);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
                && $request['chat_id'] === '300'
                && $request['text'] === config('bots.scenarios.warmup.telegram.text');
        });

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
                && $request['chat_id'] === '300'
                && $request['text'] === 'Обычный автоответ.';
        });
    }

    private function createTelegramChannel(): Channel
    {
        return Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'telegram-secret',
            ],
        ]);
    }

    private function fakeTelegramApiSequence(int $firstMessageId): void
    {
        $sequence = Http::sequence();

        for ($offset = 0; $offset < 20; $offset++) {
            $sequence->push([
                'ok' => true,
                'result' => [
                    'message_id' => $firstMessageId + $offset,
                ],
            ]);
        }

        Http::fake([
            'https://api.telegram.org/*' => $sequence,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function telegramPayload(
        int|string $userId = 200,
        int|string $chatId = 300,
        int|string $messageId = 10,
        ?string $text = 'hello',
        ?string $username = 'telegram_user',
        int $date = 1_711_539_200,
    ): array {
        return [
            'update_id' => $messageId,
            'message' => [
                'message_id' => $messageId,
                'date' => $date,
                'text' => $text,
                'from' => [
                    'id' => $userId,
                    'username' => $username,
                    'is_bot' => false,
                ],
                'chat' => [
                    'id' => $chatId,
                    'type' => 'private',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function telegramCallbackPayload(
        int|string $userId = 200,
        int|string $chatId = 300,
        string $callbackId = 'callback-1',
        string $callbackData = 'scenario:warmup:1:positive',
        int|string $messageId = 10,
        ?string $username = 'telegram_user',
        int $date = 1_711_539_200,
    ): array {
        return [
            'update_id' => $messageId,
            'callback_query' => [
                'id' => $callbackId,
                'data' => $callbackData,
                'from' => [
                    'id' => $userId,
                    'username' => $username,
                    'is_bot' => false,
                ],
                'message' => [
                    'message_id' => $messageId,
                    'date' => $date,
                    'chat' => [
                        'id' => $chatId,
                        'type' => 'private',
                    ],
                ],
            ],
        ];
    }
}
