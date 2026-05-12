<?php

namespace Tests\Feature;

use App\Filament\Pages\BotConstructor;
use App\Jobs\ProcessAutoReplyJob;
use App\Jobs\ProcessBotConstructorScheduledArrowJob;
use App\Models\AutoReplyRule;
use App\Models\BotConstructorArrow;
use App\Models\BotConstructorArrowRun;
use App\Models\BotConstructorBlock;
use App\Models\BotConstructorBlockRun;
use App\Models\BotConstructorExecution;
use App\Models\BotConstructorExecutionBlockRun;
use App\Models\Channel;
use App\Models\ChannelActivityLog;
use App\Models\ChannelRuntimeState;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Dialog;
use App\Models\Message;
use App\Models\TelegramAccountOutgoingMessage;
use App\Models\User;
use App\Services\Bots\ProcessBotConstructorBlocksAction;
use App\Services\Bots\SendBotDialogTextAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class BotConstructorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    public function test_constructor_is_available_only_for_admins(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
            'role' => User::ROLE_EMPLOYEE,
        ]);

        $this->actingAs($admin)
            ->get(BotConstructor::getUrl())
            ->assertOk()
            ->assertSee('Стартовые условия');

        $this->actingAs($employee)
            ->get(BotConstructor::getUrl())
            ->assertRedirect('/admin/login');
    }

    public function test_admin_can_create_and_save_green_start_block(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = $this->readyTelegramChannel();

        Livewire::actingAs($admin)
            ->test(BotConstructor::class)
            ->call('addBlock')
            ->assertSet('draftIsActive', false)
            ->set('draftTitle', 'Приветствие')
            ->set('draftIsActive', true)
            ->set('draftChannelIds', [$channel->id])
            ->set('draftMatchType', BotConstructorBlock::MATCH_TYPE_CONTAINS_TEXT)
            ->set('draftMatchValuesInput', 'Привет; привет ; Здравствуйте')
            ->set('draftResponseText', 'Здравствуйте!')
            ->call('saveBlock')
            ->assertHasNoErrors();

        $block = BotConstructorBlock::query()->firstOrFail();

        $this->assertSame('Приветствие', $block->title);
        $this->assertTrue($block->is_active);
        $this->assertSame(BotConstructorBlock::MATCH_TYPE_CONTAINS_TEXT, $block->match_type);
        $this->assertSame(['привет', 'здравствуйте'], $block->match_values);
        $this->assertSame([$channel->id], $block->channels()->pluck('channels.id')->all());
    }

    public function test_active_block_rejects_channel_that_is_not_ready_in_channel_settings(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create([
            'credentials' => [
                'token' => 'telegram-token',
            ],
            'last_webhook_received_at' => null,
            'last_reply_sent_at' => null,
            'last_error_at' => null,
        ]);

        Livewire::actingAs($admin)
            ->test(BotConstructor::class)
            ->call('addBlock')
            ->set('draftIsActive', true)
            ->set('draftChannelIds', [$channel->id])
            ->set('draftMatchType', BotConstructorBlock::MATCH_TYPE_EXACT_KEYWORD)
            ->set('draftMatchValuesInput', 'привет')
            ->set('draftResponseText', 'Ответ')
            ->call('saveBlock')
            ->assertHasErrors(['draftChannelIds']);
    }

    public function test_active_block_accepts_ready_account_channel_for_constructor_gateway_delivery(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->account()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'is_active' => true,
        ]);
        ChannelRuntimeState::query()->create([
            'channel_id' => $channel->id,
            'auth_status' => ChannelRuntimeState::AUTH_STATUS_AUTHORIZED,
            'authorization_state' => ChannelRuntimeState::AUTHORIZATION_STATE_READY,
            'sync_status' => ChannelRuntimeState::SYNC_STATUS_LIVE,
            'runtime_payload' => [
                'gateway_capabilities' => [
                    'outgoing_replies' => true,
                ],
            ],
        ]);

        Livewire::actingAs($admin)
            ->test(BotConstructor::class)
            ->call('addBlock')
            ->set('draftIsActive', true)
            ->set('draftChannelIds', [$channel->id])
            ->set('draftMatchType', BotConstructorBlock::MATCH_TYPE_EXACT_KEYWORD)
            ->set('draftMatchValuesInput', 'привет')
            ->set('draftResponseText', 'Ответ')
            ->call('saveBlock')
            ->assertHasNoErrors();

        $block = BotConstructorBlock::query()->firstOrFail();

        $this->assertTrue($block->is_active);
        $this->assertSame([$channel->id], $block->channels()->pluck('channels.id')->all());
    }

    public function test_inactive_draft_can_select_and_save_channel_that_is_not_ready_yet(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create([
            'credentials' => [
                'token' => 'telegram-token',
            ],
            'last_webhook_received_at' => null,
            'last_reply_sent_at' => null,
            'last_error_at' => null,
        ]);

        Livewire::actingAs($admin)
            ->test(BotConstructor::class)
            ->call('addBlock')
            ->assertSeeHtml('value="'.$channel->id.'"')
            ->assertDontSeeHtml('value="'.$channel->id.'" disabled')
            ->set('draftChannelIds', [$channel->id])
            ->set('draftMatchType', BotConstructorBlock::MATCH_TYPE_EXACT_KEYWORD)
            ->set('draftMatchValuesInput', 'привет')
            ->set('draftResponseText', 'Ответ')
            ->call('saveBlock')
            ->assertHasNoErrors();

        $block = BotConstructorBlock::query()->firstOrFail();

        $this->assertFalse($block->is_active);
        $this->assertSame([$channel->id], $block->channels()->pluck('channels.id')->all());
    }

    public function test_moved_selected_block_keeps_new_coordinates_when_saved_later(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $block = BotConstructorBlock::factory()->create([
            'x' => 64,
            'y' => 64,
        ]);

        Livewire::actingAs($admin)
            ->test(BotConstructor::class)
            ->call('selectBlock', $block->id)
            ->call('moveBlock', $block->id, 320, 240)
            ->set('draftTitle', 'После движения')
            ->call('saveBlock')
            ->assertHasNoErrors();

        $block->refresh();

        $this->assertSame(320, $block->x);
        $this->assertSame(240, $block->y);
        $this->assertSame('После движения', $block->title);
    }

    public function test_constructor_does_not_crash_when_channel_credentials_cannot_be_decrypted(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = $this->readyTelegramChannel();

        DB::table('channels')
            ->where('id', $channel->id)
            ->update(['credentials' => 'broken-encrypted-value']);

        $component = Livewire::actingAs($admin)
            ->test(BotConstructor::class);

        $options = $component->instance()->channelOptions();

        $this->assertCount(1, $options);
        $this->assertSame('Ошибка настроек', $options[0]['status']);
        $this->assertStringContainsString('Ошибка настроек', $options[0]['label']);
        $this->assertFalse($options[0]['is_ready']);

        $this->assertFalse($channel->fresh()->isReadyForConstructorAutoReplies());
    }

    public function test_matching_legacy_rules_send_first_then_green_blocks_in_block_id_order(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push([
                    'ok' => true,
                    'result' => ['message_id' => 1001],
                ])
                ->push([
                    'ok' => true,
                    'result' => ['message_id' => 1002],
                ])
                ->push([
                    'ok' => true,
                    'result' => ['message_id' => 1003],
                ]),
        ]);

        $channel = $this->readyTelegramChannel();
        $firstBlock = BotConstructorBlock::factory()->active()->create([
            'match_type' => BotConstructorBlock::MATCH_TYPE_EXACT_KEYWORD,
            'match_values' => ['привет'],
            'response_text' => 'Первый ответ',
        ]);
        $secondBlock = BotConstructorBlock::factory()->active()->create([
            'match_type' => BotConstructorBlock::MATCH_TYPE_CONTAINS_TEXT,
            'match_values' => ['при'],
            'response_text' => 'Второй ответ',
        ]);
        $firstBlock->channels()->attach($channel->id);
        $secondBlock->channels()->attach($channel->id);

        AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
            'keyword' => null,
            'normalized_keyword' => null,
            'reply_text' => 'Старый автоответ',
            'is_active' => true,
        ]);

        $message = $this->createInboundMessage($channel, [
            'text' => 'Привет',
            'provider_event_key' => 'constructor-green-order',
        ]);

        ProcessAutoReplyJob::dispatchSync($message->id);

        Http::assertSentCount(3);

        $this->assertSame(
            ['Старый автоответ', 'Первый ответ', 'Второй ответ'],
            Message::query()
                ->where('direction', Message::DIRECTION_OUTBOUND)
                ->where('reply_to_message_id', $message->id)
                ->orderBy('id')
                ->pluck('text')
                ->all(),
        );
        $this->assertDatabaseHas('bot_constructor_block_runs', [
            'inbound_message_id' => $message->id,
            'bot_constructor_block_id' => $firstBlock->id,
            'status' => BotConstructorBlockRun::STATUS_SENT,
        ]);
        $this->assertDatabaseHas('bot_constructor_block_runs', [
            'inbound_message_id' => $message->id,
            'bot_constructor_block_id' => $secondBlock->id,
            'status' => BotConstructorBlockRun::STATUS_SENT,
        ]);
        $this->assertDatabaseHas('messages', [
            'reply_to_message_id' => $message->id,
            'text' => 'Старый автоответ',
        ]);

        $execution = BotConstructorExecution::query()->firstOrFail();

        $this->assertSame(BotConstructorExecution::STATUS_COMPLETED, $execution->status);
        $this->assertSame(BotConstructorExecution::TRIGGER_INBOUND, $execution->trigger_type);
        $this->assertSame($message->id, $execution->root_inbound_message_id);
        $this->assertSame($message->dialog_id, $execution->dialog_id);
        $this->assertSame(3, $execution->next_sequence_number);
        $this->assertSame(
            [
                [$firstBlock->id, BotConstructorExecutionBlockRun::STATUS_SENT, 1],
                [$secondBlock->id, BotConstructorExecutionBlockRun::STATUS_SENT, 2],
            ],
            BotConstructorExecutionBlockRun::query()
                ->orderBy('sequence_number')
                ->get(['bot_constructor_block_id', 'status', 'sequence_number'])
                ->map(fn (BotConstructorExecutionBlockRun $run): array => [
                    $run->bot_constructor_block_id,
                    $run->status,
                    $run->sequence_number,
                ])
                ->all(),
        );
        $this->assertDatabaseHas('bot_constructor_dialog_states', [
            'dialog_id' => $message->dialog_id,
            'current_block_id' => $secondBlock->id,
            'last_execution_id' => $execution->id,
        ]);
    }

    public function test_constructor_queues_telegram_account_gateway_reply_without_bot_api_request(): void
    {
        Http::fake();

        $channel = $this->readyTelegramAccountChannel();
        $block = BotConstructorBlock::factory()->active()->create([
            'match_type' => BotConstructorBlock::MATCH_TYPE_EXACT_KEYWORD,
            'match_values' => ['текст1'],
            'response_text' => 'Конструктор текст1',
        ]);
        $block->channels()->attach($channel->id);
        $message = $this->createInboundDialogMessage($channel, [
            'text' => 'текст1',
            'provider_event_key' => 'constructor-account-gateway',
        ]);

        ProcessAutoReplyJob::dispatchSync($message->id);

        Http::assertNothingSent();

        $outgoing = TelegramAccountOutgoingMessage::query()->firstOrFail();
        $outboundMessage = Message::query()
            ->where('reply_to_message_id', $message->id)
            ->where('sent_by_system_code', Message::SENT_BY_SYSTEM_CODE_BOT_CONSTRUCTOR_BLOCK)
            ->firstOrFail();

        $this->assertSame(TelegramAccountOutgoingMessage::STATUS_PENDING, $outgoing->status);
        $this->assertSame($outboundMessage->id, $outgoing->message_id);
        $this->assertSame('Конструктор текст1', $outgoing->text);
        $this->assertSame('telegram_account_gateway', data_get($outboundMessage->raw_payload, 'provider'));
        $this->assertSame(TelegramAccountOutgoingMessage::STATUS_PENDING, data_get($outboundMessage->raw_payload, 'delivery_status'));
        $this->assertNull($channel->fresh()->last_reply_sent_at);
        $this->assertDatabaseHas('bot_constructor_block_runs', [
            'inbound_message_id' => $message->id,
            'bot_constructor_block_id' => $block->id,
            'outbound_message_id' => $outboundMessage->id,
            'status' => BotConstructorBlockRun::STATUS_SENT,
        ]);
    }

    public function test_account_auto_reply_runs_before_constructor_and_both_are_queued_for_gateway(): void
    {
        Http::fake();

        $channel = $this->readyTelegramAccountChannel();
        AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
            'keyword' => null,
            'normalized_keyword' => null,
            'reply_text' => 'Автоответ текст1',
            'is_active' => true,
        ]);
        $block = BotConstructorBlock::factory()->active()->create([
            'match_type' => BotConstructorBlock::MATCH_TYPE_EXACT_KEYWORD,
            'match_values' => ['текст1'],
            'response_text' => 'Конструктор текст1',
        ]);
        $block->channels()->attach($channel->id);
        $message = $this->createInboundDialogMessage($channel, [
            'text' => 'текст1',
            'provider_event_key' => 'account-auto-and-constructor',
        ]);

        ProcessAutoReplyJob::dispatchSync($message->id);

        Http::assertNothingSent();

        $this->assertSame(
            ['Автоответ текст1', 'Конструктор текст1'],
            Message::query()
                ->where('direction', Message::DIRECTION_OUTBOUND)
                ->where('reply_to_message_id', $message->id)
                ->orderBy('id')
                ->pluck('text')
                ->all(),
        );
        $this->assertSame(
            ['Автоответ текст1', 'Конструктор текст1'],
            TelegramAccountOutgoingMessage::query()
                ->orderBy('id')
                ->pluck('text')
                ->all(),
        );
        $this->assertDatabaseHas('bot_constructor_block_runs', [
            'inbound_message_id' => $message->id,
            'bot_constructor_block_id' => $block->id,
            'status' => BotConstructorBlockRun::STATUS_SENT,
        ]);
    }

    public function test_constructor_runs_after_partial_auto_reply_retry_completes_remaining_rules(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push([
                    'ok' => true,
                    'result' => ['message_id' => 101],
                ])
                ->push([
                    'ok' => false,
                ], 500)
                ->push([
                    'ok' => true,
                    'result' => ['message_id' => 102],
                ])
                ->push([
                    'ok' => true,
                    'result' => ['message_id' => 103],
                ]),
        ]);

        $channel = $this->readyTelegramChannel();

        AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
            'keyword' => null,
            'normalized_keyword' => null,
            'reply_text' => 'Первый автоответ',
            'priority' => 5,
            'is_active' => true,
        ]);
        $secondRule = AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD,
            'keyword' => 'Мульти',
            'normalized_keyword' => AutoReplyRule::normalizeKeyword('Мульти'),
            'reply_text' => 'Второй автоответ',
            'priority' => 20,
            'is_active' => true,
        ]);
        $block = BotConstructorBlock::factory()->active()->create([
            'match_type' => BotConstructorBlock::MATCH_TYPE_EXACT_KEYWORD,
            'match_values' => ['мульти'],
            'response_text' => 'Конструктор после retry',
        ]);
        $block->channels()->attach($channel->id);

        $message = $this->createInboundMessage($channel, [
            'text' => 'мульти',
            'provider_event_key' => 'constructor-after-partial-retry',
        ]);

        try {
            ProcessAutoReplyJob::dispatchSync($message->id);
            $this->fail('Expected first attempt to fail on the second matched rule.');
        } catch (\Throwable) {
        }

        ProcessAutoReplyJob::dispatchSync($message->id);
        ProcessAutoReplyJob::dispatchSync($message->id);

        Http::assertSentCount(4);

        $this->assertSame(
            ['Первый автоответ', 'Второй автоответ', 'Конструктор после retry'],
            Message::query()
                ->where('direction', Message::DIRECTION_OUTBOUND)
                ->where('reply_to_message_id', $message->id)
                ->orderBy('id')
                ->pluck('text')
                ->all(),
        );
        $this->assertDatabaseHas('bot_constructor_block_runs', [
            'inbound_message_id' => $message->id,
            'bot_constructor_block_id' => $block->id,
            'status' => BotConstructorBlockRun::STATUS_SENT,
        ]);

        $failedLog = $channel->activityLogs()
            ->where('event', 'bot.reply_failed')
            ->latest('id')
            ->firstOrFail();
        $resumeCompletedLog = $channel->activityLogs()
            ->where('event', 'bot.reply_resume_completed')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame([$secondRule->id], $failedLog->context['remaining_rule_ids']);
        $this->assertSame($failedLog->id, $resumeCompletedLog->context['resume_failure_log_id']);
    }

    public function test_none_reply_is_recorded_once_and_does_not_send_duplicate_on_retry(): void
    {
        Http::fake();

        $channel = $this->readyTelegramChannel();
        $block = BotConstructorBlock::factory()->active()->create([
            'match_type' => BotConstructorBlock::MATCH_TYPE_ANY_INBOUND,
            'match_values' => [],
            'response_text' => '#{none}',
        ]);
        $block->channels()->attach($channel->id);
        $message = $this->createInboundMessage($channel, [
            'provider_event_key' => 'constructor-none-retry',
        ]);

        ProcessAutoReplyJob::dispatchSync($message->id);
        ProcessAutoReplyJob::dispatchSync($message->id);

        Http::assertNothingSent();
        $this->assertDatabaseCount('bot_constructor_block_runs', 1);
        $this->assertDatabaseCount('bot_constructor_executions', 1);
        $this->assertDatabaseCount('bot_constructor_execution_block_runs', 1);
        $this->assertDatabaseHas('bot_constructor_block_runs', [
            'inbound_message_id' => $message->id,
            'bot_constructor_block_id' => $block->id,
            'status' => BotConstructorBlockRun::STATUS_NO_REPLY,
        ]);
        $this->assertDatabaseHas('bot_constructor_execution_block_runs', [
            'bot_constructor_block_id' => $block->id,
            'status' => BotConstructorExecutionBlockRun::STATUS_NO_REPLY,
            'sequence_number' => 1,
        ]);
        $this->assertDatabaseHas('bot_constructor_dialog_states', [
            'dialog_id' => $message->dialog_id,
            'current_block_id' => $block->id,
        ]);
        $this->assertSame(1, Message::query()->count());
    }

    public function test_existing_legacy_run_without_execution_trace_is_recovered_without_duplicate_send(): void
    {
        Http::fake();

        $channel = $this->readyTelegramChannel();
        $block = BotConstructorBlock::factory()->active()->create([
            'match_type' => BotConstructorBlock::MATCH_TYPE_EXACT_KEYWORD,
            'match_values' => ['привет'],
            'response_text' => 'Ответ после recovery',
        ]);
        $block->channels()->attach($channel->id);
        $message = $this->createInboundMessage($channel, [
            'text' => 'привет',
            'provider_event_key' => 'constructor-partial-recovery',
        ]);
        $oldOutboundMessage = Message::factory()->create([
            'dialog_id' => $message->dialog_id,
            'contact_id' => $message->contact_id,
            'contact_identity_id' => $message->contact_identity_id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_AUTO_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_SYSTEM,
            'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_BOT_CONSTRUCTOR_BLOCK,
            'reply_to_message_id' => $message->id,
            'provider_event_key' => null,
            'external_chat_id' => $message->external_chat_id,
            'external_message_id' => 'already-sent',
            'text' => 'Ответ после recovery',
        ]);
        BotConstructorBlockRun::query()->create([
            'inbound_message_id' => $message->id,
            'bot_constructor_block_id' => $block->id,
            'outbound_message_id' => $oldOutboundMessage->id,
            'status' => BotConstructorBlockRun::STATUS_SENT,
            'error_message' => null,
        ]);

        app(ProcessBotConstructorBlocksAction::class)->handle($message);
        app(ProcessBotConstructorBlocksAction::class)->handle($message);

        Http::assertNothingSent();
        $this->assertDatabaseCount('bot_constructor_block_runs', 1);
        $this->assertDatabaseCount('bot_constructor_executions', 1);
        $this->assertDatabaseHas('bot_constructor_execution_block_runs', [
            'bot_constructor_block_id' => $block->id,
            'outbound_message_id' => $oldOutboundMessage->id,
            'status' => BotConstructorExecutionBlockRun::STATUS_DELIVERY_UNCERTAIN,
            'sequence_number' => 1,
        ]);
        $this->assertDatabaseMissing('bot_constructor_dialog_states', [
            'dialog_id' => $message->dialog_id,
        ]);
        $this->assertSame(
            BotConstructorExecution::STATUS_COMPLETED,
            BotConstructorExecution::query()->firstOrFail()->status,
        );
        $this->assertSame(2, Message::query()->count());
    }

    public function test_retry_reuses_running_inbound_execution_when_only_part_of_start_blocks_were_traced(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push([
                    'ok' => true,
                    'result' => ['message_id' => 2001],
                ]),
        ]);

        $channel = $this->readyTelegramChannel();
        $firstBlock = BotConstructorBlock::factory()->active()->create([
            'match_type' => BotConstructorBlock::MATCH_TYPE_EXACT_KEYWORD,
            'match_values' => ['привет'],
            'response_text' => '#{none}',
        ]);
        $secondBlock = BotConstructorBlock::factory()->active()->create([
            'match_type' => BotConstructorBlock::MATCH_TYPE_CONTAINS_TEXT,
            'match_values' => ['при'],
            'response_text' => 'Второй ответ после retry',
        ]);
        $firstBlock->channels()->attach($channel->id);
        $secondBlock->channels()->attach($channel->id);
        $message = $this->createInboundMessage($channel, [
            'text' => 'привет',
            'provider_event_key' => 'constructor-running-execution-retry',
        ]);
        $execution = BotConstructorExecution::query()->create([
            'root_inbound_message_id' => $message->id,
            'parent_execution_id' => null,
            'started_by_arrow_run_id' => null,
            'dialog_id' => $message->dialog_id,
            'channel_id' => $channel->id,
            'trigger_type' => BotConstructorExecution::TRIGGER_INBOUND,
            'auto_transition_count' => 0,
            'next_sequence_number' => 2,
            'status' => BotConstructorExecution::STATUS_RUNNING,
        ]);
        BotConstructorBlockRun::query()->create([
            'inbound_message_id' => $message->id,
            'bot_constructor_block_id' => $firstBlock->id,
            'status' => BotConstructorBlockRun::STATUS_NO_REPLY,
            'error_message' => null,
        ]);
        BotConstructorExecutionBlockRun::query()->create([
            'bot_constructor_execution_id' => $execution->id,
            'bot_constructor_block_id' => $firstBlock->id,
            'bot_constructor_arrow_run_id' => null,
            'dialog_id' => $message->dialog_id,
            'channel_id' => $channel->id,
            'sequence_number' => 1,
            'status' => BotConstructorExecutionBlockRun::STATUS_NO_REPLY,
            'outbound_message_id' => null,
            'processing_started_at' => null,
            'error_message' => null,
        ]);

        app(ProcessBotConstructorBlocksAction::class)->handle($message);
        app(ProcessBotConstructorBlocksAction::class)->handle($message);

        Http::assertSentCount(1);
        $this->assertDatabaseCount('bot_constructor_executions', 1);
        $this->assertDatabaseCount('bot_constructor_execution_block_runs', 2);
        $this->assertDatabaseHas('bot_constructor_executions', [
            'id' => $execution->id,
            'status' => BotConstructorExecution::STATUS_COMPLETED,
            'next_sequence_number' => 3,
        ]);
        $this->assertSame(
            [
                [$firstBlock->id, BotConstructorExecutionBlockRun::STATUS_NO_REPLY, 1],
                [$secondBlock->id, BotConstructorExecutionBlockRun::STATUS_SENT, 2],
            ],
            BotConstructorExecutionBlockRun::query()
                ->where('bot_constructor_execution_id', $execution->id)
                ->orderBy('sequence_number')
                ->get(['bot_constructor_block_id', 'status', 'sequence_number'])
                ->map(fn (BotConstructorExecutionBlockRun $run): array => [
                    $run->bot_constructor_block_id,
                    $run->status,
                    $run->sequence_number,
                ])
                ->all(),
        );
        $this->assertDatabaseHas('bot_constructor_dialog_states', [
            'dialog_id' => $message->dialog_id,
            'current_block_id' => $secondBlock->id,
            'last_execution_id' => $execution->id,
        ]);
    }

    public function test_retry_runs_missing_arrows_after_start_block_trace_was_already_successful(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push([
                    'ok' => true,
                    'result' => ['message_id' => 3000],
                ]),
        ]);

        $channel = $this->readyTelegramChannel();
        $startBlock = BotConstructorBlock::factory()->active()->create([
            'match_type' => BotConstructorBlock::MATCH_TYPE_EXACT_KEYWORD,
            'match_values' => ['старт'],
            'response_text' => 'Уже отправленный старт',
        ]);
        $targetBlock = BotConstructorBlock::factory()->active()->create([
            'response_text' => 'Целевой ответ после retry',
        ]);
        $startBlock->channels()->attach($channel->id);
        BotConstructorArrow::factory()->manualLimit(5)->create([
            'source_block_id' => $startBlock->id,
            'target_block_id' => $targetBlock->id,
        ]);
        $message = $this->createInboundMessage($channel, [
            'text' => 'старт',
            'provider_event_key' => 'constructor-retry-missing-arrows',
        ]);
        $oldOutboundMessage = Message::factory()->create([
            'dialog_id' => $message->dialog_id,
            'contact_id' => $message->contact_id,
            'contact_identity_id' => $message->contact_identity_id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_AUTO_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_SYSTEM,
            'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_BOT_CONSTRUCTOR_BLOCK,
            'reply_to_message_id' => $message->id,
            'external_chat_id' => $message->external_chat_id,
            'external_message_id' => 'already-sent-start',
            'text' => 'Уже отправленный старт',
        ]);
        $execution = BotConstructorExecution::query()->create([
            'root_inbound_message_id' => $message->id,
            'parent_execution_id' => null,
            'started_by_arrow_run_id' => null,
            'dialog_id' => $message->dialog_id,
            'channel_id' => $channel->id,
            'trigger_type' => BotConstructorExecution::TRIGGER_INBOUND,
            'auto_transition_count' => 0,
            'next_sequence_number' => 2,
            'status' => BotConstructorExecution::STATUS_RUNNING,
        ]);
        BotConstructorBlockRun::query()->create([
            'inbound_message_id' => $message->id,
            'bot_constructor_block_id' => $startBlock->id,
            'outbound_message_id' => $oldOutboundMessage->id,
            'status' => BotConstructorBlockRun::STATUS_SENT,
            'error_message' => null,
        ]);
        BotConstructorExecutionBlockRun::query()->create([
            'bot_constructor_execution_id' => $execution->id,
            'bot_constructor_block_id' => $startBlock->id,
            'bot_constructor_arrow_run_id' => null,
            'dialog_id' => $message->dialog_id,
            'channel_id' => $channel->id,
            'sequence_number' => 1,
            'status' => BotConstructorExecutionBlockRun::STATUS_SENT,
            'outbound_message_id' => $oldOutboundMessage->id,
            'processing_started_at' => null,
            'error_message' => null,
        ]);

        app(ProcessBotConstructorBlocksAction::class)->handle($message);
        app(ProcessBotConstructorBlocksAction::class)->handle($message);

        Http::assertSentCount(1);
        $this->assertDatabaseCount('bot_constructor_block_runs', 1);
        $this->assertDatabaseHas('bot_constructor_arrow_runs', [
            'bot_constructor_execution_id' => $execution->id,
            'source_block_id' => $startBlock->id,
            'target_block_id' => $targetBlock->id,
            'status' => BotConstructorArrowRun::STATUS_PASSED,
        ]);
        $this->assertSame(
            [
                [$startBlock->id, BotConstructorExecutionBlockRun::STATUS_SENT, 1],
                [$targetBlock->id, BotConstructorExecutionBlockRun::STATUS_SENT, 2],
            ],
            BotConstructorExecutionBlockRun::query()
                ->where('bot_constructor_execution_id', $execution->id)
                ->orderBy('sequence_number')
                ->get(['bot_constructor_block_id', 'status', 'sequence_number'])
                ->map(fn (BotConstructorExecutionBlockRun $run): array => [
                    $run->bot_constructor_block_id,
                    $run->status,
                    $run->sequence_number,
                ])
                ->all(),
        );
        $this->assertDatabaseHas('messages', [
            'reply_to_message_id' => $message->id,
            'text' => 'Целевой ответ после retry',
        ]);
    }

    public function test_successful_start_block_runs_immediate_arrow_to_target_block(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push([
                    'ok' => true,
                    'result' => ['message_id' => 3001],
                ]),
        ]);

        $channel = $this->readyTelegramChannel();
        $startBlock = BotConstructorBlock::factory()->active()->create([
            'match_type' => BotConstructorBlock::MATCH_TYPE_EXACT_KEYWORD,
            'match_values' => ['старт'],
            'response_text' => '#{none}',
        ]);
        $targetBlock = BotConstructorBlock::factory()->active()->create([
            'response_text' => 'Ответ из целевого блока',
        ]);
        $startBlock->channels()->attach($channel->id);
        BotConstructorArrow::factory()->manualLimit(5)->create([
            'source_block_id' => $startBlock->id,
            'target_block_id' => $targetBlock->id,
        ]);
        $message = $this->createInboundMessage($channel, [
            'text' => 'старт',
            'provider_event_key' => 'constructor-immediate-arrow',
        ]);

        app(ProcessBotConstructorBlocksAction::class)->handle($message);

        Http::assertSentCount(1);
        $this->assertDatabaseHas('messages', [
            'reply_to_message_id' => $message->id,
            'text' => 'Ответ из целевого блока',
        ]);
        $this->assertDatabaseHas('bot_constructor_arrow_runs', [
            'source_block_id' => $startBlock->id,
            'target_block_id' => $targetBlock->id,
            'status' => BotConstructorArrowRun::STATUS_PASSED,
        ]);

        $execution = BotConstructorExecution::query()->firstOrFail();

        $this->assertSame(1, $execution->auto_transition_count);
        $this->assertSame(
            [
                [$startBlock->id, BotConstructorExecutionBlockRun::STATUS_NO_REPLY, 1],
                [$targetBlock->id, BotConstructorExecutionBlockRun::STATUS_SENT, 2],
            ],
            BotConstructorExecutionBlockRun::query()
                ->where('bot_constructor_execution_id', $execution->id)
                ->orderBy('sequence_number')
                ->get(['bot_constructor_block_id', 'status', 'sequence_number'])
                ->map(fn (BotConstructorExecutionBlockRun $run): array => [
                    $run->bot_constructor_block_id,
                    $run->status,
                    $run->sequence_number,
                ])
                ->all(),
        );
        $this->assertDatabaseHas('bot_constructor_dialog_states', [
            'dialog_id' => $message->dialog_id,
            'current_block_id' => $targetBlock->id,
        ]);
    }

    public function test_failed_target_block_does_not_spend_arrow_pass_limit(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push([
                    'ok' => true,
                    'result' => ['message_id' => 3002],
                ]),
        ]);

        $channel = $this->readyTelegramChannel();
        $channel->forceFill([
            'last_webhook_received_at' => null,
            'last_error_at' => null,
        ])->save();
        $startBlock = BotConstructorBlock::factory()->active()->create([
            'match_type' => BotConstructorBlock::MATCH_TYPE_EXACT_KEYWORD,
            'match_values' => ['старт'],
            'response_text' => '#{none}',
        ]);
        $targetBlock = BotConstructorBlock::factory()->active()->create([
            'response_text' => 'Ответ после восстановления',
        ]);
        $startBlock->channels()->attach($channel->id);
        BotConstructorArrow::factory()->manualLimit(1)->create([
            'source_block_id' => $startBlock->id,
            'target_block_id' => $targetBlock->id,
        ]);
        $firstMessage = $this->createInboundMessage($channel, [
            'text' => 'старт',
            'provider_event_key' => 'constructor-arrow-failed-target',
        ]);

        app(ProcessBotConstructorBlocksAction::class)->handle($firstMessage);

        $this->assertDatabaseHas('bot_constructor_arrow_runs', [
            'dialog_id' => $firstMessage->dialog_id,
            'status' => BotConstructorArrowRun::STATUS_FAILED,
        ]);

        $channel->forceFill([
            'last_webhook_received_at' => now(),
            'last_error_at' => null,
        ])->save();
        $secondMessage = Message::factory()->create([
            'dialog_id' => $firstMessage->dialog_id,
            'contact_id' => $firstMessage->contact_id,
            'contact_identity_id' => $firstMessage->contact_identity_id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'provider_event_key' => 'constructor-arrow-after-recovery',
            'external_chat_id' => $firstMessage->external_chat_id,
            'external_message_id' => '11',
            'text' => 'старт',
            'received_at' => now(),
        ]);

        app(ProcessBotConstructorBlocksAction::class)->handle($secondMessage);

        Http::assertSentCount(1);
        $this->assertSame(
            [BotConstructorArrowRun::STATUS_FAILED, BotConstructorArrowRun::STATUS_PASSED],
            BotConstructorArrowRun::query()
                ->where('dialog_id', $firstMessage->dialog_id)
                ->orderBy('id')
                ->pluck('status')
                ->all(),
        );
    }

    public function test_fallback_command_runs_due_scheduled_arrow(): void
    {
        Queue::fake([ProcessBotConstructorScheduledArrowJob::class]);
        Http::fake();

        $channel = $this->readyTelegramChannel();
        $startBlock = BotConstructorBlock::factory()->active()->create([
            'match_type' => BotConstructorBlock::MATCH_TYPE_EXACT_KEYWORD,
            'match_values' => ['таймер'],
            'response_text' => '#{none}',
        ]);
        $targetBlock = BotConstructorBlock::factory()->active()->create([
            'response_text' => '#{none}',
        ]);
        $startBlock->channels()->attach($channel->id);
        BotConstructorArrow::factory()->delayed(5, BotConstructorArrow::DELAY_UNIT_MINUTES)->create([
            'source_block_id' => $startBlock->id,
            'target_block_id' => $targetBlock->id,
        ]);
        $message = $this->createInboundMessage($channel, [
            'text' => 'таймер',
            'provider_event_key' => 'constructor-scheduled-arrow',
        ]);

        app(ProcessBotConstructorBlocksAction::class)->handle($message);

        Queue::assertPushed(ProcessBotConstructorScheduledArrowJob::class);

        $arrowRun = BotConstructorArrowRun::query()->firstOrFail();
        $arrowRun->forceFill([
            'scheduled_for' => now()->subSecond(),
        ])->save();

        $this->artisan('bot-constructor:run-scheduled-arrows')
            ->assertExitCode(0);

        $arrowRun->refresh();

        $this->assertSame(BotConstructorArrowRun::STATUS_PASSED, $arrowRun->status);
        $this->assertDatabaseHas('bot_constructor_executions', [
            'trigger_type' => BotConstructorExecution::TRIGGER_SCHEDULED_ARROW,
            'parent_execution_id' => $arrowRun->bot_constructor_execution_id,
            'started_by_arrow_run_id' => $arrowRun->id,
            'root_inbound_message_id' => $message->id,
            'status' => BotConstructorExecution::STATUS_COMPLETED,
        ]);
        $this->assertDatabaseHas('bot_constructor_execution_block_runs', [
            'bot_constructor_block_id' => $targetBlock->id,
            'bot_constructor_arrow_run_id' => $arrowRun->id,
            'status' => BotConstructorExecutionBlockRun::STATUS_NO_REPLY,
        ]);
        $this->assertDatabaseHas('bot_constructor_dialog_states', [
            'dialog_id' => $message->dialog_id,
            'current_block_id' => $targetBlock->id,
        ]);
    }

    public function test_cleanup_marks_stale_processing_runs_safely(): void
    {
        $channel = $this->readyTelegramChannel();
        $message = $this->createInboundMessage($channel, [
            'provider_event_key' => 'constructor-cleanup',
        ]);
        $sourceBlock = BotConstructorBlock::factory()->active()->create();
        $targetBlock = BotConstructorBlock::factory()->active()->create();
        $arrow = BotConstructorArrow::factory()->create([
            'source_block_id' => $sourceBlock->id,
            'target_block_id' => $targetBlock->id,
        ]);
        $execution = BotConstructorExecution::factory()->create([
            'root_inbound_message_id' => $message->id,
            'dialog_id' => $message->dialog_id,
            'channel_id' => $channel->id,
            'status' => BotConstructorExecution::STATUS_RUNNING,
        ]);
        $arrowRun = BotConstructorArrowRun::factory()->create([
            'bot_constructor_execution_id' => $execution->id,
            'bot_constructor_arrow_id' => $arrow->id,
            'dialog_id' => $message->dialog_id,
            'source_block_id' => $sourceBlock->id,
            'target_block_id' => $targetBlock->id,
            'processing_started_at' => now()->subMinutes(20),
            'status' => BotConstructorArrowRun::STATUS_PROCESSING,
        ]);
        BotConstructorExecutionBlockRun::factory()->create([
            'bot_constructor_execution_id' => $execution->id,
            'bot_constructor_block_id' => $targetBlock->id,
            'bot_constructor_arrow_run_id' => $arrowRun->id,
            'dialog_id' => $message->dialog_id,
            'channel_id' => $channel->id,
            'sequence_number' => 1,
            'processing_started_at' => now()->subMinutes(20),
            'status' => BotConstructorExecutionBlockRun::STATUS_PROCESSING,
        ]);

        $arrow->delete();

        $this->artisan('bot-constructor:cleanup-processing-runs')
            ->assertExitCode(0);

        $this->assertDatabaseHas('bot_constructor_arrow_runs', [
            'id' => $arrowRun->id,
            'status' => BotConstructorArrowRun::STATUS_CANCELLED,
        ]);
        $this->assertDatabaseHas('bot_constructor_execution_block_runs', [
            'bot_constructor_arrow_run_id' => $arrowRun->id,
            'status' => BotConstructorExecutionBlockRun::STATUS_DELIVERY_UNCERTAIN,
        ]);
        $this->assertDatabaseHas('bot_constructor_executions', [
            'id' => $execution->id,
            'status' => BotConstructorExecution::STATUS_FAILED,
        ]);
    }

    public function test_constructor_failure_masks_channel_secrets_in_state_runs_and_activity_log(): void
    {
        $token = 'telegram-token';
        $webhookSecret = 'webhook-secret';
        $channel = $this->readyTelegramChannel();
        $block = BotConstructorBlock::factory()->active()->create([
            'match_type' => BotConstructorBlock::MATCH_TYPE_ANY_INBOUND,
            'match_values' => [],
            'response_text' => 'Ответ',
        ]);
        $block->channels()->attach($channel->id);
        $message = $this->createInboundMessage($channel, [
            'provider_event_key' => 'constructor-secret-mask',
        ]);

        $sendAction = Mockery::mock(SendBotDialogTextAction::class);
        $sendAction
            ->shouldReceive('handleMessage')
            ->once()
            ->andThrow(new RuntimeException(
                "POST https://api.telegram.org/bot{$token}/sendMessage failed with secret {$webhookSecret}",
            ));
        $this->app->instance(SendBotDialogTextAction::class, $sendAction);

        app(ProcessBotConstructorBlocksAction::class)->handle($message);

        $run = BotConstructorBlockRun::query()->firstOrFail();
        $executionRun = BotConstructorExecutionBlockRun::query()->firstOrFail();
        $channel = $channel->fresh();
        $log = ChannelActivityLog::query()
            ->where('event', 'bot.constructor_block_failed')
            ->latest('created_at')
            ->firstOrFail();

        $this->assertSame(BotConstructorBlockRun::STATUS_FAILED, $run->status);
        $this->assertSame(BotConstructorExecutionBlockRun::STATUS_FAILED, $executionRun->status);

        foreach ([$run->error_message, $executionRun->error_message, $channel->last_error_message, data_get($log->context, 'error')] as $storedMessage) {
            $this->assertStringNotContainsString($token, (string) $storedMessage);
            $this->assertStringNotContainsString($webhookSecret, (string) $storedMessage);
            $this->assertStringContainsString('[secret]', (string) $storedMessage);
        }
    }

    public function test_block_matching_supports_parameter_and_text_or_parameter_modes(): void
    {
        $message = new Message([
            'text' => 'Обычный текст',
            'message_parameter' => 'Promo_123',
        ]);

        $parameterBlock = new BotConstructorBlock([
            'match_type' => BotConstructorBlock::MATCH_TYPE_EXACT_PARAMETER,
            'match_values' => ['promo_123'],
        ]);
        $textOrParameterBlock = new BotConstructorBlock([
            'match_type' => BotConstructorBlock::MATCH_TYPE_EXACT_TEXT_OR_PARAMETER,
            'match_values' => ['обычный текст'],
        ]);

        $this->assertTrue($parameterBlock->matchesMessage($message));
        $this->assertTrue($textOrParameterBlock->matchesMessage($message));
    }

    private function readyTelegramChannel(): Channel
    {
        return Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'webhook-secret',
            ],
            'last_webhook_received_at' => now(),
            'last_error_at' => null,
        ]);
    }

    private function readyTelegramAccountChannel(): Channel
    {
        $channel = Channel::factory()->account()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'is_active' => true,
        ]);

        ChannelRuntimeState::query()->create([
            'channel_id' => $channel->id,
            'auth_status' => ChannelRuntimeState::AUTH_STATUS_AUTHORIZED,
            'authorization_state' => ChannelRuntimeState::AUTHORIZATION_STATE_READY,
            'sync_status' => ChannelRuntimeState::SYNC_STATUS_LIVE,
            'runtime_payload' => [
                'gateway_capabilities' => [
                    'outgoing_replies' => true,
                ],
            ],
        ]);

        return $channel->fresh('runtimeState') ?? $channel;
    }

    /**
     * @param  array<string, mixed>  $messageOverrides
     */
    private function createInboundDialogMessage(Channel $channel, array $messageOverrides = []): Message
    {
        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '200',
            'external_username' => 'telegram_user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => '300',
        ]);

        return Message::factory()->create(array_merge([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'reply_to_message_id' => null,
            'provider_event_key' => 'provider-event-key',
            'external_chat_id' => '300',
            'external_message_id' => '10',
            'text' => 'hello',
            'raw_payload' => ['message' => 'payload'],
            'received_at' => now(),
            'auto_reply_sent_at' => null,
        ], $messageOverrides));
    }

    /**
     * @param  array<string, mixed>  $messageOverrides
     */
    private function createInboundMessage(Channel $channel, array $messageOverrides = []): Message
    {
        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '200',
            'external_username' => 'telegram_user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => '300',
        ]);

        return Message::factory()->create(array_merge([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'reply_to_message_id' => null,
            'provider_event_key' => 'provider-event-key',
            'external_chat_id' => '300',
            'external_message_id' => '10',
            'text' => 'hello',
            'raw_payload' => ['message' => 'payload'],
            'received_at' => now(),
            'auto_reply_sent_at' => null,
        ], $messageOverrides));
    }
}
