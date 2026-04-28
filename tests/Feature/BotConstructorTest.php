<?php

namespace Tests\Feature;

use App\Filament\Pages\BotConstructor;
use App\Jobs\ProcessAutoReplyJob;
use App\Models\AutoReplyRule;
use App\Models\BotConstructorBlock;
use App\Models\BotConstructorBlockRun;
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
            ->assertForbidden();
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

        $this->actingAs($admin)
            ->get(BotConstructor::getUrl())
            ->assertOk()
            ->assertSee('Ошибка настроек');

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
        $this->assertDatabaseHas('bot_constructor_block_runs', [
            'inbound_message_id' => $message->id,
            'bot_constructor_block_id' => $block->id,
            'status' => BotConstructorBlockRun::STATUS_NO_REPLY,
        ]);
        $this->assertSame(1, Message::query()->count());
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
        $channel = $channel->fresh();
        $log = ChannelActivityLog::query()
            ->where('event', 'bot.constructor_block_failed')
            ->latest('created_at')
            ->firstOrFail();

        $this->assertSame(BotConstructorBlockRun::STATUS_FAILED, $run->status);

        foreach ([$run->error_message, $channel->last_error_message, data_get($log->context, 'error')] as $storedMessage) {
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

        return Message::factory()->create(array_merge([
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
