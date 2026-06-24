<?php

namespace Tests\Feature;

use App\Jobs\ProcessDataCollectionResponseJob;
use App\Models\AutoReplyRule;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Message;
use App\Models\ScenarioChannelBinding;
use App\Models\ScenarioRun;
use App\Services\Scenarios\ScenarioRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WarmupScenarioMaxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('scenarios.warmup.enabled_for_new_starts', true);
        config()->set('bots.legacy_auto_reply_rules_enabled', true);
        app(ScenarioRegistry::class)->forgetCachedDefinitions();
    }

    public function test_warmup_starts_on_first_max_inbound_and_preempts_auto_reply(): void
    {
        Http::fake([
            'https://platform-api.max.ru/*' => Http::response([
                'message' => [
                    'message_id' => 'max-out-9001',
                ],
            ]),
        ]);

        $channel = $this->createMaxChannel();

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
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $this->maxPayload(
            messageId: 'max-10',
            text: 'hello',
        ))->assertOk();

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
        $this->assertSame([
            'positive',
            'later',
            'decline',
        ], data_get($run->state_payload, 'expected_actions'));
        $this->assertSame(config('bots.scenarios.warmup.max.buttons.positive'), data_get($run->state_payload, 'expected_labels.positive'));
        $this->assertSame(Message::SENT_BY_SYSTEM_CODE_SCENARIO_WARMUP, $outboundMessage->sent_by_system_code);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://platform-api.max.ru/messages?chat_id=700'
                && $request['text'] === config('bots.scenarios.warmup.max.text')
                && data_get($request->data(), 'attachments.0.type') === 'inline_keyboard'
                && data_get($request->data(), 'attachments.0.payload.buttons.0.0.type') === 'message'
                && data_get($request->data(), 'attachments.0.payload.buttons.0.0.text') === config('bots.scenarios.warmup.max.buttons.positive');
        });
        $this->assertDatabaseMissing('messages', [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_AUTO_REPLY,
        ]);
    }

    public function test_warmup_does_not_start_without_binding_on_max(): void
    {
        Http::fake();

        $channel = $this->createMaxChannel();

        $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $this->maxPayload(
            messageId: 'max-10',
            text: 'hello',
        ))->assertOk();

        $this->assertDatabaseCount('scenario_runs', 0);
        $this->assertDatabaseMissing('messages', [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_SCENARIO_MESSAGE,
            'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_SCENARIO_WARMUP,
        ]);
    }

    public function test_max_button_text_completes_run(): void
    {
        Http::fake([
            'https://platform-api.max.ru/*' => Http::response([
                'message' => [
                    'message_id' => 'max-out-9001',
                ],
            ]),
        ]);

        $channel = $this->createMaxChannel();

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => 'warmup',
            'is_active' => true,
        ]);

        $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $this->maxPayload(
            messageId: 'max-10',
            text: 'hello',
        ))->assertOk();

        $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $this->maxPayload(
            messageId: 'max-11',
            text: config('bots.scenarios.warmup.max.buttons.later'),
        ))->assertOk();

        $run = ScenarioRun::query()->where('scenario_code', 'warmup')->firstOrFail();
        $reactionMessage = Message::query()
            ->where('direction', Message::DIRECTION_INBOUND)
            ->where('external_message_id', 'max-11')
            ->firstOrFail();

        $this->assertSame(ScenarioRun::STATUS_COMPLETED, $run->status);
        $this->assertSame('reacted_later', $run->exit_outcome);
        $this->assertSame($reactionMessage->id, data_get($run->state_payload, 'reaction_message_id'));
        $this->assertSame('later', data_get($run->state_payload, 'reaction_action'));
        $this->assertSame(config('bots.scenarios.warmup.max.buttons.later'), data_get($run->state_payload, 'reaction_label'));
        $this->assertSame('max_text_match', data_get($run->state_payload, 'reaction_source'));
    }

    public function test_active_collector_keeps_priority_over_max_warmup(): void
    {
        Queue::fake();
        Http::fake();

        $channel = $this->createMaxChannel();
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
            'external_user_id' => '500',
            'external_username' => 'max_user',
        ]);

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => 'warmup',
            'is_active' => true,
        ]);

        $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $this->maxPayload(
            messageId: 'max-10',
            text: 'Герман',
        ))->assertOk();

        Queue::assertPushed(ProcessDataCollectionResponseJob::class);
        $this->assertDatabaseCount('scenario_runs', 0);
        Http::assertNothingSent();
    }

    public function test_non_matching_max_text_cancels_warmup_and_falls_back_to_auto_reply(): void
    {
        $this->fakeMaxApiSequence();

        $channel = $this->createMaxChannel();

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
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $this->maxPayload(
            messageId: 'max-10',
            text: 'hello',
        ))->assertOk();

        $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $this->maxPayload(
            messageId: 'max-11',
            text: 'обычное сообщение',
        ))->assertOk();

        $run = ScenarioRun::query()->where('scenario_code', 'warmup')->firstOrFail();
        $secondInbound = Message::query()
            ->where('direction', Message::DIRECTION_INBOUND)
            ->where('external_message_id', 'max-11')
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
        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://platform-api.max.ru/messages?chat_id=700'
                && $request['text'] === config('bots.scenarios.warmup.max.text');
        });
        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://platform-api.max.ru/messages?chat_id=700'
                && $request['text'] === 'Обычный автоответ.';
        });
    }

    public function test_max_uses_snapshot_labels_instead_of_updated_config(): void
    {
        config()->set('bots.scenarios.warmup.max.buttons.positive', 'Старый текст');

        Http::fake([
            'https://platform-api.max.ru/*' => Http::response([
                'message' => [
                    'message_id' => 'max-out-9001',
                ],
            ]),
        ]);

        $channel = $this->createMaxChannel();

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => 'warmup',
            'is_active' => true,
        ]);

        $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $this->maxPayload(
            messageId: 'max-10',
            text: 'hello',
        ))->assertOk();

        config()->set('bots.scenarios.warmup.max.buttons.positive', 'Новый текст');

        $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $this->maxPayload(
            messageId: 'max-11',
            text: 'Старый текст',
        ))->assertOk();

        $run = ScenarioRun::query()->where('scenario_code', 'warmup')->firstOrFail();

        $this->assertSame(ScenarioRun::STATUS_COMPLETED, $run->status);
        $this->assertSame('positive', data_get($run->state_payload, 'reaction_action'));
        $this->assertSame('Старый текст', data_get($run->state_payload, 'reaction_label'));
    }

    private function createMaxChannel(): Channel
    {
        return Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
                'webhook_secret' => 'max-secret',
            ],
        ]);
    }

    private function fakeMaxApiSequence(): void
    {
        $sequence = Http::sequence();

        for ($offset = 1; $offset <= 20; $offset++) {
            $sequence->push([
                'message' => [
                    'message_id' => 'max-out-'.$offset,
                ],
            ]);
        }

        Http::fake([
            'https://platform-api.max.ru/*' => $sequence,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function maxPayload(
        int|string $userId = 500,
        int|string $chatId = 700,
        int|string $messageId = 'max-10',
        ?string $text = 'hello',
        ?string $username = 'max_user',
        string $timestamp = '2026-03-27T12:00:00+03:00',
    ): array {
        return [
            'update_type' => 'message_created',
            'user_locale' => 'ru',
            'timestamp' => $timestamp,
            'message' => [
                'message_id' => $messageId,
                'timestamp' => $timestamp,
                'sender' => [
                    'user_id' => $userId,
                    'username' => $username,
                    'is_bot' => false,
                ],
                'recipient' => [
                    'chat_id' => $chatId,
                ],
                'body' => [
                    'text' => $text,
                ],
            ],
        ];
    }
}
