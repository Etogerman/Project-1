<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Dialog;
use App\Models\Message;
use App\Models\ScenarioChannelBinding;
use App\Models\ScenarioRun;
use App\Services\Scenarios\NeedsDiscoveryScenario;
use App\Services\Scenarios\WarmupScenario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NeedsDiscoveryScenarioTest extends TestCase
{
    use RefreshDatabase;

    public function test_needs_discovery_does_not_start_before_collector_is_completed(): void
    {
        Http::fake();

        $channel = $this->createTelegramChannel();

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => NeedsDiscoveryScenario::code(),
            'is_active' => true,
        ]);

        $this->createRoute(
            $channel,
            externalUserId: '200',
            externalChatId: '300',
            contactOverrides: [
                'data_collection_status' => null,
                'data_collection_completed_at' => null,
            ],
        );

        $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload(
            userId: 200,
            chatId: 300,
            messageId: 10,
            text: 'hello',
        ))->assertOk();

        $this->assertDatabaseCount('scenario_runs', 0);
        Http::assertNothingSent();
    }

    public function test_needs_discovery_does_not_start_while_warmup_has_priority(): void
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
            'scenario_code' => WarmupScenario::code(),
            'is_active' => true,
        ]);
        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => NeedsDiscoveryScenario::code(),
            'is_active' => true,
        ]);

        $this->createRoute($channel, externalUserId: '200', externalChatId: '300');

        $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload(
            userId: 200,
            chatId: 300,
            messageId: 10,
            text: 'hello',
        ))->assertOk();

        $this->assertDatabaseHas('scenario_runs', [
            'scenario_code' => WarmupScenario::code(),
            'status' => ScenarioRun::STATUS_ACTIVE,
        ]);
        $this->assertDatabaseMissing('scenario_runs', [
            'scenario_code' => NeedsDiscoveryScenario::code(),
        ]);
    }

    public function test_needs_discovery_starts_after_terminal_warmup_run(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 9101,
                ],
            ]),
        ]);

        $channel = $this->createTelegramChannel();

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => WarmupScenario::code(),
            'is_active' => true,
        ]);
        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => NeedsDiscoveryScenario::code(),
            'is_active' => true,
        ]);

        [, , $dialog] = $this->createRoute($channel, externalUserId: '200', externalChatId: '300');

        ScenarioRun::query()->create([
            'dialog_id' => $dialog->id,
            'scenario_code' => WarmupScenario::code(),
            'status' => ScenarioRun::STATUS_COMPLETED,
            'current_step' => null,
            'state_payload' => [],
            'exit_outcome' => 'reacted_positive',
            'started_at' => now()->subMinute(),
            'finished_at' => now()->subSeconds(30),
        ]);

        $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload(
            userId: 200,
            chatId: 300,
            messageId: 11,
            text: 'следующий шаг',
        ))->assertOk();

        $run = ScenarioRun::query()
            ->where('scenario_code', NeedsDiscoveryScenario::code())
            ->firstOrFail();
        $outboundMessage = Message::query()
            ->where('direction', Message::DIRECTION_OUTBOUND)
            ->where('sent_by_system_code', Message::SENT_BY_SYSTEM_CODE_SCENARIO_NEEDS_DISCOVERY)
            ->firstOrFail();

        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame(NeedsDiscoveryScenario::STEP_PRIMARY_GOAL, $run->current_step);
        $this->assertSame($outboundMessage->id, data_get($run->state_payload, 'question_message_ids.primary_goal'));
        $this->assertSame(config('bots.scenarios.needs_discovery.primary_goal.question'), $outboundMessage->text);
    }

    public function test_telegram_happy_path_completes_needs_discovery_with_answers(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push([
                    'ok' => true,
                    'result' => ['message_id' => 9201],
                ])
                ->push([
                    'ok' => true,
                    'result' => ['message_id' => 9202],
                ])
                ->push([
                    'ok' => true,
                    'result' => ['message_id' => 9203],
                ]),
        ]);

        $channel = $this->createTelegramChannel();

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => NeedsDiscoveryScenario::code(),
            'is_active' => true,
        ]);

        $this->createRoute($channel, externalUserId: '200', externalChatId: '300');

        $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload(
            userId: 200,
            chatId: 300,
            messageId: 20,
            text: 'хочу продолжить',
        ))->assertOk();

        $run = ScenarioRun::query()->where('scenario_code', NeedsDiscoveryScenario::code())->firstOrFail();
        $this->assertSame(NeedsDiscoveryScenario::STEP_PRIMARY_GOAL, $run->current_step);

        $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload(
            userId: 200,
            chatId: 300,
            messageId: 21,
            text: 'Найти больше клиентов',
        ))->assertOk();

        $run->refresh();

        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame(NeedsDiscoveryScenario::STEP_MAIN_BLOCKER, $run->current_step);
        $this->assertSame('Найти больше клиентов', data_get($run->state_payload, 'answers.primary_goal.text'));
        $this->assertFalse(data_get($run->state_payload, 'answers.primary_goal.skipped'));

        $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload(
            userId: 200,
            chatId: 300,
            messageId: 22,
            text: 'Не хватает времени на обработку',
        ))->assertOk();

        $run->refresh();

        $outboundMessages = Message::query()
            ->where('direction', Message::DIRECTION_OUTBOUND)
            ->where('sent_by_system_code', Message::SENT_BY_SYSTEM_CODE_SCENARIO_NEEDS_DISCOVERY)
            ->orderBy('id')
            ->get();

        $this->assertSame(ScenarioRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(NeedsDiscoveryScenario::OUTCOME_COMPLETED_WITH_ANSWERS, $run->exit_outcome);
        $this->assertNull($run->current_step);
        $this->assertCount(3, $outboundMessages);
        $this->assertSame(config('bots.scenarios.needs_discovery.primary_goal.question'), $outboundMessages[0]->text);
        $this->assertSame(config('bots.scenarios.needs_discovery.main_blocker.question'), $outboundMessages[1]->text);
        $this->assertSame(config('bots.scenarios.needs_discovery.completion_message'), $outboundMessages[2]->text);
        $this->assertSame('Не хватает времени на обработку', data_get($run->state_payload, 'answers.main_blocker.text'));
        $this->assertFalse(data_get($run->state_payload, 'answers.main_blocker.skipped'));
        $this->assertSame($outboundMessages[2]->id, data_get($run->state_payload, 'completion_message_id'));
    }

    public function test_needs_discovery_replays_blocked_follow_up_question_after_unblock_before_accepting_next_answer(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push([
                    'ok' => true,
                    'result' => ['message_id' => 9211],
                ])
                ->push([
                    'ok' => true,
                    'result' => ['message_id' => 9212],
                ]),
        ]);

        $channel = $this->createTelegramChannel();

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => NeedsDiscoveryScenario::code(),
            'is_active' => true,
        ]);

        [, , $dialog] = $this->createRoute($channel, externalUserId: '200', externalChatId: '300');

        $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload(
            userId: 200,
            chatId: 300,
            messageId: 40,
            text: 'хочу продолжить',
        ))->assertOk();

        $dialog->forceFill([
            'bot_subscription_status' => Dialog::BOT_SUBSCRIPTION_STATUS_BLOCKED_BY_USER,
            'bot_subscription_changed_at' => now(),
        ])->save();

        $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload(
            userId: 200,
            chatId: 300,
            messageId: 41,
            text: 'Найти больше клиентов',
        ))->assertOk();

        $run = ScenarioRun::query()->where('scenario_code', NeedsDiscoveryScenario::code())->firstOrFail();

        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame(NeedsDiscoveryScenario::STEP_MAIN_BLOCKER, $run->current_step);
        $this->assertSame('Найти больше клиентов', data_get($run->state_payload, 'answers.primary_goal.text'));
        $this->assertNull(data_get($run->state_payload, 'question_message_ids.main_blocker'));
        $this->assertTrue((bool) data_get($run->state_payload, 'run.pending_delivery_active'));
        $this->assertSame(NeedsDiscoveryScenario::STEP_MAIN_BLOCKER, data_get($run->state_payload, 'run.pending_delivery_step'));
        $this->assertSame('question', data_get($run->state_payload, 'run.pending_delivery_type'));

        Http::assertSentCount(1);

        $dialog->forceFill([
            'bot_subscription_status' => null,
            'bot_subscription_changed_at' => now()->addSecond(),
        ])->save();

        $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload(
            userId: 200,
            chatId: 300,
            messageId: 42,
            text: 'первое после unblock',
        ))->assertOk();

        $run->refresh();

        $outboundMessages = Message::query()
            ->where('direction', Message::DIRECTION_OUTBOUND)
            ->where('sent_by_system_code', Message::SENT_BY_SYSTEM_CODE_SCENARIO_NEEDS_DISCOVERY)
            ->orderBy('id')
            ->get();

        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame(NeedsDiscoveryScenario::STEP_MAIN_BLOCKER, $run->current_step);
        $this->assertSame(2, $outboundMessages->count());
        $this->assertSame(config('bots.scenarios.needs_discovery.main_blocker.question'), $outboundMessages[1]->text);
        $this->assertSame($outboundMessages[1]->id, data_get($run->state_payload, 'question_message_ids.main_blocker'));
        $this->assertNull(data_get($run->state_payload, 'answers.main_blocker.text'));
        $this->assertNull(data_get($run->state_payload, 'answers.main_blocker.message_id'));
        $this->assertFalse((bool) data_get($run->state_payload, 'run.pending_delivery_active'));
    }

    public function test_needs_discovery_replays_blocked_completion_after_unblock_before_finishing_run(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push([
                    'ok' => true,
                    'result' => ['message_id' => 9221],
                ])
                ->push([
                    'ok' => true,
                    'result' => ['message_id' => 9222],
                ])
                ->push([
                    'ok' => true,
                    'result' => ['message_id' => 9223],
                ]),
        ]);

        $channel = $this->createTelegramChannel();

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => NeedsDiscoveryScenario::code(),
            'is_active' => true,
        ]);

        [, , $dialog] = $this->createRoute($channel, externalUserId: '200', externalChatId: '300');

        $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload(
            userId: 200,
            chatId: 300,
            messageId: 50,
            text: 'хочу продолжить',
        ))->assertOk();

        $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload(
            userId: 200,
            chatId: 300,
            messageId: 51,
            text: 'Найти больше клиентов',
        ))->assertOk();

        $dialog->forceFill([
            'bot_subscription_status' => Dialog::BOT_SUBSCRIPTION_STATUS_BLOCKED_BY_USER,
            'bot_subscription_changed_at' => now(),
        ])->save();

        $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload(
            userId: 200,
            chatId: 300,
            messageId: 52,
            text: 'Не хватает времени на обработку',
        ))->assertOk();

        $run = ScenarioRun::query()->where('scenario_code', NeedsDiscoveryScenario::code())->firstOrFail();

        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame(NeedsDiscoveryScenario::STEP_MAIN_BLOCKER, $run->current_step);
        $this->assertSame('Не хватает времени на обработку', data_get($run->state_payload, 'answers.main_blocker.text'));
        $this->assertNull(data_get($run->state_payload, 'completion_message_id'));
        $this->assertTrue((bool) data_get($run->state_payload, 'run.pending_delivery_active'));
        $this->assertSame('completion', data_get($run->state_payload, 'run.pending_delivery_type'));

        $dialog->forceFill([
            'bot_subscription_status' => null,
            'bot_subscription_changed_at' => now()->addSecond(),
        ])->save();

        $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload(
            userId: 200,
            chatId: 300,
            messageId: 53,
            text: 'первое после unblock',
        ))->assertOk();

        $run->refresh();

        $outboundMessages = Message::query()
            ->where('direction', Message::DIRECTION_OUTBOUND)
            ->where('sent_by_system_code', Message::SENT_BY_SYSTEM_CODE_SCENARIO_NEEDS_DISCOVERY)
            ->orderBy('id')
            ->get();

        $this->assertSame(ScenarioRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(NeedsDiscoveryScenario::OUTCOME_COMPLETED_WITH_ANSWERS, $run->exit_outcome);
        $this->assertNull($run->current_step);
        $this->assertSame(3, $outboundMessages->count());
        $this->assertSame(config('bots.scenarios.needs_discovery.completion_message'), $outboundMessages[2]->text);
        $this->assertSame($outboundMessages[2]->id, data_get($run->state_payload, 'completion_message_id'));
        $this->assertFalse((bool) data_get($run->state_payload, 'run.pending_delivery_active'));
    }

    public function test_max_happy_path_supports_skip_and_completes_with_partial_answers(): void
    {
        Http::fake([
            'https://platform-api.max.ru/*' => Http::sequence()
                ->push([
                    'message' => ['message_id' => 'max-out-1'],
                ])
                ->push([
                    'message' => ['message_id' => 'max-out-2'],
                ])
                ->push([
                    'message' => ['message_id' => 'max-out-3'],
                ]),
        ]);

        $channel = $this->createMaxChannel();

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => NeedsDiscoveryScenario::code(),
            'is_active' => true,
        ]);

        $this->createRoute($channel, externalUserId: '500', externalChatId: '700');

        $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $this->maxPayload(
            messageId: 'max-20',
            text: 'готов',
        ))->assertOk();

        $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $this->maxPayload(
            messageId: 'max-21',
            text: '  SKIP  ',
        ))->assertOk();

        $this->withHeaders([
            'X-Max-Bot-Api-Secret' => 'max-secret',
        ])->postJson("/webhooks/max/{$channel->id}", $this->maxPayload(
            messageId: 'max-22',
            text: 'Долго согласовываем действия',
        ))->assertOk();

        $run = ScenarioRun::query()->where('scenario_code', NeedsDiscoveryScenario::code())->firstOrFail();

        $this->assertSame(ScenarioRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(NeedsDiscoveryScenario::OUTCOME_COMPLETED_WITH_PARTIAL_ANSWERS, $run->exit_outcome);
        $this->assertTrue(data_get($run->state_payload, 'answers.primary_goal.skipped'));
        $this->assertNull(data_get($run->state_payload, 'answers.primary_goal.text'));
        $this->assertSame('Долго согласовываем действия', data_get($run->state_payload, 'answers.main_blocker.text'));
        $this->assertFalse(data_get($run->state_payload, 'answers.main_blocker.skipped'));
    }

    public function test_double_skip_completes_with_completed_skipped_outcome_and_does_not_restart(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push([
                    'ok' => true,
                    'result' => ['message_id' => 9301],
                ])
                ->push([
                    'ok' => true,
                    'result' => ['message_id' => 9302],
                ])
                ->push([
                    'ok' => true,
                    'result' => ['message_id' => 9303],
                ]),
        ]);

        $channel = $this->createTelegramChannel();

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => NeedsDiscoveryScenario::code(),
            'is_active' => true,
        ]);

        $this->createRoute($channel, externalUserId: '200', externalChatId: '300');

        $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload(
            userId: 200,
            chatId: 300,
            messageId: 30,
            text: 'поехали',
        ))->assertOk();

        $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload(
            userId: 200,
            chatId: 300,
            messageId: 31,
            text: '  ПрОпУсТиТь  ',
        ))->assertOk();

        $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload(
            userId: 200,
            chatId: 300,
            messageId: 32,
            text: ' skip ',
        ))->assertOk();

        $run = ScenarioRun::query()->where('scenario_code', NeedsDiscoveryScenario::code())->firstOrFail();

        $this->assertSame(NeedsDiscoveryScenario::OUTCOME_COMPLETED_SKIPPED, $run->exit_outcome);
        $this->assertTrue(data_get($run->state_payload, 'answers.primary_goal.skipped'));
        $this->assertTrue(data_get($run->state_payload, 'answers.main_blocker.skipped'));

        $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
        ])->postJson("/webhooks/telegram/{$channel->id}", $this->telegramPayload(
            userId: 200,
            chatId: 300,
            messageId: 33,
            text: 'ещё сообщение',
        ))->assertOk();

        $this->assertDatabaseCount('scenario_runs', 1);
        Http::assertSentCount(3);
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

    /**
     * @param  array<string, mixed>  $contactOverrides
     * @return array{0: Contact, 1: ContactIdentity, 2: Dialog}
     */
    private function createRoute(
        Channel $channel,
        string $externalUserId,
        string $externalChatId,
        array $contactOverrides = [],
    ): array {
        $contact = Contact::factory()->create(array_merge([
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_COMPLETED,
            'data_collection_completed_at' => now(),
            'is_auto_reply_enabled' => true,
        ], $contactOverrides));

        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => $externalUserId,
            'external_username' => $channel->platform === Channel::PLATFORM_TELEGRAM ? 'telegram_user' : 'max_user',
        ]);

        $dialog = Dialog::factory()->create([
            'current_contact_identity_id' => $identity->id,
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'external_chat_id' => $externalChatId,
        ]);

        return [$contact, $identity, $dialog];
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
