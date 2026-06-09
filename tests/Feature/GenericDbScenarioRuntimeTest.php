<?php

namespace Tests\Feature;

use App\Data\AI\AiProviderStructuredResult;
use App\Data\Bitrix24\Bitrix24ContactSyncQueueResultData;
use App\Data\Bitrix24\Bitrix24DealSyncQueueResultData;
use App\Data\Bitrix24\Bitrix24HistoryExportQueueResultData;
use App\Data\Bots\AutoReplyDeliveryResult;
use App\Data\Bots\BotDialogTextSendResult;
use App\Data\Bots\StoredInboundMessageResult;
use App\Data\Dialogs\DialogRouteStatusData;
use App\Jobs\InferContactGenderFromFirstNameJob;
use App\Jobs\ProcessScenarioInboundJob;
use App\Jobs\ProcessScenarioStartJob;
use App\Jobs\ProcessScenarioV3AiAnalysisJob;
use App\Jobs\ProcessScenarioV3OutboundMessageJob;
use App\Jobs\ProcessScenarioV3ScheduledTransitionJob;
use App\Jobs\RetryScenarioV3AiAnalysisJob;
use App\Models\AiRequest;
use App\Models\AiTask;
use App\Models\AutoReplyRule;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\ContactPhoneNumber;
use App\Models\DataDictionaryEntry;
use App\Models\Dialog;
use App\Models\GeoResolutionEvent;
use App\Models\Message;
use App\Models\Scenario;
use App\Models\ScenarioBuilderBlock;
use App\Models\ScenarioBuilderCondition;
use App\Models\ScenarioBuilderEdge;
use App\Models\ScenarioChannelBinding;
use App\Models\ScenarioRun;
use App\Models\ScenarioV3OutboundMessage;
use App\Models\ScenarioV3ScheduledTransition;
use App\Models\ScenarioVersion;
use App\Models\Tag;
use App\Services\AI\AiProviderRequestException;
use App\Services\AI\GeminiApiService;
use App\Services\Bitrix24\QueueBitrix24ContactSyncAction;
use App\Services\Bitrix24\QueueBitrix24DealSyncAction;
use App\Services\Bitrix24\QueueBitrix24HistoryExportAction;
use App\Services\Bots\DispatchStoredInboundBotMessageAction;
use App\Services\Bots\SendBotDialogTextAction;
use App\Services\Bots\StoreOutboundScenarioMessageAction;
use App\Services\Contacts\AddContactPhoneAction;
use App\Services\DataCollection\ExtractFirstNameAction;
use App\Services\Dialogs\DeleteLastOutboundDialogMessageAction;
use App\Services\Geo\ResolveGeoCityAction;
use App\Services\Scenarios\DispatchStoredInboundScenarioAction;
use App\Services\Scenarios\GenericDbScenarioRuntime;
use App\Services\Scenarios\ScenarioEdgeExpressionCondition;
use App\Services\Scenarios\ScenarioRegistry;
use Database\Seeders\GeoDictionarySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\BuildsIbizaMvpSchema;
use Tests\TestCase;

class GenericDbScenarioRuntimeTest extends TestCase
{
    use BuildsIbizaMvpSchema;
    use RefreshDatabase;

    public function test_database_backed_scenario_starts_by_parameter_and_advances_to_first_question(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 7001]])
                ->push(['ok' => true, 'result' => ['message_id' => 7002]]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario('vip_ibiza_apply', $this->linearSchema('vip_ibiza_apply'));

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $message = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => '/start vip_ibiza_apply',
            'message_parameter' => 'vip_ibiza_apply',
            'raw_payload' => [
                'message' => [
                    'text' => '/start vip_ibiza_apply',
                ],
            ],
        ]);

        (new ProcessScenarioStartJob($message->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();
        $outboundMessages = Message::query()
            ->where('direction', Message::DIRECTION_OUTBOUND)
            ->orderBy('id')
            ->get();

        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('ask_name', $run->current_step);
        $this->assertSame([], $run->state_payload);
        $this->assertCount(2, $outboundMessages);
        $this->assertSame('Добро пожаловать в сценарий.', $outboundMessages[0]->text);
        $this->assertSame('Как вас зовут?', $outboundMessages[1]->text);
        $this->assertSame(Message::KIND_OUTBOUND_SCENARIO_MESSAGE, $outboundMessages[0]->message_kind);
        $this->assertSame('scenario_vip_ibiza_apply', $outboundMessages[0]->sent_by_system_code);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === $dialog->external_chat_id
            && $request['text'] === 'Добро пожаловать в сценарий.');
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === $dialog->external_chat_id
            && $request['text'] === 'Как вас зовут?');
    }

    public function test_database_backed_scenario_can_start_by_contains_text_scope(): void
    {
        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario('contains_parameter_rule', [
            'version' => 1,
            'start_block_id' => 'welcome',
            'triggers' => [
                [
                    'type' => 'parameter',
                    'value' => 'green_start',
                    'match_scope' => AutoReplyRule::MATCH_SCOPE_CONTAINS_TEXT,
                ],
            ],
            'blocks' => [
                'welcome' => [
                    'type' => 'message',
                    'text' => 'Ответ из зелёного правила.',
                    'next' => 'done',
                ],
                'done' => [
                    'type' => 'complete',
                ],
            ],
        ]);

        app(ScenarioRegistry::class)->forgetCachedDefinitions();

        $message = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'Пожалуйста, запусти green_start сейчас',
            'message_parameter' => null,
        ]);

        $runtime = app(ScenarioRegistry::class)->makeRuntime($scenario->code);

        $this->assertNotNull($runtime);
        $this->assertTrue($runtime->shouldStart($message));
    }

    public function test_v3_runtime_starts_sends_text_buttons_and_waits_for_button_text(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9001]])
                ->push(['ok' => true, 'result' => ['message_id' => 9002]]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario('v3_catalog_runtime', $this->v3CatalogRuntimeSchema($channel->id));

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
            'message_parameter' => null,
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();

        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('start', $run->current_step);
        $this->assertSame('waiting_input', data_get($run->state_payload, 'v3.status'));
        $this->assertSame(['btn_catalog'], data_get($run->state_payload, 'v3.waiting_output_ids'));

        $startOutbound = Message::query()
            ->where('reply_to_message_id', $startMessage->id)
            ->where('message_kind', Message::KIND_OUTBOUND_SCENARIO_MESSAGE)
            ->firstOrFail();

        $this->assertSame('Получить каталог', data_get($startOutbound->raw_payload, 'v3.buttons.rows.0.0.text'));
        $this->assertSame('text', data_get($startOutbound->raw_payload, 'v3.buttons.rows.0.0.type'));

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === $dialog->external_chat_id
            && $request['text'] === 'Выберите действие'
            && data_get($request->data(), 'reply_markup.keyboard.0.0.text') === 'Получить каталог');

        $buttonMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'Получить каталог',
        ]);

        (new ProcessScenarioInboundJob($buttonMessage->id, $run->id))
            ->handle(app(ScenarioRegistry::class));

        $run->refresh();

        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('catalog', $run->current_step);
        $this->assertSame('waiting_input', data_get($run->state_payload, 'v3.status'));
        $this->assertSame('catalog', data_get($run->state_payload, 'v3.current_block_id'));
        $this->assertSame([], data_get($run->state_payload, 'v3.waiting_output_ids'));

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === $dialog->external_chat_id
            && $request['text'] === 'Вот каталог');
    }

    public function test_v3_tag_effects_failure_stops_message_dispatch(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 9103],
            ]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $tag = Tag::factory()->create(['name' => 'Импортированный тег']);
        $schema = $this->v3CatalogRuntimeSchema($channel->id);
        data_set($schema, 'builder_v3_runtime.blocks.start.actions', [[
            'type' => 'tag_effects',
            'assign_tag_ids' => [$tag->id],
            'remove_tag_ids' => [],
        ]]);
        data_set($schema, 'builder_v3_runtime.blocks.start.message.text', 'Ответ после тега');
        data_set($schema, 'builder_v3_runtime.blocks.start.buttons', null);
        data_set($schema, 'builder_v3_runtime.edges', []);

        $scenario = $this->createPublishedScenario('v3_tag_effects_stop', $schema);

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $tag->forceFill(['is_active' => false])->save();

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
            'message_parameter' => null,
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        Http::assertNothingSent();
        $this->assertDatabaseMissing('messages', [
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'text' => 'Ответ после тега',
        ]);

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();
        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('start', $run->current_step);
    }

    public function test_v3_wait_reply_priority_can_beat_matching_button_edge(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9101]])
                ->push(['ok' => true, 'result' => ['message_id' => 9102]]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario(
            'v3_button_wait_reply_priority',
            $this->v3ButtonAndWaitReplyRuntimeSchema($channel->id),
        );

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();

        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Получить каталог');

        $run->refresh();

        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('manual', $run->current_step);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === $dialog->external_chat_id
            && $request['text'] === 'Сработала обычная стрелка с большим приоритетом');
    }

    public function test_v3_button_edge_keeps_edge_key_transition_limit_and_priority(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 9110]]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $schema = $this->v3ButtonAndWaitReplyRuntimeSchema($channel->id);
        $buttonEdgePath = 'builder_v3_runtime.blocks.start.buttons.rows.0.0.edge';

        data_set($schema, $buttonEdgePath.'.edge_key', 'edge_button_guarded');
        data_set($schema, $buttonEdgePath.'.priority', 30);
        data_set($schema, $buttonEdgePath.'.transition_limit', 1);
        data_set($schema, 'builder_v3_runtime.blocks.start.wait_reply_edges.0.priority', 5);
        data_set($schema, 'builder_v3_runtime.edges.0', data_get($schema, $buttonEdgePath));

        $scenario = $this->createPublishedScenario('v3_button_edge_limit_priority', $schema);

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $firstStartMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($firstStartMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->active()->firstOrFail();

        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Получить каталог');

        $run->refresh();
        $dialog->refresh();

        $buttonCounterKey = 'published_'.$scenario->publishedVersion->id.':edge_button_guarded';
        $fallbackCounterKey = 'published_'.$scenario->publishedVersion->id.':edge_manual';

        $this->assertSame('catalog', $run->current_step);
        $this->assertSame(1, data_get($dialog->fields_payload, '_v3.transition_counts.'.$buttonCounterKey));
        $this->assertNull(data_get($dialog->fields_payload, '_v3.transition_counts.'.$fallbackCounterKey));

        $secondStartMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($secondStartMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->active()->firstOrFail();

        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Получить каталог');

        $run->refresh();
        $dialog->refresh();

        $this->assertSame('manual', $run->current_step);
        $this->assertSame(1, data_get($dialog->fields_payload, '_v3.transition_counts.'.$buttonCounterKey));
        $this->assertSame(1, data_get($dialog->fields_payload, '_v3.transition_counts.'.$fallbackCounterKey));
    }

    public function test_v3_button_edge_keeps_contact_and_field_conditions(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 9111]]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $dialog->forceFill([
            'fields_payload' => [
                'lead_status' => 'cold',
            ],
        ])->save();

        $schema = $this->v3ButtonAndWaitReplyRuntimeSchema($channel->id);
        $buttonEdgePath = 'builder_v3_runtime.blocks.start.buttons.rows.0.0.edge';

        data_set($schema, $buttonEdgePath.'.priority', 30);
        data_set($schema, $buttonEdgePath.'.contact_phone_condition', AutoReplyRule::CONTACT_PHONE_CONDITION_HAS_PHONE);
        data_set($schema, $buttonEdgePath.'.field_condition', [
            'enabled' => true,
            'field_scope' => 'dialog',
            'field_key' => 'lead_status',
            'operator' => 'equals',
            'value' => 'hot',
        ]);
        data_set($schema, 'builder_v3_runtime.blocks.start.wait_reply_edges.0.priority', 5);
        data_set($schema, 'builder_v3_runtime.edges.0', data_get($schema, $buttonEdgePath));

        $scenario = $this->createPublishedScenario('v3_button_edge_conditions', $schema);

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->active()->firstOrFail();

        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Получить каталог');

        $run->refresh();

        $this->assertSame('manual', $run->current_step);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === $dialog->external_chat_id
            && $request['text'] === 'Сработала обычная стрелка с большим приоритетом');
    }

    public function test_v3_wait_reply_sends_target_message_after_run_transition_is_persisted(): void
    {
        Queue::fake([
            ProcessScenarioV3OutboundMessageJob::class,
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario('v3_wait_reply_deferred_send', $this->v3CatalogRuntimeSchema($channel->id));
        $sendCount = 0;
        $runId = null;
        $sendAction = Mockery::mock(SendBotDialogTextAction::class);
        $sendAction
            ->shouldReceive('handleMessage')
            ->twice()
            ->andReturnUsing(function (...$args) use (&$sendCount, &$runId, $dialog): BotDialogTextSendResult {
                $sendCount++;
                $text = (string) $args[1];

                if ($text === 'Вот каталог') {
                    $this->assertNotNull($runId);
                    $this->assertSame('catalog', ScenarioRun::query()->findOrFail($runId)->current_step);
                    $this->assertSame('catalog', data_get(ScenarioRun::query()->findOrFail($runId)->state_payload, 'v3.current_block_id'));
                }

                return $this->successfulBotSendResult($dialog, $text, 'mock-v3-'.$sendCount);
            });
        $this->app->instance(SendBotDialogTextAction::class, $sendAction);

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();
        $runId = $run->id;

        $buttonMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'Получить каталог',
        ]);

        (new ProcessScenarioInboundJob($buttonMessage->id, $run->id))
            ->handle(app(ScenarioRegistry::class));

        $targetOutbound = ScenarioV3OutboundMessage::query()
            ->where('text', 'Вот каталог')
            ->firstOrFail();

        $this->assertSame(2, $sendCount);
        $this->assertSame(ScenarioV3OutboundMessage::STATUS_SENT, $targetOutbound->status);
        Queue::assertPushed(ProcessScenarioV3OutboundMessageJob::class, fn (ProcessScenarioV3OutboundMessageJob $job): bool => $job->outboundMessageId === $targetOutbound->id);
    }

    public function test_v3_ai_analysis_retries_delayed_variant_until_later_message_is_accepted(): void
    {
        Queue::fake([
            ProcessScenarioV3AiAnalysisJob::class,
        ]);
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9201]])
                ->push(['ok' => true, 'result' => ['message_id' => 9202]]),
        ]);

        $prompts = [];
        $gemini = Mockery::mock(GeminiApiService::class);
        $gemini
            ->shouldReceive('generateStructuredWithMetadata')
            ->twice()
            ->andReturnUsing(function (string $systemPrompt, string $userPrompt, array $schema) use (&$prompts): AiProviderStructuredResult {
                $prompts[] = $userPrompt;
                $this->assertSame(['1', '2'], data_get($schema, 'properties.output_id.enum'));
                $this->assertStringContainsString('ID 1: Имя найдено', $systemPrompt);
                $this->assertStringContainsString('ID 2: Имя не найдено', $systemPrompt);

                $payload = count($prompts) === 1
                    ? ['output_id' => '2', 'data' => []]
                    : ['output_id' => '1', 'data' => ['first_name' => 'Вася']];

                return new AiProviderStructuredResult(
                    provider: 'gemini',
                    model: 'test-gemini',
                    parsedPayload: $payload,
                    requestBodyRaw: '{}',
                    responseBodyRaw: '{}',
                    httpStatus: 200,
                    inputTokens: 10,
                    outputTokens: 5,
                    totalTokens: 15,
                );
            });
        $this->app->instance(GeminiApiService::class, $gemini);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario('v3_ai_name_retry_delay', $this->v3AiNameRuntimeSchema($channel->id));

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();
        $firstAnswer = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'Меня',
        ]);

        (new ProcessScenarioInboundJob($firstAnswer->id, $run->id))
            ->handle(app(ScenarioRegistry::class));

        $run->refresh();
        $pending = data_get($run->state_payload, 'v3.ai_analysis_pending.ai');

        $this->assertSame('ai', $run->current_step);
        $this->assertSame('name_retry', data_get($pending, 'output_id'));
        $this->assertSame(10, data_get($pending, 'delay_seconds'));
        Queue::assertPushed(ProcessScenarioV3AiAnalysisJob::class, fn (ProcessScenarioV3AiAnalysisJob $job): bool => $job->scenarioRunId === $run->id
            && $job->inboundMessageId === $firstAnswer->id
            && $job->blockId === 'ai');

        $secondAnswer = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'зовут Вася',
        ]);

        (new ProcessScenarioInboundJob($secondAnswer->id, $run->id))
            ->handle(app(ScenarioRegistry::class));

        $run->refresh();

        $this->assertSame(ScenarioRun::STATUS_COMPLETED, $run->status);
        $this->assertNull($run->current_step);
        $this->assertSame('completed', $run->exit_outcome);
        $this->assertNull(data_get($run->state_payload, 'v3.ai_analysis_pending.ai'));
        $this->assertSame('Вася', data_get($run->state_payload, 'v3.ai_analysis.ai.data.first_name'));
        $this->assertStringContainsString("Меня\nзовут Вася", $prompts[1]);
        $contact->refresh();
        $this->assertSame('Вася', $contact->first_name);
        $this->assertSame(Contact::FIRST_NAME_SOURCE_CONTACT_CONFIRMED, $contact->first_name_source);
        $this->assertSame(Contact::FIRST_NAME_RESOLUTION_METHOD_AI_ANALYSIS, $contact->first_name_resolution_method);

        $sentTexts = Http::recorded()
            ->map(fn (array $record): string => (string) $record[0]['text'])
            ->values()
            ->all();

        $this->assertSame(['Как вас зовут?', 'Имя принято'], $sentTexts);

        (new ProcessScenarioV3AiAnalysisJob(
            $run->id,
            $dialog->id,
            $firstAnswer->id,
            $scenario->code,
            $scenario->publishedVersion->id,
            'ai',
            (string) data_get($pending, 'token'),
        ))->handle(app(ScenarioRegistry::class));

        $run->refresh();

        $this->assertSame(ScenarioRun::STATUS_COMPLETED, $run->status);
        $this->assertNull($run->current_step);
    }

    public function test_v3_delayed_ai_analysis_does_not_reschedule_same_output_for_same_message(): void
    {
        Queue::fake([
            ProcessScenarioV3AiAnalysisJob::class,
        ]);
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9251]])
                ->push(['ok' => true, 'result' => ['message_id' => 9252]]),
        ]);

        $gemini = Mockery::mock(GeminiApiService::class);
        $gemini
            ->shouldReceive('generateStructuredWithMetadata')
            ->times(3)
            ->andReturn(new AiProviderStructuredResult(
                provider: 'gemini',
                model: 'test-gemini',
                parsedPayload: ['output_id' => 'name_retry', 'data' => []],
                requestBodyRaw: '{}',
                responseBodyRaw: '{}',
                httpStatus: 200,
                inputTokens: 10,
                outputTokens: 5,
                totalTokens: 15,
            ));
        $this->app->instance(GeminiApiService::class, $gemini);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $schema = $this->v3AiNameRuntimeSchema($channel->id);
        $retryToAiEdge = [
            'id' => '40',
            'edge_key' => 'edge_retry_to_ai',
            'mode' => 'automatic',
            'priority' => 10,
            'transition_limit' => 0,
            'source_block_id' => 'retry',
            'target_block_id' => 'ai',
            'from_output_id' => null,
            'label' => 'Проверить снова',
            'match' => [
                'type' => 'any_inbound',
                'text' => '',
                'variants' => [],
            ],
        ];

        data_set($schema, 'builder_v3_runtime.blocks.ai.ai_analysis.source', 'current_inbound_message');
        data_set($schema, 'builder_v3_runtime.blocks.retry.automatic_edges', [$retryToAiEdge]);
        data_set($schema, 'builder_v3_runtime.edges', [
            ...data_get($schema, 'builder_v3_runtime.edges', []),
            $retryToAiEdge,
        ]);

        $scenario = $this->createPublishedScenario('v3_ai_name_retry_no_loop', $schema);

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();
        $answer = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'Больше 40 лет',
        ]);

        (new ProcessScenarioInboundJob($answer->id, $run->id))
            ->handle(app(ScenarioRegistry::class));

        $run->refresh();
        $pending = data_get($run->state_payload, 'v3.ai_analysis_pending.ai');

        $this->assertSame('ai', $run->current_step);
        $this->assertSame('name_retry', data_get($pending, 'output_id'));
        Queue::assertPushed(ProcessScenarioV3AiAnalysisJob::class, 1);

        (new ProcessScenarioV3AiAnalysisJob(
            $run->id,
            $dialog->id,
            $answer->id,
            $scenario->code,
            $scenario->publishedVersion->id,
            'ai',
            (string) data_get($pending, 'token'),
        ))->handle(app(ScenarioRegistry::class));

        $run->refresh();
        $sentTexts = Http::recorded()
            ->map(fn (array $record): string => (string) $record[0]['text'])
            ->values()
            ->all();

        $this->assertSame(['Как вас зовут?', 'Повторите имя'], $sentTexts);
        Queue::assertPushed(ProcessScenarioV3AiAnalysisJob::class, 1);
        $this->assertSame('ai', $run->current_step);
        $this->assertNull(data_get($run->state_payload, 'v3.ai_analysis_pending.ai'));
        $this->assertSame([
            'message_id' => $answer->id,
            'output_id' => 'name_retry',
            'delay_seconds' => 10,
        ], collect(data_get($run->state_payload, 'v3.ai_analysis_delayed_history.ai', []))
            ->map(fn (array $entry): array => [
                'message_id' => (int) ($entry['message_id'] ?? 0),
                'output_id' => (string) ($entry['output_id'] ?? ''),
                'delay_seconds' => (int) ($entry['delay_seconds'] ?? 0),
            ])
            ->first());
    }

    public function test_v3_check_data_action_finds_dictionary_name_without_ai(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9301]])
                ->push(['ok' => true, 'result' => ['message_id' => 9302]]),
        ]);

        $gemini = Mockery::mock(GeminiApiService::class);
        $gemini->shouldReceive('generateStructured')->never();
        $this->app->instance(GeminiApiService::class, $gemini);

        DataDictionaryEntry::query()->create([
            'dictionary_key' => DataDictionaryEntry::DICTIONARY_NAMES,
            'lookup_value' => 'Вася',
            'result_value' => 'Василий',
            'gender' => DataDictionaryEntry::GENDER_MALE,
            'variant_type' => DataDictionaryEntry::VARIANT_TYPE_SHORT,
            'auto_apply' => true,
            'is_active' => true,
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario('v3_check_data_name', $this->v3CheckDataNameRuntimeSchema($channel->id));

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();
        $answer = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'Вася',
        ]);

        (new ProcessScenarioInboundJob($answer->id, $run->id))
            ->handle(app(ScenarioRegistry::class));

        $run->refresh();
        $this->assertSame(ScenarioRun::STATUS_COMPLETED, $run->status);
        $this->assertNull($run->current_step);
        $this->assertSame('completed', $run->exit_outcome);
        $this->assertSame('Василий', data_get($run->state_payload, 'v3.variables.first_name'));

        $contact->refresh();
        $this->assertSame('Василий', $contact->first_name);
        $this->assertSame(Contact::FIRST_NAME_SOURCE_CONTACT_CONFIRMED, $contact->first_name_source);
        $this->assertSame(Contact::FIRST_NAME_RESOLUTION_METHOD_DICTIONARY_LOOKUP, $contact->first_name_resolution_method);
    }

    public function test_v3_check_data_action_routes_manual_required_name(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9311]])
                ->push(['ok' => true, 'result' => ['message_id' => 9312]]),
        ]);

        $gemini = Mockery::mock(GeminiApiService::class);
        $gemini->shouldReceive('generateStructured')->never();
        $this->app->instance(GeminiApiService::class, $gemini);

        DataDictionaryEntry::query()->create([
            'dictionary_key' => DataDictionaryEntry::DICTIONARY_NAMES,
            'lookup_value' => 'Сашенька',
            'result_value' => 'Александр',
            'gender' => DataDictionaryEntry::GENDER_MALE,
            'variant_type' => DataDictionaryEntry::VARIANT_TYPE_SPOKEN,
            'auto_apply' => false,
            'is_active' => true,
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $contact->forceFill(['gender' => DataDictionaryEntry::GENDER_MALE])->save();
        $scenario = $this->createPublishedScenario('v3_check_data_manual_required_name', $this->v3CheckDataNameRuntimeSchema($channel->id));

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();
        $answer = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'Сашенька',
        ]);

        (new ProcessScenarioInboundJob($answer->id, $run->id))
            ->handle(app(ScenarioRegistry::class));

        $run->refresh();
        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('manual_required', $run->current_step);
        $this->assertNull(data_get($run->state_payload, 'v3.variables.first_name'));

        $contact->refresh();
        $this->assertNull($contact->first_name);

        $sentTexts = Http::recorded()
            ->map(fn (array $record): string => (string) $record[0]['text'])
            ->values()
            ->all();

        $this->assertSame(['Как вас зовут?', 'Нужно уточнить'], $sentTexts);
    }

    public function test_v3_message_text_substitutes_contact_dialog_and_scenario_variables(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9361]])
                ->push(['ok' => true, 'result' => ['message_id' => 9362]]),
        ]);

        DataDictionaryEntry::query()->create([
            'dictionary_key' => DataDictionaryEntry::DICTIONARY_NAMES,
            'lookup_value' => 'Вася',
            'result_value' => 'Василий',
            'gender' => DataDictionaryEntry::GENDER_MALE,
            'variant_type' => DataDictionaryEntry::VARIANT_TYPE_SHORT,
            'auto_apply' => true,
            'is_active' => true,
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext(
            $channel,
            contactOverrides: [
                'first_name' => 'Abrikosov German',
                'first_name_source' => Contact::FIRST_NAME_SOURCE_AUTO,
                'gender' => 'male',
            ],
            dialogOverrides: ['fields_payload' => ['selected_gender' => 'male']],
        );
        $schema = $this->v3CheckDataNameRuntimeSchema($channel->id);
        data_set(
            $schema,
            'builder_v3_runtime.blocks.accepted.message.text',
            'Записали {{variables.first_name}} / {{contact.first_name|нет имени}} / {{contact.gender}} / {{dialog.selected_gender}}',
        );
        $scenario = $this->createPublishedScenario('v3_message_variables', $schema);

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();
        $answer = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'Вася',
        ]);

        (new ProcessScenarioInboundJob($answer->id, $run->id))
            ->handle(app(ScenarioRegistry::class));

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['text'] === 'Записали Василий / Василий / male / male');
    }

    public function test_v3_ai_analysis_substitutes_prompt_variables(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9351]])
                ->push(['ok' => true, 'result' => ['message_id' => 9352]]),
        ]);

        $captured = [];
        $gemini = Mockery::mock(GeminiApiService::class);
        $gemini
            ->shouldReceive('generateStructuredWithMetadata')
            ->once()
            ->andReturnUsing(function (string $systemPrompt, string $userPrompt, array $schema) use (&$captured): AiProviderStructuredResult {
                $captured = [
                    'system' => $systemPrompt,
                    'user' => $userPrompt,
                    'schema' => $schema,
                ];

                return new AiProviderStructuredResult(
                    provider: 'gemini',
                    model: 'test-gemini',
                    parsedPayload: ['output_id' => '1', 'data' => ['first_name' => 'Николай']],
                    requestBodyRaw: '{}',
                    responseBodyRaw: '{}',
                    httpStatus: 200,
                    inputTokens: 10,
                    outputTokens: 5,
                    totalTokens: 15,
                );
            });
        $this->app->instance(GeminiApiService::class, $gemini);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel, [
            'gender' => 'male',
            'city' => 'Москва',
            'region' => 'Москва',
        ]);
        $schema = $this->v3AiNameRuntimeSchema($channel->id);
        data_set(
            $schema,
            'builder_v3_runtime.blocks.ai.ai_analysis.prompt',
            "Найди имя.\nПол: {{contact.gender|unknown}}\nГород: {{contact.city|none}}\nРегион: {{contact.region|none}}\nСообщения:\n{{input.client_messages}}\nПрошлое имя: {{variables.previous_name|empty}}",
        );
        $scenario = $this->createPublishedScenario('v3_ai_prompt_variables', $schema);

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();
        $answer = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'Коля',
        ]);

        (new ProcessScenarioInboundJob($answer->id, $run->id))
            ->handle(app(ScenarioRegistry::class));

        $this->assertStringContainsString('Пол: male', $captured['system']);
        $this->assertStringContainsString('Город: Москва', $captured['system']);
        $this->assertStringContainsString('Регион: Москва', $captured['system']);
        $this->assertStringContainsString("Сообщения:\nКоля", $captured['system']);
        $this->assertStringContainsString('Прошлое имя: empty', $captured['system']);
        $this->assertStringContainsString('ID 1: Имя найдено', $captured['system']);
        $this->assertSame(['1', '2'], data_get($captured['schema'], 'properties.output_id.enum'));
        $this->assertSame('Данные для анализа уже подставлены в промт оператора.', $captured['user']);

        $contact->refresh();
        $this->assertSame('Николай', $contact->first_name);
        $this->assertSame(Contact::FIRST_NAME_RESOLUTION_METHOD_AI_ANALYSIS, $contact->first_name_resolution_method);
    }

    public function test_v3_ai_analysis_logs_non_name_request_to_ai_requests(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9361]])
                ->push(['ok' => true, 'result' => ['message_id' => 9362]]),
        ]);

        $gemini = Mockery::mock(GeminiApiService::class);
        $gemini
            ->shouldReceive('generateStructuredWithMetadata')
            ->once()
            ->andReturn(new AiProviderStructuredResult(
                provider: 'gemini',
                model: 'test-gemini',
                parsedPayload: ['output_id' => '1', 'data' => ['geo_city' => 'Москва']],
                requestBodyRaw: '{"request":true}',
                responseBodyRaw: '{"output_id":"1"}',
                httpStatus: 200,
                inputTokens: 10,
                outputTokens: 5,
                totalTokens: 15,
            ));
        $this->app->instance(GeminiApiService::class, $gemini);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $schema = $this->v3AiNameRuntimeSchema($channel->id);
        data_set($schema, 'builder_v3_runtime.blocks.ask_name.message.text', 'В каком городе живете?');
        data_set($schema, 'builder_v3_runtime.blocks.ai.title', 'ИИ анализирует город');
        data_set($schema, 'builder_v3_runtime.blocks.ai.ai_analysis.prompt', 'Определи город клиента.');
        data_set($schema, 'builder_v3_runtime.blocks.ai.ai_analysis.extract_fields', [[
            'key' => 'geo_city',
            'label' => 'Город',
            'type' => 'text',
        ]]);
        data_set($schema, 'builder_v3_runtime.blocks.ai.ai_analysis.outputs.0.label', 'Город найден');
        data_set($schema, 'builder_v3_runtime.blocks.ai.ai_analysis.outputs.1.label', 'Город не найден');
        data_set($schema, 'builder_v3_runtime.blocks.accepted.actions', []);
        $scenario = $this->createPublishedScenario('v3_ai_geo_city_analytics', $schema);

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();
        $answer = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'Москва',
        ]);

        (new ProcessScenarioInboundJob($answer->id, $run->id))
            ->handle(app(ScenarioRegistry::class));

        $request = AiRequest::query()->sole();
        $run->refresh();

        $this->assertSame(AiTask::KEY_SCENARIO_V3_AI_ANALYSIS, $request->task_key);
        $this->assertSame(AiRequest::STATUS_SUCCESS, $request->status);
        $this->assertSame($contact->id, $request->contact_id);
        $this->assertSame($dialog->id, $request->dialog_id);
        $this->assertSame($channel->id, $request->channel_id);
        $this->assertSame($scenario->id, $request->scenario_id);
        $this->assertSame('ai', $request->scenario_block_id);
        $this->assertSame('scenario_v3_ai_analysis:ai', $request->prompt_key);
        $this->assertSame($request->id, data_get($run->state_payload, 'v3.ai_analysis.ai.ai_request_id'));
        $this->assertSame('Москва', data_get($run->state_payload, 'v3.ai_analysis.ai.data.geo_city'));
        $this->assertFalse(data_get($run->state_payload, 'v3.ai_analysis.ai.error'));
        $this->assertNull(data_get($run->state_payload, 'v3.ai_analysis.ai.error_reason'));
    }

    public function test_v3_ai_analysis_first_name_uses_universal_ai_request_and_name_event(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9363]])
                ->push(['ok' => true, 'result' => ['message_id' => 9364]]),
        ]);

        $gemini = Mockery::mock(GeminiApiService::class);
        $gemini
            ->shouldReceive('generateStructuredWithMetadata')
            ->once()
            ->andReturn(new AiProviderStructuredResult(
                provider: 'gemini',
                model: 'test-gemini',
                parsedPayload: ['output_id' => '1', 'data' => ['first_name' => 'Николай']],
                requestBodyRaw: '{}',
                responseBodyRaw: '{}',
                httpStatus: 200,
                inputTokens: 10,
                outputTokens: 5,
                totalTokens: 15,
            ));
        $this->app->instance(GeminiApiService::class, $gemini);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario('v3_ai_first_name_universal_analytics', $this->v3AiNameRuntimeSchema($channel->id));

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();
        $answer = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'Николай',
        ]);

        (new ProcessScenarioInboundJob($answer->id, $run->id))
            ->handle(app(ScenarioRegistry::class));

        $request = AiRequest::query()->sole();
        $run->refresh();

        $this->assertSame(AiTask::KEY_SCENARIO_V3_AI_ANALYSIS, $request->task_key);
        $this->assertSame('scenario_v3_ai_analysis:ai', $request->prompt_key);
        $this->assertSame('ai', $request->scenario_block_id);
        $this->assertSame($request->id, data_get($run->state_payload, 'v3.ai_analysis.ai.ai_request_id'));
        $this->assertDatabaseHas('contact_first_name_resolution_events', [
            'contact_id' => $contact->id,
            'dialog_id' => $dialog->id,
            'scenario_id' => $scenario->id,
            'scenario_block_id' => 'ai',
            'ai_request_id' => $request->id,
        ]);
    }

    public function test_v3_ai_analysis_unknown_output_keeps_successful_ai_request_and_state_warning(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9365]]),
        ]);

        $gemini = Mockery::mock(GeminiApiService::class);
        $gemini
            ->shouldReceive('generateStructuredWithMetadata')
            ->once()
            ->andReturn(new AiProviderStructuredResult(
                provider: 'gemini',
                model: 'test-gemini',
                parsedPayload: ['output_id' => 'unexpected', 'data' => ['geo_city' => 'Москва']],
                requestBodyRaw: '{}',
                responseBodyRaw: '{}',
                httpStatus: 200,
                inputTokens: 10,
                outputTokens: 5,
                totalTokens: 15,
            ));
        $this->app->instance(GeminiApiService::class, $gemini);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $schema = $this->v3AiNameRuntimeSchema($channel->id);
        data_set($schema, 'builder_v3_runtime.blocks.ai.ai_analysis.extract_fields', [[
            'key' => 'geo_city',
            'label' => 'Город',
            'type' => 'text',
        ]]);
        data_set($schema, 'builder_v3_runtime.blocks.accepted.actions', []);
        $scenario = $this->createPublishedScenario('v3_ai_unknown_output_analytics', $schema);

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();
        $answer = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'Москва',
        ]);

        (new ProcessScenarioInboundJob($answer->id, $run->id))
            ->handle(app(ScenarioRegistry::class));

        $request = AiRequest::query()->sole();
        $run->refresh();

        $this->assertSame(AiRequest::STATUS_SUCCESS, $request->status);
        $this->assertSame($request->id, data_get($run->state_payload, 'v3.ai_analysis.ai.ai_request_id'));
        $this->assertTrue(data_get($run->state_payload, 'v3.ai_analysis.ai.error'));
        $this->assertSame('unknown_output', data_get($run->state_payload, 'v3.ai_analysis.ai.error_reason'));
        $this->assertSame([], data_get($run->state_payload, 'v3.ai_analysis.ai.data'));
    }

    public function test_v3_ai_analysis_temporary_provider_error_schedules_retry(): void
    {
        Queue::fake([
            RetryScenarioV3AiAnalysisJob::class,
        ]);
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9366]]),
        ]);

        $gemini = Mockery::mock(GeminiApiService::class);
        $gemini
            ->shouldReceive('generateStructuredWithMetadata')
            ->once()
            ->andThrow(new AiProviderRequestException(
                message: 'quota exceeded',
                provider: 'gemini',
                model: 'test-gemini',
                requestBodyRaw: '{}',
                responseBodyRaw: '{"error":"quota"}',
                httpStatus: 429,
            ));
        $this->app->instance(GeminiApiService::class, $gemini);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $schema = $this->v3AiNameRuntimeSchema($channel->id);
        data_set($schema, 'builder_v3_runtime.blocks.ai.ai_analysis.extract_fields', [[
            'key' => 'geo_city',
            'label' => 'Город',
            'type' => 'text',
        ]]);
        $scenario = $this->createPublishedScenario('v3_ai_provider_error_analytics', $schema);

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();
        $answer = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'Москва',
        ]);

        (new ProcessScenarioInboundJob($answer->id, $run->id))
            ->handle(app(ScenarioRegistry::class));

        $request = AiRequest::query()->with('attempts')->sole();
        $run->refresh();

        $retry = data_get($run->state_payload, 'v3.ai_analysis_retry.ai');

        $this->assertSame(AiRequest::STATUS_RETRYING, $request->status);
        $this->assertSame('ai', $run->current_step);
        $this->assertSame($request->id, data_get($retry, 'ai_request_id'));
        $this->assertSame(2, data_get($retry, 'cycle'));
        $this->assertSame('temporary_provider_error', data_get($retry, 'reason'));
        $this->assertCount(1, $request->attempts);
        Queue::assertPushed(RetryScenarioV3AiAnalysisJob::class, fn (RetryScenarioV3AiAnalysisJob $job): bool => $job->scenarioRunId === $run->id
            && $job->inboundMessageId === $answer->id
            && $job->blockId === 'ai'
            && $job->cycle === 2);
    }

    public function test_v3_ai_analysis_exhausted_temporary_retries_routes_to_ai_failed(): void
    {
        Queue::fake([
            RetryScenarioV3AiAnalysisJob::class,
        ]);
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9367]]),
        ]);

        $gemini = Mockery::mock(GeminiApiService::class);
        $gemini
            ->shouldReceive('generateStructuredWithMetadata')
            ->twice()
            ->andThrow(new AiProviderRequestException(
                message: 'overloaded',
                provider: 'gemini',
                model: 'test-gemini',
                requestBodyRaw: '{}',
                responseBodyRaw: '{"error":"overloaded"}',
                httpStatus: 503,
            ));
        $this->app->instance(GeminiApiService::class, $gemini);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $schema = $this->v3AiNameRuntimeSchema($channel->id);
        data_set($schema, 'builder_v3_runtime.blocks.ai.ai_analysis.extract_fields', [[
            'key' => 'geo_city',
            'label' => 'Город',
            'type' => 'text',
        ]]);
        $scenario = $this->createPublishedScenario('v3_ai_retry_exhausted', $schema);

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();
        $answer = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'Москва',
        ]);

        (new ProcessScenarioInboundJob($answer->id, $run->id))
            ->handle(app(ScenarioRegistry::class));

        $request = AiRequest::query()->with('attempts')->sole();
        $run->refresh();
        $token = 'final-retry-token';
        $statePayload = $run->state_payload;
        data_set($statePayload, 'v3.ai_analysis_retry.ai', [
            'token' => $token,
            'cycle' => 4,
            'max_cycles' => 4,
            'ai_request_id' => $request->id,
            'message_id' => $answer->id,
            'reason' => 'temporary_provider_error',
        ]);
        $run->forceFill(['state_payload' => $statePayload])->save();

        (new RetryScenarioV3AiAnalysisJob(
            $run->id,
            $dialog->id,
            $answer->id,
            $scenario->code,
            $scenario->publishedVersion->id,
            'ai',
            $token,
            4,
        ))->handle(app(ScenarioRegistry::class));

        $request->refresh()->load('attempts');
        $run->refresh();

        $this->assertSame(AiRequest::STATUS_ERROR, $request->status);
        $this->assertCount(2, $request->attempts);
        $this->assertNull(data_get($run->state_payload, 'v3.ai_analysis_retry.ai'));
        $this->assertSame('ai_failed', data_get($run->state_payload, 'v3.ai_analysis.ai.output_id'));
        $this->assertSame('ai_failed', data_get($run->state_payload, 'v3.ai_analysis.ai.error_reason'));
        $this->assertSame('ai_failed_no_edge', data_get($run->state_payload, 'v3.ai_analysis.ai.route_error_reason'));
        $this->assertSame('ai', $run->current_step);
        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
    }

    public function test_v3_ai_analysis_normalizes_first_name_with_dictionary_before_contact_write(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9371]])
                ->push(['ok' => true, 'result' => ['message_id' => 9372]]),
        ]);

        DataDictionaryEntry::query()->create([
            'dictionary_key' => DataDictionaryEntry::DICTIONARY_NAMES,
            'lookup_value' => 'Клава',
            'result_value' => 'Клавдия',
            'gender' => DataDictionaryEntry::GENDER_FEMALE,
            'variant_type' => DataDictionaryEntry::VARIANT_TYPE_SHORT,
            'auto_apply' => true,
            'is_active' => true,
        ]);

        $gemini = Mockery::mock(GeminiApiService::class);
        $gemini
            ->shouldReceive('generateStructuredWithMetadata')
            ->once()
            ->andReturn(new AiProviderStructuredResult(
                provider: 'gemini',
                model: 'test-gemini',
                parsedPayload: ['output_id' => '1', 'data' => ['first_name' => 'Клава']],
                requestBodyRaw: '{}',
                responseBodyRaw: '{}',
                httpStatus: 200,
                inputTokens: 10,
                outputTokens: 5,
                totalTokens: 15,
            ));
        $this->app->instance(GeminiApiService::class, $gemini);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext(
            $channel,
            contactOverrides: [
                'first_name' => 'Abrikosov German',
                'first_name_source' => Contact::FIRST_NAME_SOURCE_AUTO,
            ],
        );
        $schema = $this->v3AiNameRuntimeSchema($channel->id);
        data_set($schema, 'builder_v3_runtime.blocks.accepted.message.text', 'Записали твое имя {{contact.first_name}}');
        $scenario = $this->createPublishedScenario('v3_ai_name_dictionary_normalization', $schema);

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();
        $answer = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'Клава',
        ]);

        (new ProcessScenarioInboundJob($answer->id, $run->id))
            ->handle(app(ScenarioRegistry::class));

        $run->refresh();
        $this->assertSame('Клавдия', data_get($run->state_payload, 'v3.ai_analysis.ai.data.first_name'));

        $contact->refresh();
        $this->assertSame('Клавдия', $contact->first_name);
        $this->assertSame(Contact::FIRST_NAME_SOURCE_CONTACT_CONFIRMED, $contact->first_name_source);
        $this->assertSame(Contact::FIRST_NAME_RESOLUTION_METHOD_AI_ANALYSIS, $contact->first_name_resolution_method);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['text'] === 'Записали твое имя Клавдия');
    }

    public function test_v3_action_missing_source_keeps_current_block_and_skips_success_message(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9381]]),
        ]);

        $gemini = Mockery::mock(GeminiApiService::class);
        $gemini
            ->shouldReceive('generateStructuredWithMetadata')
            ->once()
            ->andReturn(new AiProviderStructuredResult(
                provider: 'gemini',
                model: 'test-gemini',
                parsedPayload: ['output_id' => '1', 'data' => ['first_name' => '']],
                requestBodyRaw: '{}',
                responseBodyRaw: '{}',
                httpStatus: 200,
                inputTokens: 10,
                outputTokens: 5,
                totalTokens: 15,
            ));
        $this->app->instance(GeminiApiService::class, $gemini);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $schema = $this->v3AiNameRuntimeSchema($channel->id);
        data_set($schema, 'builder_v3_runtime.blocks.accepted.message.text', 'Записали твое имя {{contact.first_name}}');
        $scenario = $this->createPublishedScenario('v3_action_missing_source_stops', $schema);

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();
        $answer = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'не имя',
        ]);

        (new ProcessScenarioInboundJob($answer->id, $run->id))
            ->handle(app(ScenarioRegistry::class));

        $run->refresh();
        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('accepted', $run->current_step);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['text'] === 'Как вас зовут?');
    }

    public function test_v3_change_data_action_writes_static_contact_and_dialog_values(): void
    {
        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario('v3_static_change_data', $this->v3StaticChangeDataRuntimeSchema($channel->id));

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();

        $this->assertSame(ScenarioRun::STATUS_COMPLETED, $run->status);
        $this->assertNull($run->current_step);

        $contact->refresh();
        $dialog->refresh();

        $this->assertSame('female', $contact->gender);
        $this->assertSame('Московская область', $contact->region);
        $this->assertSame('female', data_get($dialog->fields_payload, 'selected_gender'));
    }

    public function test_v3_variables_action_increments_dialog_variable_and_follows_automatic_edge(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 9710],
            ]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario('v3_variables_increment', $this->v3VariablesRuntimeSchema($channel->id));

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $dialog->refresh();
        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();

        $this->assertSame(1, data_get($dialog->fields_payload, 'спросили_имя'));
        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('done', $run->current_step);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['text'] === 'Попытка записана');
    }

    #[DataProvider('v3Bitrix24SyncOperationProvider')]
    public function test_v3_bitrix24_sync_action_queues_operation_and_continues_block(
        string $operation,
        string $expectedQueueAction,
    ): void {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 9718],
            ]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $schema = $this->v3VariablesRuntimeSchema($channel->id);
        data_set($schema, 'builder_v3_runtime.blocks.variables.actions', [
            [
                'type' => 'bitrix24_sync',
                'operation' => $operation,
            ],
            [
                'type' => 'variables',
                'operations' => [[
                    'operation' => 'increment',
                    'field_key' => 'bitrix_after_sync',
                    'amount' => 1,
                ]],
            ],
        ]);
        $scenario = $this->createPublishedScenario('v3_bitrix24_sync_'.$operation, $schema);

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $expectedContact = Mockery::on(fn ($value): bool => $value instanceof Contact && $value->is($contact));
        $contactQueueAction = Mockery::mock(QueueBitrix24ContactSyncAction::class);
        $dealQueueAction = Mockery::mock(QueueBitrix24DealSyncAction::class);
        $historyQueueAction = Mockery::mock(QueueBitrix24HistoryExportAction::class);

        if ($expectedQueueAction === 'contact') {
            $contactQueueAction
                ->shouldReceive('handle')
                ->once()
                ->with($expectedContact)
                ->andReturn(new Bitrix24ContactSyncQueueResultData(
                    queued: true,
                    alreadyPending: false,
                    ready: true,
                    rootContactId: $contact->id,
                ));
        } else {
            $contactQueueAction->shouldReceive('handle')->never();
        }

        if ($expectedQueueAction === 'deal') {
            $dealQueueAction
                ->shouldReceive('handle')
                ->once()
                ->with($expectedContact)
                ->andReturn(new Bitrix24DealSyncQueueResultData(
                    queued: true,
                    alreadyPending: false,
                    ready: true,
                    rootContactId: $contact->id,
                ));
        } else {
            $dealQueueAction->shouldReceive('handle')->never();
        }

        if ($expectedQueueAction === 'history') {
            $historyQueueAction
                ->shouldReceive('handle')
                ->once()
                ->with($expectedContact)
                ->andReturn(new Bitrix24HistoryExportQueueResultData(
                    queued: true,
                    alreadyPending: false,
                    ready: true,
                    rootContactId: $contact->id,
                ));
        } else {
            $historyQueueAction->shouldReceive('handle')->never();
        }

        app()->instance(QueueBitrix24ContactSyncAction::class, $contactQueueAction);
        app()->instance(QueueBitrix24DealSyncAction::class, $dealQueueAction);
        app()->instance(QueueBitrix24HistoryExportAction::class, $historyQueueAction);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $dialog->refresh();
        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();

        $this->assertSame(1, data_get($dialog->fields_payload, 'bitrix_after_sync'));
        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('done', $run->current_step);
        $this->assertSame('queued', data_get($run->state_payload, 'v3.bitrix24_sync.last.status'));
        $this->assertSame($operation, data_get($run->state_payload, 'v3.bitrix24_sync.last.operation'));
        $this->assertSame($contact->id, data_get($run->state_payload, 'v3.bitrix24_sync.last.root_contact_id'));
        $this->assertTrue(data_get($run->state_payload, 'v3.bitrix24_sync.variables.0.queued'));

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['text'] === 'Попытка записана');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function v3Bitrix24SyncOperationProvider(): array
    {
        return [
            'contact sync' => ['contact_sync', 'contact'],
            'deal sync' => ['deal_sync', 'deal'],
            'history export' => ['history_export', 'history'],
            'contact sync with followups' => ['contact_sync_with_followups', 'contact'],
        ];
    }

    public function test_v3_variables_action_can_store_start_parameter(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 9713],
            ]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $schema = $this->v3VariablesRuntimeSchema($channel->id);
        data_set($schema, 'builder_v3_runtime.entrypoints.0.values', ['/start 123321']);
        data_set($schema, 'builder_v3_runtime.blocks.variables.actions.0.operations', [[
            'operation' => 'set',
            'field_key' => 'start_param',
            'value_source' => 'start_param',
            'value' => '',
        ]]);
        $scenario = $this->createPublishedScenario('v3_variables_start_param', $schema);

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => '/start 123321',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $dialog->refresh();
        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();

        $this->assertSame('123321', data_get($dialog->fields_payload, 'start_param'));
        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('done', $run->current_step);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['text'] === 'Попытка записана');
    }

    public function test_v3_variables_action_can_store_message_parameter_start_parameter(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 9714],
            ]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $schema = $this->v3VariablesRuntimeSchema($channel->id);
        data_set($schema, 'builder_v3_runtime.entrypoints.0.values', ['/start fallback']);
        data_set($schema, 'builder_v3_runtime.blocks.variables.actions.0.operations', [[
            'operation' => 'set',
            'field_key' => 'start_param',
            'value_source' => 'start_param',
            'value' => '',
        ]]);
        $scenario = $this->createPublishedScenario('v3_variables_message_parameter_start_param', $schema);

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => '/start fallback',
            'message_parameter' => '123321',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $dialog->refresh();

        $this->assertSame('123321', data_get($dialog->fields_payload, 'start_param'));
    }

    public function test_v3_simulate_start_parameter_reroutes_to_matching_start_block(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 9715],
            ]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $dialog->forceFill(['fields_payload' => ['start_param' => 'payload_42']])->save();
        $scenario = $this->createPublishedScenario('v3_simulate_start_parameter', $this->v3SimulateStartParameterRuntimeSchema($channel->id));

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'анкета',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $dialog->refresh();
        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();

        $this->assertSame('payload_42', data_get($dialog->fields_payload, 'start_param'));
        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('payload_start', $run->current_step);
        $this->assertSame('payload_start', data_get($run->state_payload, 'v3.current_block_id'));
        $this->assertSame(1, Message::query()
            ->where('dialog_id', $dialog->id)
            ->where('direction', Message::DIRECTION_INBOUND)
            ->count());

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['text'] === 'Сработал параметр payload_42');
        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['text'] === 'Обычное продолжение');
    }

    public function test_v3_simulate_start_parameter_continues_when_parameter_has_no_start_block(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 9716],
            ]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $dialog->forceFill(['fields_payload' => ['start_param' => 'unknown_payload']])->save();
        $scenario = $this->createPublishedScenario('v3_simulate_start_parameter_noop', $this->v3SimulateStartParameterRuntimeSchema($channel->id));

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'анкета',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();

        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('after_noop', $run->current_step);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['text'] === 'Обычное продолжение');
    }

    public function test_v3_variables_action_fails_and_rolls_back_when_increment_value_is_not_numeric(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 9711],
            ]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $dialog->forceFill(['fields_payload' => ['спросили_имя' => 'abc', 'другое' => 'ok']])->save();
        $scenario = $this->createPublishedScenario('v3_variables_failed', $this->v3VariablesRuntimeSchema($channel->id));

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $dialog->refresh();
        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();

        $this->assertSame('abc', data_get($dialog->fields_payload, 'спросили_имя'));
        $this->assertSame('ok', data_get($dialog->fields_payload, 'другое'));
        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('variables', $run->current_step);

        Http::assertNothingSent();
    }

    public function test_v3_message_can_pick_text_by_dialog_variable(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 9712],
            ]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $dialog->forceFill(['fields_payload' => ['спросили_имя' => 2]])->save();
        $scenario = $this->createPublishedScenario('v3_variable_message', $this->v3VariableMessageRuntimeSchema($channel->id));

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['text'] === 'Напиши имя полностью');
    }

    public function test_v3_message_can_pick_text_by_numeric_dialog_variable_operator(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 9713],
            ]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $dialog->forceFill(['fields_payload' => ['спросили_имя' => 3]])->save();
        $schema = $this->v3VariableMessageRuntimeSchema($channel->id);
        data_set($schema, 'builder_v3_runtime.blocks.ask_name.message.variable_text_variants', [
            [
                'operator' => 'eq',
                'value' => '1',
                'text' => 'Как тебя зовут?',
            ],
            [
                'operator' => 'gte',
                'value' => '3',
                'text' => "Три попытки прошло\nПередаю оператору",
            ],
        ]);
        $scenario = $this->createPublishedScenario('v3_variable_message_operator', $schema);

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['text'] === "Три попытки прошло\nПередаю оператору");
    }

    public function test_v3_edge_expression_supports_numeric_dialog_variables_with_cyrillic_keys(): void
    {
        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $dialog->forceFill(['fields_payload' => ['спросили_имя' => '2']])->save();
        $message = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'ответ',
        ]);

        $condition = app(ScenarioEdgeExpressionCondition::class);

        $this->assertTrue($condition->evaluate('{{dialog.спросили_имя}} < 3', $message));
        $this->assertFalse($condition->evaluate('{{dialog.спросили_имя}} >= 3', $message));
        $this->assertTrue($condition->evaluate('{{dialog.новая_переменная}} == 0', $message));
    }

    public function test_v3_edge_expression_supports_parentheses_for_grouped_conditions(): void
    {
        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $dialog->forceFill(['fields_payload' => ['сколько_раз_спросили_имя' => 1]])->save();
        $contact->forceFill([
            'first_name' => '',
            'first_name_source' => 'auto',
        ])->save();
        $message = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'ответ',
        ]);

        $condition = app(ScenarioEdgeExpressionCondition::class);
        $expression = '({{contact.first_name}} == "" or {{contact.first_name_source}} == "" or {{contact.first_name_source}} == "auto") and {{dialog.сколько_раз_спросили_имя}} == 1';

        $this->assertTrue($condition->evaluate($expression, $message));
        $this->assertFalse($condition->evaluate(str_replace('== 1', '== 2', $expression), $message));
    }

    public function test_v3_edge_expression_keeps_and_priority_over_or_without_parentheses(): void
    {
        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $dialog->forceFill(['fields_payload' => ['count' => 2]])->save();
        $contact->forceFill(['first_name' => 'Герман'])->save();
        $message = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'ответ',
        ]);

        $condition = app(ScenarioEdgeExpressionCondition::class);

        $this->assertTrue($condition->evaluate('{{dialog.count}} == 2 or {{dialog.count}} == 3 and {{contact.first_name}} == ""', $message));
        $this->assertFalse($condition->evaluate('({{dialog.count}} == 2 or {{dialog.count}} == 3) and {{contact.first_name}} == ""', $message));
    }

    public function test_v3_edge_expression_rejects_unclosed_parenthesis(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(ScenarioEdgeExpressionCondition::class)
            ->assertValid('({{dialog.count}} == 1 or {{dialog.count}} == 2');
    }

    public function test_v3_distance_to_moscow_action_calculates_for_ru_country_and_follows_resolved_edge(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 9701],
            ]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $contact->forceFill([
            'country' => 'RU',
            'city' => 'Москва',
            'distance_to_moscow_km' => null,
            'distance_to_moscow_status' => null,
            'distance_to_moscow_calculated_at' => null,
        ])->save();

        $scenario = $this->createPublishedScenario(
            'v3_distance_to_moscow',
            $this->v3DistanceToMoscowRuntimeSchema($channel->id),
        );

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();
        $contact->refresh();

        $this->assertSame(0, $contact->distance_to_moscow_km);
        $this->assertSame(Contact::DISTANCE_TO_MOSCOW_STATUS_RESOLVED, $contact->distance_to_moscow_status);
        $this->assertNotNull($contact->distance_to_moscow_calculated_at);
        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('done', $run->current_step);
        $this->assertSame('distance_resolved', data_get($run->state_payload, 'v3.distance_to_moscow.distance.output_id'));

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === $dialog->external_chat_id
            && $request['text'] === 'Расстояние рассчитано');
    }

    public function test_v3_geo_city_action_writes_contact_and_follows_found_edge(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9761]])
                ->push(['ok' => true, 'result' => ['message_id' => 9762]]),
        ]);
        $this->seed(GeoDictionarySeeder::class);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel, [
            'country' => null,
            'region' => null,
            'city' => null,
        ]);
        $scenario = $this->createPublishedScenario(
            'v3_geo_city_found',
            $this->v3GeoCityRuntimeSchema($channel->id),
        );

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();
        $run->forceFill([
            'state_payload' => array_replace_recursive($run->state_payload ?? [], [
                'v3' => [
                    'geo_resolution_attempts' => [
                        'resolve_geo' => 2,
                    ],
                ],
            ]),
        ])->save();

        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'я из мск');

        $run->refresh();
        $contact->refresh();

        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('geo_found_done', $run->current_step);
        $this->assertSame('Россия', $contact->country);
        $this->assertSame('Москва', $contact->region);
        $this->assertSame('Москва', $contact->city);
        $this->assertNull(data_get($run->state_payload, 'v3.geo_resolution_attempts.resolve_geo'));

        $this->assertDatabaseHas('geo_resolution_events', [
            'contact_id' => $contact->id,
            'dialog_id' => $dialog->id,
            'status' => ResolveGeoCityAction::STATUS_MATCHED_CITY,
            'matched_alias' => 'мск',
            'country' => 'Россия',
            'region' => 'Москва',
            'city' => 'Москва',
        ]);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === $dialog->external_chat_id
            && $request['text'] === 'Город найден');
    }

    public function test_v3_geo_city_action_routes_ambiguous_to_manual_required_without_mutating_contact(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9771]])
                ->push(['ok' => true, 'result' => ['message_id' => 9772]]),
        ]);
        $this->seed(GeoDictionarySeeder::class);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel, [
            'country' => 'Россия',
            'region' => 'Старый регион',
            'city' => 'Старый город',
        ]);
        $scenario = $this->createPublishedScenario(
            'v3_geo_city_ambiguous',
            $this->v3GeoCityRuntimeSchema($channel->id),
        );

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();

        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'я из химок, сейчас в москве');

        $run->refresh();
        $contact->refresh();

        $this->assertSame('geo_manual_required_done', $run->current_step);
        $this->assertSame('Россия', $contact->country);
        $this->assertSame('Старый регион', $contact->region);
        $this->assertSame('Старый город', $contact->city);
        $this->assertSame(1, data_get($run->state_payload, 'v3.geo_resolution_attempts.resolve_geo'));
        $this->assertSame('ambiguous_city', data_get($run->state_payload, 'v3.geo_resolution_pending.resolve_geo.reason'));
        $this->assertCount(2, data_get($run->state_payload, 'v3.geo_resolution_pending.resolve_geo.candidates'));

        $this->assertDatabaseHas('geo_resolution_events', [
            'contact_id' => $contact->id,
            'dialog_id' => $dialog->id,
            'status' => ResolveGeoCityAction::STATUS_AMBIGUOUS,
        ]);
    }

    public function test_v3_geo_city_action_preserves_legacy_ambiguous_output_for_old_snapshot(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9773]])
                ->push(['ok' => true, 'result' => ['message_id' => 9774]]),
        ]);
        $this->seed(GeoDictionarySeeder::class);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel, [
            'country' => 'Россия',
            'region' => 'Старый регион',
            'city' => 'Старый город',
        ]);
        $scenario = $this->createPublishedScenario(
            'v3_geo_city_legacy_ambiguous',
            $this->v3GeoCityRuntimeSchema($channel->id, legacyGeoOutputs: true),
        );

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();

        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'я из химок, сейчас в москве');

        $run->refresh();

        $this->assertSame('geo_ambiguous_done', $run->current_step);
        $this->assertSame(1, data_get($run->state_payload, 'v3.geo_resolution_attempts.resolve_geo'));
    }

    public function test_v3_geo_city_action_handles_missing_current_inbound_message(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9781]])
                ->push(['ok' => true, 'result' => ['message_id' => 9782]]),
        ]);
        $this->seed(GeoDictionarySeeder::class);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario(
            'v3_geo_city_missing_inbound',
            $this->v3GeoCityRuntimeSchema($channel->id),
        );

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();

        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, '', [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_SCENARIO_MESSAGE,
            'sent_by_type' => Message::SENT_BY_TYPE_SYSTEM,
        ]);

        $run->refresh();
        $event = GeoResolutionEvent::query()->where('contact_id', $contact->id)->firstOrFail();

        $this->assertSame('geo_not_found_done', $run->current_step);
        $this->assertSame(1, data_get($run->state_payload, 'v3.geo_resolution_attempts.resolve_geo'));
        $this->assertSame(ResolveGeoCityAction::STATUS_NOT_FOUND, $event->status);
        $this->assertSame('missing_current_inbound_message', data_get($event->payload, 'reason'));
    }

    public function test_v3_geo_city_action_ignores_legacy_internal_limit_and_runs_resolver(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9791]])
                ->push(['ok' => true, 'result' => ['message_id' => 9792]]),
        ]);
        $this->seed(GeoDictionarySeeder::class);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel, [
            'country' => null,
            'region' => null,
            'city' => null,
        ]);
        $scenario = $this->createPublishedScenario(
            'v3_geo_city_limit',
            $this->v3GeoCityRuntimeSchema($channel->id),
        );

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();
        $run->forceFill([
            'state_payload' => array_replace_recursive($run->state_payload ?? [], [
                'v3' => [
                    'geo_resolution_attempts' => [
                        'resolve_geo' => 3,
                    ],
                ],
            ]),
        ])->save();

        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'я из мск');

        $run->refresh();
        $contact->refresh();

        $this->assertSame('geo_found_done', $run->current_step);
        $this->assertNull(data_get($run->state_payload, 'v3.geo_resolution_attempts.resolve_geo'));
        $this->assertSame('Россия', $contact->country);
        $this->assertSame('Москва', $contact->region);
        $this->assertSame('Москва', $contact->city);
        $event = GeoResolutionEvent::query()->where('contact_id', $contact->id)->firstOrFail();
        $this->assertSame(ResolveGeoCityAction::STATUS_MATCHED_CITY, $event->status);
        $this->assertSame('matched_city', data_get($event->payload, 'resolver_status'));
        $this->assertSame('geo_found', data_get($event->payload, 'final_output'));
    }

    public function test_v3_geo_city_action_writes_contact_from_ai_analysis_data(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9793]])
                ->push(['ok' => true, 'result' => ['message_id' => 9794]]),
        ]);
        $this->seed(GeoDictionarySeeder::class);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel, [
            'country' => null,
            'region' => null,
            'city' => null,
        ]);
        $scenario = $this->createPublishedScenario(
            'v3_geo_city_ai_data_found',
            $this->v3GeoCityRuntimeSchema($channel->id, geoAction: $this->v3GeoCityAiDataAction()),
        );

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();
        $run->forceFill([
            'state_payload' => array_replace_recursive($run->state_payload ?? [], [
                'v3' => [
                    'geo_resolution_attempts' => [
                        'resolve_geo' => 2,
                    ],
                    'ai_analysis' => [
                        'ai_city' => [
                            'output_id' => 'city_found',
                            'label' => 'Город найден',
                            'ai_request_id' => 123,
                            'data' => [
                                'geo_city' => 'Химки',
                                'geo_region' => 'Московская область',
                                'geo_country' => 'Россия',
                            ],
                        ],
                    ],
                ],
            ]),
        ])->save();

        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'дальше');

        $run->refresh();
        $contact->refresh();
        $event = GeoResolutionEvent::query()->where('contact_id', $contact->id)->firstOrFail();

        $this->assertSame('geo_found_done', $run->current_step);
        $this->assertSame('Россия', $contact->country);
        $this->assertSame('Московская область', $contact->region);
        $this->assertSame('Химки', $contact->city);
        $this->assertNull(data_get($run->state_payload, 'v3.geo_resolution_attempts.resolve_geo'));
        $this->assertSame(ResolveGeoCityAction::STATUS_MATCHED_CITY, $event->status);
        $this->assertSame('ai_data', data_get($event->payload, 'source'));
        $this->assertSame('city_found', data_get($event->payload, 'ai_output_id'));
        $this->assertSame('Химки', data_get($event->payload, 'ai_city'));
        $this->assertSame('Химки Московская область Россия', data_get($event->payload, 'resolver_input'));
        $this->assertTrue(data_get($event->payload, 'resolver_ran'));
        $this->assertSame('geo_found', data_get($event->payload, 'final_output'));
    }

    public function test_v3_geo_city_action_routes_ai_city_not_confirmed_to_not_found(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9795]])
                ->push(['ok' => true, 'result' => ['message_id' => 9796]]),
        ]);
        $this->seed(GeoDictionarySeeder::class);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel, [
            'country' => 'Россия',
            'region' => 'Старый регион',
            'city' => 'Старый город',
        ]);
        $scenario = $this->createPublishedScenario(
            'v3_geo_city_ai_data_not_found',
            $this->v3GeoCityRuntimeSchema($channel->id, geoAction: $this->v3GeoCityAiDataAction()),
        );

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();
        $run->forceFill([
            'state_payload' => array_replace_recursive($run->state_payload ?? [], [
                'v3' => [
                    'ai_analysis' => [
                        'ai_city' => [
                            'output_id' => 'city_found',
                            'label' => 'Город найден',
                            'data' => [
                                'geo_city' => 'Омск',
                                'geo_region' => 'Омская область',
                                'geo_country' => 'Россия',
                            ],
                        ],
                    ],
                ],
            ]),
        ])->save();

        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'дальше');

        $run->refresh();
        $contact->refresh();
        $event = GeoResolutionEvent::query()->where('contact_id', $contact->id)->firstOrFail();

        $this->assertSame('geo_not_found_done', $run->current_step);
        $this->assertSame('Россия', $contact->country);
        $this->assertSame('Старый регион', $contact->region);
        $this->assertSame('Старый город', $contact->city);
        $this->assertSame(1, data_get($run->state_payload, 'v3.geo_resolution_attempts.resolve_geo'));
        $this->assertSame(ResolveGeoCityAction::STATUS_NOT_FOUND, $event->status);
        $this->assertSame('geo_not_found', data_get($event->payload, 'final_output'));
        $this->assertSame('Омск Омская область Россия', data_get($event->payload, 'resolver_input'));
        $this->assertTrue(data_get($event->payload, 'resolver_ran'));
    }

    public function test_v3_geo_city_action_counts_missing_ai_data_and_writes_diagnostic_event(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9797]])
                ->push(['ok' => true, 'result' => ['message_id' => 9798]]),
        ]);
        $this->seed(GeoDictionarySeeder::class);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario(
            'v3_geo_city_missing_ai_data',
            $this->v3GeoCityRuntimeSchema($channel->id, geoAction: $this->v3GeoCityAiDataAction()),
        );

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();

        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'дальше');

        $run->refresh();
        $event = GeoResolutionEvent::query()->where('contact_id', $contact->id)->firstOrFail();

        $this->assertSame('geo_not_found_done', $run->current_step);
        $this->assertSame(1, data_get($run->state_payload, 'v3.geo_resolution_attempts.resolve_geo'));
        $this->assertSame(ResolveGeoCityAction::STATUS_NOT_FOUND, $event->status);
        $this->assertSame('missing_ai_data', data_get($event->payload, 'reason'));
        $this->assertFalse(data_get($event->payload, 'resolver_ran'));
        $this->assertNull($event->source_text);
    }

    public function test_v3_geo_city_action_counts_missing_ai_city_and_writes_diagnostic_event(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9799]])
                ->push(['ok' => true, 'result' => ['message_id' => 9800]]),
        ]);
        $this->seed(GeoDictionarySeeder::class);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario(
            'v3_geo_city_missing_ai_city',
            $this->v3GeoCityRuntimeSchema($channel->id, geoAction: $this->v3GeoCityAiDataAction()),
        );

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();
        $run->forceFill([
            'state_payload' => array_replace_recursive($run->state_payload ?? [], [
                'v3' => [
                    'ai_analysis' => [
                        'ai_city' => [
                            'output_id' => 'city_found',
                            'label' => 'Город не найден',
                            'data' => [
                                'geo_region' => 'Московская область',
                                'geo_country' => 'Россия',
                            ],
                        ],
                    ],
                ],
            ]),
        ])->save();

        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'дальше');

        $run->refresh();
        $event = GeoResolutionEvent::query()->where('contact_id', $contact->id)->firstOrFail();

        $this->assertSame('geo_not_found_done', $run->current_step);
        $this->assertSame(1, data_get($run->state_payload, 'v3.geo_resolution_attempts.resolve_geo'));
        $this->assertSame('missing_ai_city', data_get($event->payload, 'reason'));
        $this->assertSame('Московская область', data_get($event->payload, 'ai_region'));
        $this->assertFalse(data_get($event->payload, 'resolver_ran'));
    }

    public function test_v3_geo_city_action_uses_ai_data_even_when_legacy_internal_limit_exists(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9801]])
                ->push(['ok' => true, 'result' => ['message_id' => 9802]]),
        ]);
        $this->seed(GeoDictionarySeeder::class);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario(
            'v3_geo_city_ai_data_limit',
            $this->v3GeoCityRuntimeSchema($channel->id, geoAction: $this->v3GeoCityAiDataAction()),
        );

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();
        $run->forceFill([
            'state_payload' => array_replace_recursive($run->state_payload ?? [], [
                'v3' => [
                    'geo_resolution_attempts' => [
                        'resolve_geo' => 3,
                    ],
                    'ai_analysis' => [
                        'ai_city' => [
                            'output_id' => 'city_found',
                            'label' => 'Город найден',
                            'data' => [
                                'geo_city' => 'Химки',
                                'geo_region' => 'Московская область',
                                'geo_country' => 'Россия',
                            ],
                        ],
                    ],
                ],
            ]),
        ])->save();

        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'дальше');

        $run->refresh();

        $contact->refresh();

        $this->assertSame('geo_found_done', $run->current_step);
        $this->assertNull(data_get($run->state_payload, 'v3.geo_resolution_attempts.resolve_geo'));
        $this->assertSame('Россия', $contact->country);
        $this->assertSame('Московская область', $contact->region);
        $this->assertSame('Химки', $contact->city);
        $event = GeoResolutionEvent::query()->where('contact_id', $contact->id)->firstOrFail();
        $this->assertSame(ResolveGeoCityAction::STATUS_MATCHED_CITY, $event->status);
        $this->assertSame('ai_data', data_get($event->payload, 'source'));
        $this->assertSame('matched_city', data_get($event->payload, 'resolver_status'));
        $this->assertSame('geo_found', data_get($event->payload, 'final_output'));
    }

    public function test_v3_unsupported_legacy_action_uses_failed_output(): void
    {
        Http::fake([
            'https://api.telegram.org/*sendMessage' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 9751],
            ]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel, [
            'gender' => 'unknown',
            'first_name' => null,
            'country' => null,
            'city' => null,
            'region' => null,
            'age_range' => null,
        ]);
        $schema = $this->v3UnsupportedActionRuntimeSchema($channel->id);
        $failedEdge = [
            'id' => 'edge_failed',
            'edge_key' => 'edge_failed',
            'mode' => 'action_result',
            'priority' => 10,
            'transition_limit' => 0,
            'source_block_id' => 'legacy_action',
            'target_block_id' => 'after_failed',
            'from_output_id' => 'failed',
            'label' => 'Старое действие отключено',
        ];
        $schema['builder_v3_runtime']['blocks']['legacy_action']['action_result_edges'][] = $failedEdge;
        $schema['builder_v3_runtime']['edges'][] = $failedEdge;
        $schema['builder_v3_runtime']['blocks']['after_failed'] = [
            'id' => 'after_failed',
            'db_id' => 99,
            'kind' => 'state',
            'title' => 'Старое действие отключено',
            'actions' => null,
            'message' => [
                'text' => 'Старое действие отключено',
                'text_format' => Message::TEXT_FORMAT_PLAIN_TEXT,
            ],
            'buttons' => null,
            'wait_reply_edges' => [],
            'automatic_edges' => [],
            'action_result_edges' => [],
            'default_target_block_id' => null,
        ];
        $scenario = $this->createPublishedScenario('v3_unsupported_legacy_action', $schema);

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $scenarioRuns = ScenarioRun::query()
            ->where('scenario_code', $scenario->code)
            ->orderBy('id')
            ->get();
        $outboundTexts = Message::query()
            ->where('direction', Message::DIRECTION_OUTBOUND)
            ->where('message_kind', Message::KIND_OUTBOUND_SCENARIO_MESSAGE)
            ->orderBy('id')
            ->pluck('text')
            ->all();

        $this->assertCount(1, $scenarioRuns);
        $this->assertSame([
            'Старое действие отключено',
        ], $outboundTexts);
    }

    public function test_v3_edit_message_action_removes_previous_inline_buttons_and_cleans_reply_keyboard_on_next_plain_message(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9601]])
                ->push(['ok' => true, 'result' => true])
                ->push(['ok' => true, 'result' => ['message_id' => 9602]]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario(
            'v3_edit_message_remove_buttons',
            $this->v3EditMessageRemoveButtonsRuntimeSchema($channel->id),
        );

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();
        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Убрать кнопки');

        $run->refresh();

        $this->assertSame('done', $run->current_step);
        $this->assertFalse((bool) data_get($run->state_payload, 'v3.pending_remove_telegram_keyboard', false));

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/editMessageReplyMarkup'
            && $request['chat_id'] === $dialog->external_chat_id
            && (string) $request['message_id'] === '9601'
            && data_get($request->data(), 'reply_markup.inline_keyboard') === []);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['text'] === 'Кнопки убраны'
            && data_get($request->data(), 'reply_markup.remove_keyboard') === null);
    }

    public function test_v3_edit_message_action_deletes_previous_message_and_continues(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9631]])
                ->push(['ok' => true, 'result' => true])
                ->push(['ok' => true, 'result' => ['message_id' => 9632]]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario(
            'v3_edit_message_delete_message',
            $this->v3EditMessageRemoveButtonsRuntimeSchema(
                $channel->id,
                operation: 'delete_message',
                target: 'last_current_run_outbound',
            ),
        );

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();
        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Убрать кнопки');

        $run->refresh();

        $this->assertSame('done', $run->current_step);
        $this->assertFalse((bool) data_get($run->state_payload, 'v3.pending_remove_telegram_keyboard', false));

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/deleteMessage'
            && $request['chat_id'] === $dialog->external_chat_id
            && (string) $request['message_id'] === '9631');
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['text'] === 'Кнопки убраны'
            && data_get($request->data(), 'reply_markup.remove_keyboard') === true);
    }

    public function test_v3_edit_message_action_on_start_deletes_last_dialog_outbound_message(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => true])
                ->push(['ok' => true, 'result' => ['message_id' => 9642]]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);

        $previousOutbound = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'external_chat_id' => $dialog->external_chat_id,
            'external_message_id' => '9641',
            'text' => 'Предыдущее сообщение',
        ]);
        $dialog->forceFill([
            'last_outbound_message_id' => $previousOutbound->id,
            'last_outbound_message_preview' => 'Предыдущее сообщение',
        ])->save();

        $scenario = $this->createPublishedScenario(
            'v3_edit_message_delete_on_start',
            $this->v3EditMessageDeleteOnStartRuntimeSchema($channel->id),
        );

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/deleteMessage'
            && $request['chat_id'] === $dialog->external_chat_id
            && (string) $request['message_id'] === '9641');
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['text'] === 'Новое стартовое сообщение');

        $previousOutbound->refresh();
        $this->assertSame(DeleteLastOutboundDialogMessageAction::STATUS_DELETED, data_get($previousOutbound->raw_payload, 'delete_action_result'));
    }

    public function test_v3_delete_message_action_cleans_reply_keyboard_before_next_inline_buttons(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9611]])
                ->push(['ok' => true, 'result' => true])
                ->push(['ok' => true, 'result' => ['message_id' => 9612]])
                ->push(['ok' => true, 'result' => true])
                ->push(['ok' => true, 'result' => ['message_id' => 9613]]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario(
            'v3_edit_message_defer_keyboard_cleanup',
            $this->v3EditMessageRemoveButtonsRuntimeSchema(
                $channel->id,
                operation: 'delete_message',
                target: 'last_current_run_outbound',
                nextHasInlineButtons: true,
            ),
        );

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();
        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Убрать кнопки');

        $run->refresh();

        $this->assertFalse((bool) data_get($run->state_payload, 'v3.pending_remove_telegram_keyboard', false));

        Http::assertSentCount(5);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/deleteMessage'
            && $request['chat_id'] === $dialog->external_chat_id
            && (string) $request['message_id'] === '9611');
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && data_get($request->data(), 'reply_markup.remove_keyboard') === true
            && ($request['disable_notification'] ?? false) === true);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/deleteMessage'
            && $request['chat_id'] === $dialog->external_chat_id
            && (string) $request['message_id'] === '9612');

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['text'] === 'Выберите следующий шаг'
            && data_get($request->data(), 'reply_markup.inline_keyboard.0.0.text') === 'Дальше'
            && data_get($request->data(), 'reply_markup.remove_keyboard') === null);
    }

    public function test_v3_edit_message_action_logs_telegram_error_and_continues(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9621]])
                ->push(['ok' => false, 'description' => 'Bad Request'], 400)
                ->push(['ok' => true, 'result' => ['message_id' => 9622]]),
        ]);
        Log::spy();

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario(
            'v3_edit_message_error_continues',
            $this->v3EditMessageRemoveButtonsRuntimeSchema($channel->id),
        );

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();
        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Убрать кнопки');

        $run->refresh();

        $this->assertSame('done', $run->current_step);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['text'] === 'Кнопки убраны');
        Log::shouldHaveReceived('warning')
            ->with('scenario.v3_edit_message_remove_buttons_failed', Mockery::on(fn (array $context): bool => ($context['exception'] ?? null) !== null
                && ($context['scenario_code'] ?? null) === $scenario->code
                && ($context['scenario_run_id'] ?? null) === $run->id));
    }

    public function test_v3_wait_reply_delivery_failure_stays_pending_and_can_retry(): void
    {
        Queue::fake([
            ProcessScenarioV3OutboundMessageJob::class,
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario('v3_wait_reply_outbox_retry', $this->v3CatalogRuntimeSchema($channel->id));
        $sendCount = 0;
        $sendAction = Mockery::mock(SendBotDialogTextAction::class);
        $sendAction
            ->shouldReceive('handleMessage')
            ->twice()
            ->andReturnUsing(function (...$args) use (&$sendCount, $dialog): BotDialogTextSendResult {
                $sendCount++;
                $text = (string) $args[1];

                if ($text === 'Вот каталог') {
                    return $this->blockedBotSendResult($dialog);
                }

                return $this->successfulBotSendResult($dialog, $text, 'mock-v3-outbox-'.$sendCount);
            });
        $this->app->instance(SendBotDialogTextAction::class, $sendAction);

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();

        $buttonMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'Получить каталог',
        ]);

        (new ProcessScenarioInboundJob($buttonMessage->id, $run->id))
            ->handle(app(ScenarioRegistry::class));

        $run->refresh();
        $pendingOutbound = ScenarioV3OutboundMessage::query()
            ->where('text', 'Вот каталог')
            ->firstOrFail();

        $this->assertSame('catalog', $run->current_step);
        $this->assertSame(ScenarioV3OutboundMessage::STATUS_PENDING, $pendingOutbound->status);
        $this->assertSame(1, $pendingOutbound->attempts);
        $this->assertSame('Канал не принял V3-сообщение.', $pendingOutbound->error_message);
        $this->assertCount(1, Message::query()->where('direction', Message::DIRECTION_OUTBOUND)->get());
        Queue::assertPushed(ProcessScenarioV3OutboundMessageJob::class, fn (ProcessScenarioV3OutboundMessageJob $job): bool => $job->outboundMessageId === $pendingOutbound->id);

        $retryAction = Mockery::mock(SendBotDialogTextAction::class);
        $retryAction
            ->shouldReceive('handleMessage')
            ->once()
            ->andReturnUsing(fn (...$args): BotDialogTextSendResult => $this->successfulBotSendResult($dialog, (string) $args[1], 'mock-v3-outbox-retry'));
        $this->app->instance(SendBotDialogTextAction::class, $retryAction);

        $this->travelTo($pendingOutbound->available_at->copy()->addSecond());

        (new ProcessScenarioV3OutboundMessageJob($pendingOutbound->id))
            ->handle(app(ScenarioRegistry::class));

        $pendingOutbound->refresh();

        $this->assertSame(ScenarioV3OutboundMessage::STATUS_SENT, $pendingOutbound->status);
        $this->assertSame(2, $pendingOutbound->attempts);
        $this->assertCount(2, Message::query()->where('direction', Message::DIRECTION_OUTBOUND)->get());
    }

    public function test_v3_outbound_stale_processing_message_can_be_reclaimed(): void
    {
        Queue::fake([
            ProcessScenarioV3OutboundMessageJob::class,
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario('v3_wait_reply_outbox_stale', $this->v3CatalogRuntimeSchema($channel->id));
        $sendCount = 0;
        $sendAction = Mockery::mock(SendBotDialogTextAction::class);
        $sendAction
            ->shouldReceive('handleMessage')
            ->twice()
            ->andReturnUsing(function (...$args) use (&$sendCount, $dialog): BotDialogTextSendResult {
                $sendCount++;
                $text = (string) $args[1];

                if ($text === 'Вот каталог') {
                    return $this->blockedBotSendResult($dialog);
                }

                return $this->successfulBotSendResult($dialog, $text, 'mock-v3-stale-'.$sendCount);
            });
        $this->app->instance(SendBotDialogTextAction::class, $sendAction);

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();

        $buttonMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'Получить каталог',
        ]);

        (new ProcessScenarioInboundJob($buttonMessage->id, $run->id))
            ->handle(app(ScenarioRegistry::class));

        $staleOutbound = ScenarioV3OutboundMessage::query()
            ->where('text', 'Вот каталог')
            ->firstOrFail();
        $staleOutbound->forceFill([
            'status' => ScenarioV3OutboundMessage::STATUS_PROCESSING,
            'processing_started_at' => now()->subMinutes(11),
            'available_at' => null,
        ])->save();

        $retryAction = Mockery::mock(SendBotDialogTextAction::class);
        $retryAction
            ->shouldReceive('handleMessage')
            ->once()
            ->andReturnUsing(fn (...$args): BotDialogTextSendResult => $this->successfulBotSendResult($dialog, (string) $args[1], 'mock-v3-stale-reclaimed'));
        $this->app->instance(SendBotDialogTextAction::class, $retryAction);

        (new ProcessScenarioV3OutboundMessageJob($staleOutbound->id))
            ->handle(app(ScenarioRegistry::class));

        $staleOutbound->refresh();

        $this->assertSame(ScenarioV3OutboundMessage::STATUS_SENT, $staleOutbound->status);
        $this->assertSame(2, $staleOutbound->attempts);
        $this->assertCount(2, Message::query()->where('direction', Message::DIRECTION_OUTBOUND)->get());
    }

    public function test_v3_outbound_stale_processing_after_external_send_start_becomes_uncertain(): void
    {
        Queue::fake([
            ProcessScenarioV3OutboundMessageJob::class,
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario('v3_outbox_stale_uncertain', $this->v3CatalogRuntimeSchema($channel->id));

        $run = ScenarioRun::query()->create([
            'dialog_id' => $dialog->id,
            'scenario_code' => $scenario->code,
            'status' => ScenarioRun::STATUS_ACTIVE,
            'current_step' => 'catalog',
            'state_payload' => [
                'v3' => [
                    'published_version_id' => $scenario->publishedVersion?->id,
                    'current_block_id' => 'catalog',
                ],
            ],
            'started_at' => now(),
        ]);

        $inboundMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'Получить каталог',
        ]);

        $outbound = ScenarioV3OutboundMessage::query()->create([
            'scenario_run_id' => $run->id,
            'dialog_id' => $dialog->id,
            'channel_id' => $channel->id,
            'inbound_message_id' => $inboundMessage->id,
            'published_version_id' => $scenario->publishedVersion?->id,
            'scenario_code' => $scenario->code,
            'block_id' => 'catalog',
            'text' => 'Вот каталог',
            'text_format' => Message::TEXT_FORMAT_PLAIN_TEXT,
            'delivery_payload' => [
                'request_phone' => false,
                'remove_telegram_keyboard' => false,
                'reply_button_rows' => null,
                'button_placement' => 'auto',
                'v3_callback_block_id' => 'catalog',
                'external_delivery_started_at' => now()->subMinutes(11)->toJSON(),
            ],
            'status' => ScenarioV3OutboundMessage::STATUS_PROCESSING,
            'attempts' => 1,
            'processing_started_at' => now()->subMinutes(11),
        ]);

        $sendAction = Mockery::mock(SendBotDialogTextAction::class);
        $sendAction->shouldNotReceive('handleMessage');
        $this->app->instance(SendBotDialogTextAction::class, $sendAction);

        (new ProcessScenarioV3OutboundMessageJob($outbound->id))
            ->handle(app(ScenarioRegistry::class));

        $outbound->refresh();
        $run->refresh();

        $this->assertSame(ScenarioV3OutboundMessage::STATUS_FAILED_UNCERTAIN, $outbound->status);
        $this->assertSame(1, $outbound->attempts);
        $this->assertSame('Отправка зависла после начала внешней доставки; автоматический повтор остановлен.', $outbound->error_message);
        $this->assertSame($outbound->id, data_get($run->state_payload, 'v3.delivery_error.outbound_message_id'));
    }

    public function test_v3_outbound_store_failure_after_provider_acceptance_is_uncertain_without_retry(): void
    {
        Queue::fake([
            ProcessScenarioV3OutboundMessageJob::class,
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario('v3_outbox_uncertain_store', $this->v3CatalogRuntimeSchema($channel->id));

        $run = ScenarioRun::query()->create([
            'dialog_id' => $dialog->id,
            'scenario_code' => $scenario->code,
            'status' => ScenarioRun::STATUS_ACTIVE,
            'current_step' => 'start',
            'state_payload' => [
                'v3' => [
                    'published_version_id' => $scenario->publishedVersion?->id,
                    'current_block_id' => 'start',
                ],
            ],
            'started_at' => now(),
        ]);

        $inboundMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'Получить каталог',
        ]);

        $outbound = ScenarioV3OutboundMessage::query()->create([
            'scenario_run_id' => $run->id,
            'dialog_id' => $dialog->id,
            'channel_id' => $channel->id,
            'inbound_message_id' => $inboundMessage->id,
            'published_version_id' => $scenario->publishedVersion?->id,
            'scenario_code' => $scenario->code,
            'block_id' => 'catalog',
            'text' => 'Вот каталог',
            'text_format' => Message::TEXT_FORMAT_PLAIN_TEXT,
            'delivery_payload' => [
                'request_phone' => false,
                'remove_telegram_keyboard' => false,
                'reply_button_rows' => null,
                'button_placement' => 'auto',
                'v3_callback_block_id' => 'catalog',
            ],
            'status' => ScenarioV3OutboundMessage::STATUS_PENDING,
            'attempts' => 0,
            'available_at' => now(),
        ]);

        $sendAction = Mockery::mock(SendBotDialogTextAction::class);
        $sendAction
            ->shouldReceive('handleMessage')
            ->once()
            ->andReturnUsing(fn (...$args): BotDialogTextSendResult => $this->successfulBotSendResult($dialog, (string) $args[1], 'mock-v3-accepted-before-store-fail'));
        $this->app->instance(SendBotDialogTextAction::class, $sendAction);

        $storeAction = Mockery::mock(StoreOutboundScenarioMessageAction::class);
        $storeAction
            ->shouldReceive('handle')
            ->once()
            ->andThrow(new \RuntimeException('local store failed after provider accepted'));
        $this->app->instance(StoreOutboundScenarioMessageAction::class, $storeAction);

        (new ProcessScenarioV3OutboundMessageJob($outbound->id))
            ->handle(app(ScenarioRegistry::class));

        $outbound->refresh();
        $run->refresh();

        $this->assertSame(ScenarioV3OutboundMessage::STATUS_FAILED_UNCERTAIN, $outbound->status);
        $this->assertSame(1, $outbound->attempts);
        $this->assertNull($outbound->outbound_message_id);
        $this->assertSame('local store failed after provider accepted', $outbound->error_message);
        $this->assertSame($outbound->id, data_get($run->state_payload, 'v3.delivery_error.outbound_message_id'));
        $this->assertCount(0, Message::query()->where('direction', Message::DIRECTION_OUTBOUND)->get());
    }

    public function test_v3_wait_reply_exact_multiline_saves_dialog_field_and_counter(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9201]])
                ->push(['ok' => true, 'result' => ['message_id' => 9202]]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario('v3_wait_reply_exact', $this->v3WaitReplyRuntimeSchema($channel->id, [
            $this->v3WaitReplyEdge(
                id: '20',
                edgeKey: 'edge_code',
                targetBlockId: 'accepted',
                matchType: 'exact_text',
                variants: ['1', '2', '3'],
                inputCapture: [
                    'enabled' => true,
                    'field_key' => 'client_code',
                    'data_type' => 'any_text',
                ],
            ),
        ], [
            'accepted' => 'Код принят',
        ]));

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();

        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, '12');

        $run->refresh();
        $dialog->refresh();

        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('start', $run->current_step);
        $this->assertNull(data_get($dialog->fields_payload, 'client_code'));
        $this->assertCount(1, Http::recorded());

        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, '2');

        $run->refresh();
        $dialog->refresh();

        $counterKey = 'published_'.$scenario->publishedVersion->id.':edge_code';

        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('accepted', $run->current_step);
        $this->assertSame('2', data_get($dialog->fields_payload, 'client_code'));
        $this->assertSame(1, data_get($dialog->fields_payload, '_v3.transition_counts.'.$counterKey));

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === $dialog->external_chat_id
            && $request['text'] === 'Код принят');
    }

    public function test_v3_wait_reply_exact_parameter_matches_message_parameter(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9251]])
                ->push(['ok' => true, 'result' => ['message_id' => 9252]]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario('v3_wait_reply_parameter', $this->v3WaitReplyRuntimeSchema($channel->id, [
            $this->v3WaitReplyEdge(
                id: '20',
                edgeKey: 'edge_parameter',
                targetBlockId: 'accepted',
                matchType: 'exact_parameter',
                variants: ['payload_42'],
            ),
        ], [
            'accepted' => 'Параметр принят',
        ]));

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();

        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'не payload', [
            'message_parameter' => 'payload_42',
        ]);

        $run->refresh();

        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('accepted', $run->current_step);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === $dialog->external_chat_id
            && $request['text'] === 'Параметр принят');
    }

    public function test_v3_wait_reply_exact_callback_matches_callback_payload(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9261]])
                ->push(['ok' => true, 'result' => ['message_id' => 9262]]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario('v3_wait_reply_callback', $this->v3WaitReplyRuntimeSchema($channel->id, [
            $this->v3WaitReplyEdge(
                id: '20',
                edgeKey: 'edge_callback',
                targetBlockId: 'accepted',
                matchType: 'exact_callback',
                variants: ['callback_42'],
            ),
        ], [
            'accepted' => 'Callback принят',
        ]));

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();

        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'callback_42', [
            'raw_payload' => [
                'callback_query' => [
                    'data' => 'callback_42',
                ],
            ],
        ]);

        $run->refresh();

        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('accepted', $run->current_step);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === $dialog->external_chat_id
            && $request['text'] === 'Callback принят');
    }

    public function test_v3_telegram_inline_button_callback_can_be_consumed_as_wait_reply_answer(): void
    {
        Queue::fake();
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9271]])
                ->push(['ok' => true, 'result' => ['message_id' => 9272]]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $schema = $this->v3WaitReplyRuntimeSchema($channel->id, [
            $this->v3WaitReplyEdge(
                id: '20',
                edgeKey: 'edge_age_range',
                targetBlockId: 'accepted',
                inputCapture: [
                    'enabled' => true,
                    'field_scope' => 'contact',
                    'field_key' => 'age_range',
                    'data_type' => 'any_text',
                ],
            ),
        ], [
            'accepted' => 'Возраст принят',
        ]);
        data_set($schema, 'builder_v3_runtime.blocks.start.buttons', [
            'placement' => 'inline_message',
            'rows' => [[[
                'id' => 'btn_24_29',
                'text' => '24 - 29 лет',
                'type' => 'text',
                'normalized_text' => '24 - 29 лет',
                'output_id' => 'btn_24_29',
                'target_block_id' => null,
            ]]],
        ]);

        $scenario = $this->createPublishedScenario('v3_wait_reply_telegram_button_capture', $schema);

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();
        $runtime = app(ScenarioRegistry::class)->makeRuntime($scenario->code);

        $this->assertNotNull($runtime);
        $this->assertTrue($runtime->supportsTelegramCallbackContinuation($run, 'v3b:start:btn_24_29'));

        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'v3b:start:btn_24_29', [
            'raw_payload' => [
                'callback_query' => [
                    'data' => 'v3b:start:btn_24_29',
                ],
            ],
        ]);

        $run->refresh();
        $dialog->refresh();
        $contact->refresh();

        $counterKey = 'published_'.$scenario->publishedVersion->id.':edge_age_range';

        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('accepted', $run->current_step);
        $this->assertSame('24_29', $contact->age_range);
        $this->assertNull(data_get($dialog->fields_payload, 'age_range'));
        $this->assertSame(1, data_get($dialog->fields_payload, '_v3.transition_counts.'.$counterKey));

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === $dialog->external_chat_id
            && $request['text'] === 'Возраст принят');
    }

    public function test_v3_wait_reply_skips_edge_when_contact_phone_condition_does_not_match(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9271]])
                ->push(['ok' => true, 'result' => ['message_id' => 9272]]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario('v3_wait_reply_phone_condition', $this->v3WaitReplyRuntimeSchema($channel->id, [
            $this->v3WaitReplyEdge(
                id: '20',
                edgeKey: 'edge_has_phone',
                targetBlockId: 'has_phone',
                priority: 20,
                contactPhoneCondition: AutoReplyRule::CONTACT_PHONE_CONDITION_HAS_PHONE,
            ),
            $this->v3WaitReplyEdge(
                id: '10',
                edgeKey: 'edge_fallback',
                targetBlockId: 'fallback',
                priority: 10,
            ),
        ], [
            'has_phone' => 'Телефон есть',
            'fallback' => 'Телефона нет',
        ]));

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();

        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'любой ответ');

        $run->refresh();

        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('fallback', $run->current_step);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === $dialog->external_chat_id
            && $request['text'] === 'Телефона нет');
    }

    public function test_v3_wait_reply_skips_edge_when_dialog_phone_condition_does_not_match(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9273]])
                ->push(['ok' => true, 'result' => ['message_id' => 9274]]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario('v3_wait_reply_dialog_phone_condition', $this->v3WaitReplyRuntimeSchema($channel->id, [
            $this->v3WaitReplyEdge(
                id: '20',
                edgeKey: 'edge_has_dialog_phone',
                targetBlockId: 'has_phone',
                priority: 20,
                dialogPhoneCondition: AutoReplyRule::CONTACT_PHONE_CONDITION_HAS_PHONE,
            ),
            $this->v3WaitReplyEdge(
                id: '10',
                edgeKey: 'edge_fallback',
                targetBlockId: 'fallback',
                priority: 10,
            ),
        ], [
            'has_phone' => 'Телефон мессенджера есть',
            'fallback' => 'Телефона мессенджера нет',
        ]));

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();

        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'любой ответ');

        $run->refresh();

        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('fallback', $run->current_step);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === $dialog->external_chat_id
            && $request['text'] === 'Телефона мессенджера нет');
    }

    public function test_v3_wait_reply_skips_edge_when_dialog_field_condition_does_not_match(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9281]])
                ->push(['ok' => true, 'result' => ['message_id' => 9282]]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $dialog->forceFill([
            'fields_payload' => [
                'lead_status' => 'cold',
            ],
        ])->save();
        $scenario = $this->createPublishedScenario('v3_wait_reply_field_condition', $this->v3WaitReplyRuntimeSchema($channel->id, [
            $this->v3WaitReplyEdge(
                id: '20',
                edgeKey: 'edge_hot',
                targetBlockId: 'hot',
                priority: 20,
                fieldCondition: [
                    'enabled' => true,
                    'field_scope' => 'dialog',
                    'field_key' => 'lead_status',
                    'operator' => 'equals',
                    'value' => 'hot',
                ],
            ),
            $this->v3WaitReplyEdge(
                id: '10',
                edgeKey: 'edge_fallback',
                targetBlockId: 'fallback',
                priority: 10,
            ),
        ], [
            'hot' => 'Горячий лид',
            'fallback' => 'Обычный лид',
        ]));

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();

        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'любой ответ');

        $run->refresh();

        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('fallback', $run->current_step);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === $dialog->external_chat_id
            && $request['text'] === 'Обычный лид');
    }

    public function test_v3_wait_reply_uses_first_name_source_contact_field_condition(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9283]])
                ->push(['ok' => true, 'result' => ['message_id' => 9284]]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $contact->forceFill([
            'first_name' => 'Герман',
            'first_name_source' => Contact::FIRST_NAME_SOURCE_MANUAL,
        ])->save();
        $scenario = $this->createPublishedScenario('v3_wait_reply_first_name_source_condition', $this->v3WaitReplyRuntimeSchema($channel->id, [
            $this->v3WaitReplyEdge(
                id: '20',
                edgeKey: 'edge_manual_name',
                targetBlockId: 'manual_name',
                priority: 20,
                fieldCondition: [
                    'enabled' => true,
                    'field_scope' => 'contact',
                    'field_key' => 'first_name_source',
                    'operator' => 'equals',
                    'value' => Contact::FIRST_NAME_SOURCE_MANUAL,
                ],
            ),
            $this->v3WaitReplyEdge(
                id: '10',
                edgeKey: 'edge_fallback',
                targetBlockId: 'fallback',
                priority: 10,
            ),
        ], [
            'manual_name' => 'Имя указал оператор',
            'fallback' => 'Обычный лид',
        ]));

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();

        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'любой ответ');

        $run->refresh();

        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('manual_name', $run->current_step);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === $dialog->external_chat_id
            && $request['text'] === 'Имя указал оператор');
    }

    public function test_v3_wait_reply_uses_expression_condition_with_fallback(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9285]])
                ->push(['ok' => true, 'result' => ['message_id' => 9286]]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $contact->forceFill(['gender' => 'unknown'])->save();
        $dialog->forceFill(['fields_payload' => ['region' => 'Москва']])->save();
        $scenario = $this->createPublishedScenario('v3_wait_reply_expression_condition', $this->v3WaitReplyRuntimeSchema($channel->id, [
            $this->v3WaitReplyEdge(
                id: '20',
                edgeKey: 'edge_known_gender',
                targetBlockId: 'known_gender',
                priority: 20,
                expression: '{{contact.gender}} == "male" or {{contact.gender}} == "female" and {{dialog.region}} == "Москва"',
            ),
            $this->v3WaitReplyEdge(
                id: '10',
                edgeKey: 'edge_fallback',
                targetBlockId: 'fallback',
                priority: 10,
            ),
        ], [
            'known_gender' => 'Пол известен',
            'fallback' => 'Пол неизвестен',
        ]));

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();

        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'любой ответ');

        $run->refresh();

        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('fallback', $run->current_step);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === $dialog->external_chat_id
            && $request['text'] === 'Пол неизвестен');
    }

    public function test_v3_wait_reply_expression_condition_matches_contact_and_dialog_values(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9287]])
                ->push(['ok' => true, 'result' => ['message_id' => 9288]]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $contact->forceFill([
            'gender' => 'male',
            'age_years' => 18,
        ])->save();
        $dialog->forceFill(['fields_payload' => ['region' => 'Москва']])->save();
        $scenario = $this->createPublishedScenario('v3_wait_reply_expression_match', $this->v3WaitReplyRuntimeSchema($channel->id, [
            $this->v3WaitReplyEdge(
                id: '20',
                edgeKey: 'edge_match',
                targetBlockId: 'match',
                priority: 20,
                expression: '{{contact.gender}} == "male" and {{contact.age_years}} == "18" and {{dialog.region}} == "Москва"',
            ),
            $this->v3WaitReplyEdge(
                id: '10',
                edgeKey: 'edge_fallback',
                targetBlockId: 'fallback',
                priority: 10,
            ),
        ], [
            'match' => 'Условие прошло',
            'fallback' => 'Fallback',
        ]));

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();

        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'любой ответ');

        $run->refresh();

        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('match', $run->current_step);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === $dialog->external_chat_id
            && $request['text'] === 'Условие прошло');
    }

    public function test_v3_wait_reply_skips_exhausted_transition_limit_and_uses_next_edge(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9301]])
                ->push(['ok' => true, 'result' => ['message_id' => 9302]]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario('v3_wait_reply_limit', $this->v3WaitReplyRuntimeSchema($channel->id, [
            $this->v3WaitReplyEdge('20', 'edge_high', 'high', priority: 20, transitionLimit: 1),
            $this->v3WaitReplyEdge('10', 'edge_low', 'low', priority: 10),
        ], [
            'high' => 'Высокий приоритет',
            'low' => 'Низкий приоритет',
        ]));
        $exhaustedKey = 'published_'.$scenario->publishedVersion->id.':edge_high';

        $dialog->forceFill([
            'fields_payload' => [
                '_v3' => [
                    'transition_counts' => [
                        $exhaustedKey => 1,
                    ],
                ],
            ],
        ])->save();

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();

        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'любой ответ');

        $run->refresh();
        $dialog->refresh();

        $lowKey = 'published_'.$scenario->publishedVersion->id.':edge_low';

        $this->assertSame('low', $run->current_step);
        $this->assertSame(1, data_get($dialog->fields_payload, '_v3.transition_counts.'.$exhaustedKey));
        $this->assertSame(1, data_get($dialog->fields_payload, '_v3.transition_counts.'.$lowKey));

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === $dialog->external_chat_id
            && $request['text'] === 'Низкий приоритет');
    }

    public function test_v3_wait_reply_invalid_capture_keeps_current_block_and_stays_silent(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9401]]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario('v3_wait_reply_invalid_capture', $this->v3WaitReplyRuntimeSchema($channel->id, [
            $this->v3WaitReplyEdge(
                id: '20',
                edgeKey: 'edge_email',
                targetBlockId: 'accepted',
                inputCapture: [
                    'enabled' => true,
                    'field_key' => 'client_email',
                    'data_type' => 'email',
                ],
            ),
        ], [
            'accepted' => 'Email принят',
        ]));

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();

        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'не email');

        $run->refresh();
        $dialog->refresh();

        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('start', $run->current_step);
        $this->assertSame('waiting_input', data_get($run->state_payload, 'v3.status'));
        $this->assertNull(data_get($dialog->fields_payload, 'client_email'));
        $this->assertSame([], data_get($dialog->fields_payload, '_v3.transition_counts', []));
        $this->assertCount(1, Http::recorded());
    }

    public function test_v3_wait_reply_dialog_fields_payload_limit_keeps_current_block_and_stays_silent(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9404]]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario('v3_wait_reply_fields_payload_limit', $this->v3WaitReplyRuntimeSchema($channel->id, [
            $this->v3WaitReplyEdge(
                id: '20',
                edgeKey: 'edge_large_payload',
                targetBlockId: 'accepted',
                inputCapture: [
                    'enabled' => true,
                    'field_key' => 'client_note',
                    'data_type' => 'any_text',
                ],
            ),
        ], [
            'accepted' => 'Данные приняты',
        ]));

        $dialog->forceFill([
            'fields_payload' => [
                'existing_note' => str_repeat('x', 65500),
            ],
        ])->save();

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();

        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'короткий ответ');

        $run->refresh();
        $dialog->refresh();
        $counterKey = 'published_'.$scenario->publishedVersion->id.':edge_large_payload';

        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('start', $run->current_step);
        $this->assertSame('waiting_input', data_get($run->state_payload, 'v3.status'));
        $this->assertNull(data_get($dialog->fields_payload, 'client_note'));
        $this->assertNull(data_get($dialog->fields_payload, '_v3.transition_counts.'.$counterKey));
        $this->assertCount(1, Http::recorded());
    }

    public function test_v3_wait_reply_skips_invalid_capture_and_uses_next_edge(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9402]])
                ->push(['ok' => true, 'result' => ['message_id' => 9403]]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario('v3_wait_reply_invalid_capture_fallback', $this->v3WaitReplyRuntimeSchema($channel->id, [
            $this->v3WaitReplyEdge(
                id: '30',
                edgeKey: 'edge_phone',
                targetBlockId: 'phone',
                priority: 20,
                inputCapture: [
                    'enabled' => true,
                    'field_key' => 'client_phone',
                    'data_type' => 'phone',
                ],
            ),
            $this->v3WaitReplyEdge(
                id: '20',
                edgeKey: 'edge_fallback',
                targetBlockId: 'fallback',
                priority: 10,
            ),
        ], [
            'phone' => 'Телефон принят',
            'fallback' => 'Это не номер телефона',
        ]));

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();

        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'не телефон');

        $run->refresh();
        $dialog->refresh();
        $phoneKey = 'published_'.$scenario->publishedVersion->id.':edge_phone';
        $fallbackKey = 'published_'.$scenario->publishedVersion->id.':edge_fallback';

        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('fallback', $run->current_step);
        $this->assertNull(data_get($dialog->fields_payload, 'client_phone'));
        $this->assertNull(data_get($dialog->fields_payload, '_v3.transition_counts.'.$phoneKey));
        $this->assertSame(1, data_get($dialog->fields_payload, '_v3.transition_counts.'.$fallbackKey));

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === $dialog->external_chat_id
            && $request['text'] === 'Это не номер телефона');
    }

    public function test_v3_transition_actions_write_contact_and_dialog_fields(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9404]])
                ->push(['ok' => true, 'result' => ['message_id' => 9405]]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario('v3_transition_actions_write_fields', $this->v3WaitReplyRuntimeSchema($channel->id, [
            $this->v3WaitReplyEdge(
                id: '20',
                edgeKey: 'edge_gender_male',
                targetBlockId: 'accepted',
                matchType: 'exact_text',
                variants: ['Мужской'],
                transitionActions: [
                    [
                        'type' => 'write_field',
                        'target_scope' => 'contact',
                        'target_field' => 'gender',
                        'value_source' => 'static',
                        'value' => 'male',
                    ],
                    [
                        'type' => 'write_field',
                        'target_scope' => 'contact',
                        'target_field' => 'gender_source',
                        'value_source' => 'static',
                        'value' => 'client',
                    ],
                    [
                        'type' => 'write_field',
                        'target_scope' => 'dialog',
                        'target_field' => 'questionnaire_step',
                        'value_source' => 'static',
                        'value' => 'gender_done',
                    ],
                ],
            ),
        ], [
            'accepted' => 'Пол записан',
        ]));

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();

        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Мужской');

        $run->refresh();
        $contact->refresh();
        $dialog->refresh();
        $counterKey = 'published_'.$scenario->publishedVersion->id.':edge_gender_male';

        $this->assertSame('accepted', $run->current_step);
        $this->assertSame('male', $contact->gender);
        $this->assertSame(Contact::GENDER_SOURCE_CLIENT, $contact->gender_source);
        $this->assertSame('gender_done', data_get($dialog->fields_payload, 'questionnaire_step'));
        $this->assertSame(1, data_get($dialog->fields_payload, '_v3.transition_counts.'.$counterKey));
    }

    public function test_v3_transition_action_failure_rolls_back_and_uses_next_edge(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9406]])
                ->push(['ok' => true, 'result' => ['message_id' => 9407]]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario('v3_transition_actions_fallback', $this->v3WaitReplyRuntimeSchema($channel->id, [
            $this->v3WaitReplyEdge(
                id: '30',
                edgeKey: 'edge_invalid_action',
                targetBlockId: 'invalid',
                priority: 20,
                transitionActions: [
                    [
                        'type' => 'write_field',
                        'target_scope' => 'contact',
                        'target_field' => 'gender',
                        'value_source' => 'static',
                        'value' => 'male',
                    ],
                    [
                        'type' => 'write_field',
                        'target_scope' => 'contact',
                        'target_field' => 'gender_source',
                        'value_source' => 'static',
                        'value' => 'bad_source',
                    ],
                ],
            ),
            $this->v3WaitReplyEdge(
                id: '20',
                edgeKey: 'edge_fallback_after_action',
                targetBlockId: 'fallback',
                priority: 10,
            ),
        ], [
            'invalid' => 'Не должно отправиться',
            'fallback' => 'Fallback',
        ]));

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();

        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Мужской');

        $run->refresh();
        $contact->refresh();
        $dialog->refresh();
        $invalidKey = 'published_'.$scenario->publishedVersion->id.':edge_invalid_action';
        $fallbackKey = 'published_'.$scenario->publishedVersion->id.':edge_fallback_after_action';

        $this->assertSame('fallback', $run->current_step);
        $this->assertNull($contact->gender);
        $this->assertNull($contact->gender_source);
        $this->assertNull(data_get($dialog->fields_payload, '_v3.transition_counts.'.$invalidKey));
        $this->assertSame(1, data_get($dialog->fields_payload, '_v3.transition_counts.'.$fallbackKey));
    }

    public function test_v3_wait_reply_number_capture_enforces_format_and_saves_normalized_decimal(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9411]])
                ->push(['ok' => true, 'result' => ['message_id' => 9412]]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario('v3_wait_reply_number_capture', $this->v3WaitReplyRuntimeSchema($channel->id, [
            $this->v3WaitReplyEdge(
                id: '20',
                edgeKey: 'edge_budget',
                targetBlockId: 'accepted',
                inputCapture: [
                    'enabled' => true,
                    'field_key' => 'budget',
                    'data_type' => 'number',
                ],
            ),
        ], [
            'accepted' => 'Число принято',
        ]));

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();

        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, '1234567890123456789');

        $run->refresh();
        $dialog->refresh();

        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('start', $run->current_step);
        $this->assertNull(data_get($dialog->fields_payload, 'budget'));
        $this->assertSame([], data_get($dialog->fields_payload, '_v3.transition_counts', []));

        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, '12,3400');

        $run->refresh();
        $dialog->refresh();

        $counterKey = 'published_'.$scenario->publishedVersion->id.':edge_budget';

        $this->assertSame('accepted', $run->current_step);
        $this->assertSame('12.3400', data_get($dialog->fields_payload, 'budget'));
        $this->assertSame(1, data_get($dialog->fields_payload, '_v3.transition_counts.'.$counterKey));

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === $dialog->external_chat_id
            && $request['text'] === 'Число принято');
    }

    public function test_v3_wait_reply_contact_share_capture_saves_phone_and_advances(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9421]])
                ->push(['ok' => true, 'result' => ['message_id' => 9422]]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario('v3_wait_reply_phone_share_capture', $this->v3WaitReplyRuntimeSchema($channel->id, [
            $this->v3WaitReplyEdge(
                id: '20',
                edgeKey: 'edge_phone_share',
                targetBlockId: 'accepted',
                inputCapture: [
                    'enabled' => true,
                    'field_key' => 'client_phone',
                    'data_type' => 'any_text',
                ],
            ),
        ], [
            'accepted' => 'Телефон принят',
        ]));

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();

        $this->assertTrue(app(ScenarioRegistry::class)->makeRuntime($scenario->code)->supportsContactShareContinuation($run));

        $contact->phoneNumbers()->create([
            'phone_raw' => '79263527111',
            'phone_normalized' => '+79263527111',
            'source' => ContactPhoneNumber::SOURCE_TELEGRAM_CONTACT_SHARE,
            'is_primary' => true,
        ]);

        $phoneShare = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_CONTACT_SHARE,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => null,
        ]);

        (new ProcessScenarioInboundJob($phoneShare->id, $run->id))
            ->handle(app(ScenarioRegistry::class));

        $run->refresh();
        $dialog->refresh();

        $counterKey = 'published_'.$scenario->publishedVersion->id.':edge_phone_share';

        $this->assertSame('accepted', $run->current_step);
        $this->assertSame('+79263527111', data_get($dialog->fields_payload, 'client_phone'));
        $this->assertSame(1, data_get($dialog->fields_payload, '_v3.transition_counts.'.$counterKey));

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === $dialog->external_chat_id
            && $request['text'] === 'Телефон принят');
    }

    public function test_v3_wait_reply_contact_phone_capture_from_text_saves_contact_without_confirming_dialog_phone(): void
    {
        Queue::fake();
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9431]])
                ->push(['ok' => true, 'result' => ['message_id' => 9432]]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario('v3_wait_reply_contact_phone', $this->v3WaitReplyRuntimeSchema($channel->id, [
            $this->v3WaitReplyEdge(
                id: '20',
                edgeKey: 'edge_contact_phone',
                targetBlockId: 'accepted',
                inputCapture: [
                    'enabled' => true,
                    'field_scope' => 'contact',
                    'field_key' => 'phone',
                    'data_type' => 'phone',
                ],
            ),
        ], [
            'accepted' => 'Телефон сохранён',
        ]));

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();

        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, '+7 926 352-71-11');

        $run->refresh();
        $dialog->refresh();
        $contact->refresh();
        $counterKey = 'published_'.$scenario->publishedVersion->id.':edge_contact_phone';

        $this->assertSame('accepted', $run->current_step);
        $this->assertNull($dialog->confirmed_phone_raw);
        $this->assertNull($dialog->confirmed_phone_normalized);
        $this->assertNull(data_get($dialog->fields_payload, 'phone'));
        $this->assertSame(1, data_get($dialog->fields_payload, '_v3.transition_counts.'.$counterKey));
        $this->assertDatabaseHas('contact_phone_numbers', [
            'contact_id' => $contact->id,
            'phone_normalized' => '+79263527111',
            'source' => ContactPhoneNumber::SOURCE_V3_CAPTURE,
        ]);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === $dialog->external_chat_id
            && $request['text'] === 'Телефон сохранён');
    }

    public function test_v3_contact_phone_capture_logs_safe_error_without_phone(): void
    {
        Queue::fake();
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9433]]),
        ]);
        Log::spy();

        $addContactPhoneAction = Mockery::mock(AddContactPhoneAction::class);
        $addContactPhoneAction
            ->shouldReceive('handle')
            ->once()
            ->andThrow(new \RuntimeException('SQL failed for phone +79263527111'));
        $this->app->instance(AddContactPhoneAction::class, $addContactPhoneAction);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario('v3_wait_reply_contact_phone_safe_log', $this->v3WaitReplyRuntimeSchema($channel->id, [
            $this->v3WaitReplyEdge(
                id: '20',
                edgeKey: 'edge_contact_phone',
                targetBlockId: 'accepted',
                inputCapture: [
                    'enabled' => true,
                    'field_scope' => 'contact',
                    'field_key' => 'phone',
                    'data_type' => 'phone',
                ],
            ),
        ], [
            'accepted' => 'Телефон сохранён',
        ]));

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();

        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, '+7 926 352-71-11');

        $run->refresh();
        $dialog->refresh();

        $this->assertSame('start', $run->current_step);
        $this->assertNull($dialog->confirmed_phone_normalized);

        Log::shouldHaveReceived('warning')
            ->with('scenario.v3_contact_phone_capture_failed', Mockery::on(function (array $context): bool {
                $encoded = json_encode($context, JSON_UNESCAPED_UNICODE) ?: '';

                return ($context['exception'] ?? null) === \RuntimeException::class
                    && ($context['error_message'] ?? null) === 'Не удалось сохранить телефон из V3-сценария.'
                    && ! array_key_exists('error', $context)
                    && ! str_contains($encoded, '+79263527111')
                    && ! str_contains($encoded, '79263527111');
            }))
            ->once();
    }

    public function test_v3_wait_reply_contact_first_name_capture_respects_manual_source_and_advances(): void
    {
        Queue::fake();
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9441]])
                ->push(['ok' => true, 'result' => ['message_id' => 9442]]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel, [
            'first_name' => 'Оператор',
            'first_name_source' => Contact::FIRST_NAME_SOURCE_MANUAL,
        ]);
        $scenario = $this->createPublishedScenario('v3_wait_reply_contact_name', $this->v3WaitReplyRuntimeSchema($channel->id, [
            $this->v3WaitReplyEdge(
                id: '20',
                edgeKey: 'edge_contact_name',
                targetBlockId: 'accepted',
                inputCapture: [
                    'enabled' => true,
                    'field_scope' => 'contact',
                    'field_key' => 'first_name',
                    'data_type' => 'any_text',
                ],
            ),
        ], [
            'accepted' => 'Имя принято',
        ]));

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();

        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Герман');

        $run->refresh();
        $dialog->refresh();
        $contact->refresh();
        $counterKey = 'published_'.$scenario->publishedVersion->id.':edge_contact_name';

        $this->assertSame('accepted', $run->current_step);
        $this->assertSame('Оператор', $contact->first_name);
        $this->assertSame(Contact::FIRST_NAME_SOURCE_MANUAL, $contact->first_name_source);
        $this->assertSame(1, data_get($dialog->fields_payload, '_v3.transition_counts.'.$counterKey));

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === $dialog->external_chat_id
            && $request['text'] === 'Имя принято');
    }

    public function test_v3_wait_reply_contact_supported_fields_capture_and_advance(): void
    {
        Queue::fake();
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 9460]]),
        ]);

        $cases = [
            'last_name' => ['value' => '  Абрикосов  ', 'data_type' => 'any_text', 'expected' => 'Абрикосов'],
            'country' => ['value' => '  Россия  ', 'data_type' => 'any_text', 'expected' => 'Россия'],
            'city' => ['value' => '  Москва  ', 'data_type' => 'any_text', 'expected' => 'Москва'],
            'gender' => ['value' => 'Женский', 'data_type' => 'any_text', 'expected' => 'female'],
            'age_range' => ['value' => '24 - 29 лет', 'data_type' => 'any_text', 'expected' => '24_29'],
            'age_years' => ['value' => '37', 'data_type' => 'number', 'expected' => 37],
        ];

        foreach ($cases as $fieldKey => $case) {
            $channel = $this->createTelegramChannel();
            [$contact, $identity, $dialog] = $this->createDialogContext($channel);
            $edgeKey = 'edge_contact_'.$fieldKey;
            $scenario = $this->createPublishedScenario('v3_wait_reply_contact_'.$fieldKey, $this->v3WaitReplyRuntimeSchema($channel->id, [
                $this->v3WaitReplyEdge(
                    id: '20',
                    edgeKey: $edgeKey,
                    targetBlockId: 'accepted',
                    inputCapture: [
                        'enabled' => true,
                        'field_scope' => 'contact',
                        'field_key' => $fieldKey,
                        'data_type' => $case['data_type'],
                    ],
                ),
            ], [
                'accepted' => 'Поле сохранено',
            ]));

            ScenarioChannelBinding::query()->create([
                'channel_id' => $channel->id,
                'scenario_code' => $scenario->code,
                'is_active' => true,
            ]);

            $startMessage = Message::factory()->create([
                'contact_id' => $contact->id,
                'contact_identity_id' => $identity->id,
                'channel_id' => $channel->id,
                'dialog_id' => $dialog->id,
                'direction' => Message::DIRECTION_INBOUND,
                'message_kind' => Message::KIND_INBOUND_USER,
                'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
                'external_chat_id' => $dialog->external_chat_id,
                'text' => 'старт',
            ]);

            (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
                ->handle(app(ScenarioRegistry::class));

            $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();

            $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, (string) $case['value']);

            $run->refresh();
            $dialog->refresh();
            $contact->refresh();
            $counterKey = 'published_'.$scenario->publishedVersion->id.':'.$edgeKey;

            $this->assertSame('accepted', $run->current_step, $fieldKey);
            $this->assertSame($case['expected'], $contact->getAttribute($fieldKey), $fieldKey);
            $this->assertNull(data_get($dialog->fields_payload, $fieldKey), $fieldKey);
            $this->assertSame(1, data_get($dialog->fields_payload, '_v3.transition_counts.'.$counterKey), $fieldKey);
        }
    }

    public function test_v3_wait_reply_invalid_contact_capture_keeps_current_block(): void
    {
        Queue::fake();
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9451]]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario('v3_wait_reply_contact_age', $this->v3WaitReplyRuntimeSchema($channel->id, [
            $this->v3WaitReplyEdge(
                id: '20',
                edgeKey: 'edge_contact_age',
                targetBlockId: 'accepted',
                inputCapture: [
                    'enabled' => true,
                    'field_scope' => 'contact',
                    'field_key' => 'age_years',
                    'data_type' => 'number',
                ],
            ),
        ], [
            'accepted' => 'Возраст принят',
        ]));

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();

        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, '150');

        $run->refresh();
        $dialog->refresh();
        $contact->refresh();

        $this->assertSame('start', $run->current_step);
        $this->assertNull($contact->age_years);
        $this->assertSame([], data_get($dialog->fields_payload, '_v3.transition_counts', []));
        $this->assertCount(1, Http::recorded());
    }

    public function test_v3_contact_share_wait_reply_continuation_is_dispatched(): void
    {
        Queue::fake();

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario('v3_dispatch_phone_share_wait_reply', $this->v3WaitReplyRuntimeSchema($channel->id, [
            $this->v3WaitReplyEdge(
                id: '20',
                edgeKey: 'edge_phone_share',
                targetBlockId: 'accepted',
                inputCapture: [
                    'enabled' => true,
                    'field_key' => 'client_phone',
                    'data_type' => 'any_text',
                ],
            ),
        ], [
            'accepted' => 'Телефон принят',
        ]));

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $run = ScenarioRun::query()->create([
            'dialog_id' => $dialog->id,
            'scenario_code' => $scenario->code,
            'status' => ScenarioRun::STATUS_ACTIVE,
            'current_step' => 'start',
            'state_payload' => [
                'v3' => [
                    'schema_version' => 3,
                    'published_version_id' => $scenario->publishedVersion?->id,
                    'status' => 'waiting_input',
                    'current_block_id' => 'start',
                    'waiting_output_ids' => [],
                ],
            ],
            'started_at' => now()->subMinute(),
        ]);

        $phoneShare = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_CONTACT_SHARE,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => null,
        ]);

        $handled = app(DispatchStoredInboundScenarioAction::class)->continueActiveRun($phoneShare);

        $this->assertTrue($handled);
        Queue::assertPushed(ProcessScenarioInboundJob::class, fn (ProcessScenarioInboundJob $job): bool => $job->scenarioRunId === $run->id
            && $job->inboundMessageId === $phoneShare->id);
    }

    public function test_v3_automatic_edge_zero_seconds_runs_after_block_message(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9431]])
                ->push(['ok' => true, 'result' => ['message_id' => 9432]]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $runtimeSchema = $this->v3AutomaticRuntimeSchema($channel->id);
        $staleCapture = [
            'enabled' => true,
            'field_scope' => 'dialog',
            'field_key' => 'stale_capture',
            'data_type' => 'any_text',
        ];
        data_set($runtimeSchema, 'builder_v3_runtime.blocks.start.automatic_edges.0.input_capture', $staleCapture);
        data_set($runtimeSchema, 'builder_v3_runtime.edges.0.input_capture', $staleCapture);
        $scenario = $this->createPublishedScenario('v3_automatic_zero', $runtimeSchema);

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();
        $dialog->refresh();
        $counterKey = 'published_'.$scenario->publishedVersion->id.':edge_auto_next';

        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('next', $run->current_step);
        $this->assertSame('next', data_get($run->state_payload, 'v3.current_block_id'));
        $this->assertSame(1, data_get($dialog->fields_payload, '_v3.transition_counts.'.$counterKey));
        $this->assertNull(data_get($dialog->fields_payload, 'stale_capture'));

        $sentTexts = Http::recorded()
            ->map(fn (array $record): string => (string) $record[0]['text'])
            ->values()
            ->all();

        $this->assertSame(['Стартовый блок', 'Автоматический переход'], $sentTexts);
    }

    public function test_v3_automatic_edge_transition_limit_skips_exhausted_edge(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9441]])
                ->push(['ok' => true, 'result' => ['message_id' => 9442]])
                ->push(['ok' => true, 'result' => ['message_id' => 9443]]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario(
            'v3_automatic_zero_limit',
            $this->v3AutomaticRuntimeSchema($channel->id, transitionLimit: 1),
        );

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $firstStartMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($firstStartMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $secondStartMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($secondStartMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->active()->firstOrFail();
        $dialog->refresh();
        $counterKey = 'published_'.$scenario->publishedVersion->id.':edge_auto_next';

        $this->assertSame('start', $run->current_step);
        $this->assertSame('start', data_get($run->state_payload, 'v3.current_block_id'));
        $this->assertSame(1, data_get($dialog->fields_payload, '_v3.transition_counts.'.$counterKey));

        $sentTexts = Http::recorded()
            ->map(fn (array $record): string => (string) $record[0]['text'])
            ->values()
            ->all();

        $this->assertSame(['Стартовый блок', 'Автоматический переход', 'Стартовый блок'], $sentTexts);
    }

    public function test_v3_delayed_automatic_edge_is_scheduled_and_processed(): void
    {
        Queue::fake([
            ProcessScenarioV3ScheduledTransitionJob::class,
        ]);
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9451]])
                ->push(['ok' => true, 'result' => ['message_id' => 9452]]),
        ]);

        $this->travelTo(now()->startOfSecond());
        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario(
            'v3_automatic_delayed',
            $this->v3AutomaticRuntimeSchema($channel->id, delay: [
                'type' => 'relative',
                'value' => 5,
                'unit' => 'min',
                'cancel_if_left_source_block' => true,
            ]),
        );

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();
        $transition = ScenarioV3ScheduledTransition::query()->firstOrFail();

        Queue::assertPushed(ProcessScenarioV3ScheduledTransitionJob::class, fn (ProcessScenarioV3ScheduledTransitionJob $job): bool => $job->scheduledTransitionId === $transition->id
            && $job->scenarioRunId === $run->id);

        $this->assertSame(ScenarioV3ScheduledTransition::STATUS_SCHEDULED, $transition->status);
        $this->assertSame('start', $run->current_step);
        $this->assertSame('start', data_get($run->state_payload, 'v3.current_block_id'));
        $this->assertTrue($transition->scheduled_for->equalTo(now()->addMinutes(5)));

        $sentTexts = Http::recorded()
            ->map(fn (array $record): string => (string) $record[0]['text'])
            ->values()
            ->all();

        $this->assertSame(['Стартовый блок'], $sentTexts);

        $this->travelTo(now()->addMinutes(5));
        $channel->forceFill(['connection_checked_at' => now()])->save();
        (new ProcessScenarioV3ScheduledTransitionJob($transition->id, $run->id))
            ->handle(app(ScenarioRegistry::class));

        $run->refresh();
        $transition->refresh();
        $dialog->refresh();
        $counterKey = 'published_'.$scenario->publishedVersion->id.':edge_auto_next';

        $this->assertSame(ScenarioV3ScheduledTransition::STATUS_PASSED, $transition->status);
        $this->assertSame('next', $run->current_step);
        $this->assertSame('next', data_get($run->state_payload, 'v3.current_block_id'));
        $this->assertSame(1, data_get($dialog->fields_payload, '_v3.transition_counts.'.$counterKey));

        $sentTexts = Http::recorded()
            ->map(fn (array $record): string => (string) $record[0]['text'])
            ->values()
            ->all();

        $this->assertSame(['Стартовый блок', 'Автоматический переход'], $sentTexts);

        (new ProcessScenarioV3ScheduledTransitionJob($transition->id, $run->id))
            ->handle(app(ScenarioRegistry::class));

        $run->refresh();
        $transition->refresh();
        $dialog->refresh();

        $this->assertSame(ScenarioV3ScheduledTransition::STATUS_PASSED, $transition->status);
        $this->assertSame('next', $run->current_step);
        $this->assertSame(1, data_get($dialog->fields_payload, '_v3.transition_counts.'.$counterKey));

        $sentTexts = Http::recorded()
            ->map(fn (array $record): string => (string) $record[0]['text'])
            ->values()
            ->all();

        $this->assertSame(['Стартовый блок', 'Автоматический переход'], $sentTexts);
    }

    public function test_v3_delayed_transition_sends_target_message_after_transition_is_persisted(): void
    {
        Queue::fake([
            ProcessScenarioV3ScheduledTransitionJob::class,
        ]);

        $sendCount = 0;
        $runId = null;
        $transitionId = null;
        $sendAction = Mockery::mock(SendBotDialogTextAction::class);
        $sendAction
            ->shouldReceive('handleMessage')
            ->twice()
            ->andReturnUsing(function (...$args) use (&$sendCount, &$runId, &$transitionId): BotDialogTextSendResult {
                $sendCount++;
                $message = $args[0];
                $text = (string) $args[1];
                $dialog = $message instanceof Message && $message->dialog instanceof Dialog
                    ? $message->dialog
                    : Dialog::query()->findOrFail($message->dialog_id);

                if ($text === 'Автоматический переход') {
                    $this->assertNotNull($runId);
                    $this->assertNotNull($transitionId);
                    $this->assertSame('next', ScenarioRun::query()->findOrFail($runId)->current_step);
                    $this->assertSame(
                        ScenarioV3ScheduledTransition::STATUS_DELIVERY_PENDING,
                        ScenarioV3ScheduledTransition::query()->findOrFail($transitionId)->status,
                    );
                }

                return $this->successfulBotSendResult($dialog, $text, 'mock-v3-delayed-'.$sendCount);
            });
        $this->app->instance(SendBotDialogTextAction::class, $sendAction);

        $this->travelTo(now()->startOfSecond());
        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario(
            'v3_delayed_deferred_send',
            $this->v3AutomaticRuntimeSchema($channel->id, delay: [
                'type' => 'relative',
                'value' => 5,
                'unit' => 'min',
                'cancel_if_left_source_block' => true,
            ]),
        );

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();
        $transition = ScenarioV3ScheduledTransition::query()->firstOrFail();
        $runId = $run->id;
        $transitionId = $transition->id;

        $this->travelTo(now()->addMinutes(5));

        (new ProcessScenarioV3ScheduledTransitionJob($transition->id, $run->id))
            ->handle(app(ScenarioRegistry::class));

        $this->assertSame(
            ScenarioV3ScheduledTransition::STATUS_PASSED,
            ScenarioV3ScheduledTransition::query()->findOrFail($transitionId)->status,
        );
        $this->assertSame(2, $sendCount);
    }

    public function test_v3_delayed_transition_stale_processing_can_be_reclaimed(): void
    {
        Queue::fake([
            ProcessScenarioV3ScheduledTransitionJob::class,
        ]);

        $sendCount = 0;
        $sendAction = Mockery::mock(SendBotDialogTextAction::class);
        $sendAction
            ->shouldReceive('handleMessage')
            ->twice()
            ->andReturnUsing(function (...$args) use (&$sendCount): BotDialogTextSendResult {
                $sendCount++;
                $message = $args[0];
                $text = (string) $args[1];
                $dialog = $message instanceof Message && $message->dialog instanceof Dialog
                    ? $message->dialog
                    : Dialog::query()->findOrFail($message->dialog_id);

                return $this->successfulBotSendResult($dialog, $text, 'mock-v3-delayed-stale-'.$sendCount);
            });
        $this->app->instance(SendBotDialogTextAction::class, $sendAction);

        $this->travelTo(now()->startOfSecond());
        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario(
            'v3_delayed_stale_processing',
            $this->v3AutomaticRuntimeSchema($channel->id, delay: [
                'type' => 'relative',
                'value' => 5,
                'unit' => 'min',
                'cancel_if_left_source_block' => true,
            ]),
        );

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();
        $transition = ScenarioV3ScheduledTransition::query()->firstOrFail();

        $this->travelTo(now()->addMinutes(5));
        $transition->forceFill([
            'status' => ScenarioV3ScheduledTransition::STATUS_PROCESSING,
            'processing_started_at' => now()->subMinutes(11),
        ])->save();

        (new ProcessScenarioV3ScheduledTransitionJob($transition->id, $run->id))
            ->handle(app(ScenarioRegistry::class));

        $run->refresh();
        $transition->refresh();

        $this->assertSame(ScenarioV3ScheduledTransition::STATUS_PASSED, $transition->status);
        $this->assertSame('next', $run->current_step);
        $this->assertSame(2, $sendCount);
    }

    public function test_v3_delayed_transition_waits_for_outbound_delivery_before_passed_status(): void
    {
        Queue::fake([
            ProcessScenarioV3OutboundMessageJob::class,
            ProcessScenarioV3ScheduledTransitionJob::class,
        ]);

        $sendCount = 0;
        $sendAction = Mockery::mock(SendBotDialogTextAction::class);
        $sendAction
            ->shouldReceive('handleMessage')
            ->twice()
            ->andReturnUsing(function (...$args) use (&$sendCount): BotDialogTextSendResult {
                $sendCount++;
                $message = $args[0];
                $text = (string) $args[1];
                $dialog = $message instanceof Message && $message->dialog instanceof Dialog
                    ? $message->dialog
                    : Dialog::query()->findOrFail($message->dialog_id);

                if ($text === 'Автоматический переход') {
                    return $this->blockedBotSendResult($dialog);
                }

                return $this->successfulBotSendResult($dialog, $text, 'mock-v3-delayed-outbox-'.$sendCount);
            });
        $this->app->instance(SendBotDialogTextAction::class, $sendAction);

        $this->travelTo(now()->startOfSecond());
        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario(
            'v3_delayed_outbox_retry',
            $this->v3AutomaticRuntimeSchema($channel->id, delay: [
                'type' => 'relative',
                'value' => 5,
                'unit' => 'min',
                'cancel_if_left_source_block' => true,
            ]),
        );

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();
        $transition = ScenarioV3ScheduledTransition::query()->firstOrFail();

        $this->travelTo(now()->addMinutes(5));

        (new ProcessScenarioV3ScheduledTransitionJob($transition->id, $run->id))
            ->handle(app(ScenarioRegistry::class));

        $transition->refresh();
        $outbound = ScenarioV3OutboundMessage::query()
            ->where('scheduled_transition_id', $transition->id)
            ->firstOrFail();

        $this->assertSame(ScenarioV3ScheduledTransition::STATUS_DELIVERY_PENDING, $transition->status);
        $this->assertSame(ScenarioV3OutboundMessage::STATUS_PENDING, $outbound->status);
        $this->assertSame(1, $outbound->attempts);
        Queue::assertPushed(ProcessScenarioV3OutboundMessageJob::class, fn (ProcessScenarioV3OutboundMessageJob $job): bool => $job->outboundMessageId === $outbound->id);

        $retryAction = Mockery::mock(SendBotDialogTextAction::class);
        $retryAction
            ->shouldReceive('handleMessage')
            ->once()
            ->andReturnUsing(fn (...$args): BotDialogTextSendResult => $this->successfulBotSendResult($dialog, (string) $args[1], 'mock-v3-delayed-outbox-retry'));
        $this->app->instance(SendBotDialogTextAction::class, $retryAction);

        $this->travelTo($outbound->available_at->copy()->addSecond());

        (new ProcessScenarioV3OutboundMessageJob($outbound->id))
            ->handle(app(ScenarioRegistry::class));

        $transition->refresh();
        $outbound->refresh();

        $this->assertSame(ScenarioV3ScheduledTransition::STATUS_PASSED, $transition->status);
        $this->assertSame(ScenarioV3OutboundMessage::STATUS_SENT, $outbound->status);
    }

    public function test_v3_delayed_transition_error_message_is_sanitized(): void
    {
        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario('v3_delayed_error_sanitize', $this->v3AutomaticRuntimeSchema($channel->id));

        $inboundMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);
        $run = ScenarioRun::query()->create([
            'dialog_id' => $dialog->id,
            'scenario_code' => $scenario->code,
            'status' => ScenarioRun::STATUS_ACTIVE,
            'current_step' => 'start',
            'state_payload' => [
                'v3' => [
                    'schema_version' => 3,
                    'published_version_id' => $scenario->publishedVersion?->id,
                    'current_block_id' => 'start',
                ],
            ],
            'started_at' => now()->subMinute(),
        ]);
        $transition = ScenarioV3ScheduledTransition::query()->create([
            'scenario_run_id' => $run->id,
            'dialog_id' => $dialog->id,
            'inbound_message_id' => $inboundMessage->id,
            'scenario_code' => $scenario->code,
            'published_version_id' => $scenario->publishedVersion?->id,
            'edge_key' => 'edge_auto_next',
            'edge_id' => 'edge_auto_next',
            'source_block_id' => 'start',
            'target_block_id' => 'next',
            'delay_payload' => ['type' => 'relative', 'value' => 5, 'unit' => 'min'],
            'scheduled_for' => now()->addMinutes(5),
            'status' => ScenarioV3ScheduledTransition::STATUS_PROCESSING,
        ]);

        $runtime = app(ScenarioRegistry::class)->makeRuntime($scenario->code);
        $this->assertInstanceOf(GenericDbScenarioRuntime::class, $runtime);

        $method = new \ReflectionMethod($runtime, 'finishV3ScheduledTransition');
        $method->setAccessible(true);
        $method->invoke(
            $runtime,
            $transition,
            ScenarioV3ScheduledTransition::STATUS_FAILED,
            'POST https://api.telegram.org/bottelegram-token/sendMessage failed token=telegram-token',
        );

        $transition->refresh();

        $this->assertSame(ScenarioV3ScheduledTransition::STATUS_FAILED, $transition->status);
        $this->assertStringNotContainsString('telegram-token', (string) $transition->error_message);
        $this->assertStringContainsString('[secret]', (string) $transition->error_message);
    }

    public function test_v3_scheduled_automatic_edge_is_scheduled_for_exact_time_and_processed(): void
    {
        Queue::fake([
            ProcessScenarioV3ScheduledTransitionJob::class,
        ]);
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9471]])
                ->push(['ok' => true, 'result' => ['message_id' => 9472]]),
        ]);

        $now = now()->startOfSecond();
        $this->travelTo($now);
        $scheduledAt = $now->copy()->addMinutes(10);
        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario(
            'v3_automatic_scheduled',
            $this->v3AutomaticRuntimeSchema($channel->id, delay: [
                'type' => 'scheduled',
                'value' => 0,
                'unit' => 'sec',
                'scheduled_at' => $scheduledAt->toIso8601String(),
                'cancel_if_left_source_block' => true,
            ]),
        );

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();
        $transition = ScenarioV3ScheduledTransition::query()->firstOrFail();

        Queue::assertPushed(ProcessScenarioV3ScheduledTransitionJob::class, fn (ProcessScenarioV3ScheduledTransitionJob $job): bool => $job->scheduledTransitionId === $transition->id
            && $job->scenarioRunId === $run->id);

        $this->assertSame(ScenarioV3ScheduledTransition::STATUS_SCHEDULED, $transition->status);
        $this->assertSame('start', $run->current_step);
        $this->assertTrue($transition->scheduled_for->equalTo($scheduledAt));

        (new ProcessScenarioV3ScheduledTransitionJob($transition->id, $run->id))
            ->handle(app(ScenarioRegistry::class));

        $transition->refresh();
        $run->refresh();

        $this->assertSame(ScenarioV3ScheduledTransition::STATUS_SCHEDULED, $transition->status);
        $this->assertSame('start', $run->current_step);

        $this->travelTo($scheduledAt);
        $channel->forceFill(['connection_checked_at' => now()])->save();
        (new ProcessScenarioV3ScheduledTransitionJob($transition->id, $run->id))
            ->handle(app(ScenarioRegistry::class));

        $run->refresh();
        $transition->refresh();
        $dialog->refresh();
        $counterKey = 'published_'.$scenario->publishedVersion->id.':edge_auto_next';

        $this->assertSame(ScenarioV3ScheduledTransition::STATUS_PASSED, $transition->status);
        $this->assertSame('next', $run->current_step);
        $this->assertSame('next', data_get($run->state_payload, 'v3.current_block_id'));
        $this->assertSame(1, data_get($dialog->fields_payload, '_v3.transition_counts.'.$counterKey));

        $sentTexts = Http::recorded()
            ->map(fn (array $record): string => (string) $record[0]['text'])
            ->values()
            ->all();

        $this->assertSame(['Стартовый блок', 'Автоматический переход'], $sentTexts);
    }

    public function test_v3_scheduled_automatic_edge_uses_original_published_version_after_republish(): void
    {
        Queue::fake([
            ProcessScenarioV3ScheduledTransitionJob::class,
        ]);
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9481]])
                ->push(['ok' => true, 'result' => ['message_id' => 9482]]),
        ]);

        $now = now()->startOfSecond();
        $this->travelTo($now);
        $scheduledAt = $now->copy()->addMinutes(10);
        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario(
            'v3_automatic_scheduled_republish',
            $this->v3AutomaticRuntimeSchema($channel->id, delay: [
                'type' => 'scheduled',
                'value' => 0,
                'unit' => 'sec',
                'scheduled_at' => $scheduledAt->toIso8601String(),
                'cancel_if_left_source_block' => true,
            ]),
        );
        $firstPublishedVersion = $scenario->publishedVersion()->firstOrFail();

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();
        $transition = ScenarioV3ScheduledTransition::query()->firstOrFail();
        $secondSchema = $this->v3AutomaticRuntimeSchema($channel->id);
        data_set($secondSchema, 'builder_v3_runtime.blocks.next.message.text', 'Новая опубликованная версия');

        $firstPublishedVersion->forceFill([
            'status' => ScenarioVersion::STATUS_ARCHIVED,
        ])->save();
        ScenarioVersion::query()->create([
            'scenario_id' => $scenario->id,
            'version_number' => 2,
            'status' => ScenarioVersion::STATUS_PUBLISHED,
            'schema_payload' => $secondSchema,
        ]);
        app(ScenarioRegistry::class)->forgetCachedDefinitions();

        $this->travelTo($scheduledAt);
        $channel->forceFill(['connection_checked_at' => now()])->save();
        (new ProcessScenarioV3ScheduledTransitionJob($transition->id, $run->id))
            ->handle(app(ScenarioRegistry::class));

        $run->refresh();
        $transition->refresh();

        $this->assertSame(ScenarioV3ScheduledTransition::STATUS_PASSED, $transition->status);
        $this->assertSame((int) $firstPublishedVersion->id, (int) $transition->published_version_id);
        $this->assertSame('next', $run->current_step);

        $sentTexts = Http::recorded()
            ->map(fn (array $record): string => (string) $record[0]['text'])
            ->values()
            ->all();

        $this->assertSame(['Стартовый блок', 'Автоматический переход'], $sentTexts);
    }

    public function test_v3_delayed_automatic_edge_cancels_when_dialog_left_source_block(): void
    {
        Queue::fake([
            ProcessScenarioV3ScheduledTransitionJob::class,
        ]);
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9461]]),
        ]);

        $this->travelTo(now()->startOfSecond());
        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario(
            'v3_automatic_delayed_cancel',
            $this->v3AutomaticRuntimeSchema($channel->id, delay: [
                'type' => 'relative',
                'value' => 5,
                'unit' => 'min',
                'cancel_if_left_source_block' => true,
            ]),
        );

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();
        $transition = ScenarioV3ScheduledTransition::query()->firstOrFail();
        $statePayload = $run->state_payload;
        data_set($statePayload, 'v3.current_block_id', 'next');
        $run->forceFill([
            'current_step' => 'next',
            'state_payload' => $statePayload,
        ])->save();

        $this->travelTo(now()->addMinutes(5));
        (new ProcessScenarioV3ScheduledTransitionJob($transition->id, $run->id))
            ->handle(app(ScenarioRegistry::class));

        $run->refresh();
        $transition->refresh();
        $dialog->refresh();
        $counterKey = 'published_'.$scenario->publishedVersion->id.':edge_auto_next';

        $this->assertSame(ScenarioV3ScheduledTransition::STATUS_CANCELLED, $transition->status);
        $this->assertSame('next', $run->current_step);
        $this->assertNull(data_get($dialog->fields_payload, '_v3.transition_counts.'.$counterKey));

        $sentTexts = Http::recorded()
            ->map(fn (array $record): string => (string) $record[0]['text'])
            ->values()
            ->all();

        $this->assertSame(['Стартовый блок'], $sentTexts);
    }

    public function test_v3_start_chooses_one_matching_entrypoint_by_highest_priority_then_latest_block_id(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9101]]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $schema = $this->v3CatalogRuntimeSchema($channel->id);
        $schema['builder_v3_runtime']['entrypoints'] = [
            [
                'block_id' => '13',
                'channel_ids' => [$channel->id],
                'match' => 'strict',
                'values' => ['старт'],
                'priority' => 9,
            ],
            [
                'block_id' => '12',
                'channel_ids' => [$channel->id],
                'match' => 'strict',
                'values' => ['старт'],
                'priority' => 10,
            ],
            [
                'block_id' => '11',
                'channel_ids' => [$channel->id],
                'match' => 'strict',
                'values' => ['старт'],
                'priority' => 10,
            ],
        ];
        $schema['builder_v3_runtime']['blocks'] = [
            '11' => [
                'id' => '11',
                'db_id' => 11,
                'kind' => 'non_state',
                'title' => 'Серый',
                'message' => [
                    'text' => 'Ответ серого блока',
                    'text_format' => Message::TEXT_FORMAT_PLAIN_TEXT,
                ],
                'buttons' => null,
                'default_target_block_id' => null,
            ],
            '12' => [
                'id' => '12',
                'db_id' => 12,
                'kind' => 'state',
                'title' => 'Белый',
                'message' => [
                    'text' => 'Ответ белого блока',
                    'text_format' => Message::TEXT_FORMAT_PLAIN_TEXT,
                ],
                'buttons' => null,
                'default_target_block_id' => null,
            ],
            '13' => [
                'id' => '13',
                'db_id' => 13,
                'kind' => 'state',
                'title' => 'Низкий приоритет',
                'message' => [
                    'text' => 'Ответ низкого приоритета',
                    'text_format' => Message::TEXT_FORMAT_PLAIN_TEXT,
                ],
                'buttons' => null,
                'default_target_block_id' => null,
            ],
        ];
        $schema['builder_v3_runtime']['edges'] = [];
        $scenario = $this->createPublishedScenario('v3_best_matching_start', $schema);

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
            'message_parameter' => null,
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $sentTexts = Http::recorded()
            ->map(fn (array $record): string => (string) $record[0]['text'])
            ->values()
            ->all();

        $this->assertSame(['Ответ белого блока'], $sentTexts);
    }

    public function test_v3_exact_callback_start_condition_ignores_plain_text_and_parameters(): void
    {
        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $schema = $this->v3CatalogRuntimeSchema($channel->id);

        data_set($schema, 'builder_v3_runtime.entrypoints.0.match', 'exact_callback');
        data_set($schema, 'builder_v3_runtime.entrypoints.0.values', ['callback_start']);

        $scenario = $this->createPublishedScenario('v3_callback_start', $schema);

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $callbackMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'callback_start',
            'message_parameter' => null,
            'raw_payload' => [
                'callback_query' => [
                    'data' => 'callback_start',
                ],
            ],
        ]);
        $plainTextMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'callback_start',
            'message_parameter' => null,
            'raw_payload' => [
                'message' => [
                    'text' => 'callback_start',
                ],
            ],
        ]);
        $parameterMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => '/start callback_start',
            'message_parameter' => 'callback_start',
            'raw_payload' => [
                'message' => [
                    'text' => '/start callback_start',
                ],
            ],
        ]);

        $runtime = app(ScenarioRegistry::class)->makeRuntime($scenario->code);

        $this->assertNotNull($runtime);
        $this->assertTrue($runtime->shouldStart($callbackMessage));
        $this->assertFalse($runtime->shouldStart($plainTextMessage));
        $this->assertFalse($runtime->shouldStart($parameterMessage));
    }

    public function test_v3_start_condition_respects_contact_phone_condition(): void
    {
        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $schema = $this->v3CatalogRuntimeSchema($channel->id);

        data_set($schema, 'builder_v3_runtime.entrypoints.0.contact_phone_condition', AutoReplyRule::CONTACT_PHONE_CONDITION_HAS_PHONE);

        $scenario = $this->createPublishedScenario('v3_phone_condition_start', $schema);

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $messageWithoutPhone = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
            'message_parameter' => null,
        ]);

        $runtime = app(ScenarioRegistry::class)->makeRuntime($scenario->code);

        $this->assertNotNull($runtime);
        $this->assertFalse($runtime->shouldStart($messageWithoutPhone));

        ContactPhoneNumber::factory()->create([
            'contact_id' => $contact->id,
        ]);

        $messageWithPhone = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
            'message_parameter' => null,
        ]);

        $this->assertTrue($runtime->shouldStart($messageWithPhone));
    }

    public function test_v3_start_condition_respects_dialog_phone_condition(): void
    {
        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $schema = $this->v3CatalogRuntimeSchema($channel->id);

        data_set($schema, 'builder_v3_runtime.entrypoints.0.dialog_phone_condition', AutoReplyRule::CONTACT_PHONE_CONDITION_HAS_PHONE);

        $scenario = $this->createPublishedScenario('v3_dialog_phone_condition_start', $schema);

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $messageWithoutPhone = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
            'message_parameter' => null,
        ]);

        $runtime = app(ScenarioRegistry::class)->makeRuntime($scenario->code);

        $this->assertNotNull($runtime);
        $this->assertFalse($runtime->shouldStart($messageWithoutPhone));

        $dialog->forceFill([
            'confirmed_phone_raw' => '+7 926 352 71 11',
            'confirmed_phone_normalized' => '+79263527111',
        ])->save();

        $messageWithPhone = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
            'message_parameter' => null,
        ]);

        $this->assertTrue($runtime->shouldStart($messageWithPhone));
    }

    public function test_v3_non_state_button_target_sends_message_and_does_not_wait(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9201]])
                ->push(['ok' => true, 'result' => ['message_id' => 9202]]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario('v3_non_state_runtime', $this->v3NonStateRuntimeSchema($channel->id));

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
            'message_parameter' => null,
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();

        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('ask_phone', $run->current_step);

        $invalidPhoneMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'Это не телефон',
        ]);

        (new ProcessScenarioInboundJob($invalidPhoneMessage->id, $run->id))
            ->handle(app(ScenarioRegistry::class));

        $run->refresh();

        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('ask_phone', $run->current_step);
        $this->assertSame('waiting_input', data_get($run->state_payload, 'v3.status'));
        $this->assertSame('ask_phone', data_get($run->state_payload, 'v3.current_block_id'));
        $this->assertSame(['btn_invalid_phone'], data_get($run->state_payload, 'v3.waiting_output_ids'));

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === $dialog->external_chat_id
            && $request['text'] === 'Это не номер телефона');
    }

    public function test_v3_request_phone_button_waits_for_contact_share_and_advances(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9401]])
                ->push(['ok' => true, 'result' => ['message_id' => 9402]]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario('v3_request_phone_button', $this->v3RequestPhoneButtonRuntimeSchema($channel->id));

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
            'message_parameter' => null,
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();

        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('ask_phone', $run->current_step);
        $this->assertSame(['btn_phone'], data_get($run->state_payload, 'v3.waiting_output_ids'));
        $this->assertTrue(app(ScenarioRegistry::class)->makeRuntime($scenario->code)->supportsContactShareContinuation($run));

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === $dialog->external_chat_id
            && $request['text'] === 'Поделитесь телефоном'
            && data_get($request->data(), 'reply_markup.keyboard.0.0.text') === 'Поделиться номером телефона'
            && data_get($request->data(), 'reply_markup.keyboard.0.0.request_contact') === true);

        $phoneShare = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_CONTACT_SHARE,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => null,
        ]);

        (new ProcessScenarioInboundJob($phoneShare->id, $run->id))
            ->handle(app(ScenarioRegistry::class));

        $run->refresh();

        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('thanks', $run->current_step);
        $this->assertSame('waiting_input', data_get($run->state_payload, 'v3.status'));
        $this->assertSame('thanks', data_get($run->state_payload, 'v3.current_block_id'));
        $this->assertSame([], data_get($run->state_payload, 'v3.waiting_output_ids'));

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === $dialog->external_chat_id
            && $request['text'] === 'Спасибо, телефон получен'
            && data_get($request->data(), 'reply_markup.remove_keyboard') === true);
    }

    public function test_v3_contact_share_reevaluates_current_block_automatic_edges(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9403]])
                ->push(['ok' => true, 'result' => ['message_id' => 9404]]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $schema = $this->v3RequestPhoneButtonRuntimeSchema($channel->id);

        data_set($schema, 'builder_v3_runtime.blocks.ask_phone.buttons.rows.0.0.target_block_id', null);
        data_set($schema, 'builder_v3_runtime.blocks.ask_phone.automatic_edges', [[
            'id' => 'edge_auto_has_phone',
            'edge_key' => 'edge_auto_has_phone',
            'mode' => 'automatic',
            'priority' => 10,
            'transition_limit' => 0,
            'source_block_id' => 'ask_phone',
            'target_block_id' => 'after_phone',
            'from_output_id' => null,
            'label' => 'Телефон есть',
            'contact_phone_condition' => AutoReplyRule::CONTACT_PHONE_CONDITION_HAS_PHONE,
            'dialog_phone_condition' => '',
            'match' => [
                'type' => 'any_inbound',
                'text' => '',
                'variants' => [],
            ],
        ]]);
        data_set($schema, 'builder_v3_runtime.blocks.after_phone', [
            'id' => 'after_phone',
            'db_id' => 3,
            'kind' => 'state',
            'title' => 'Телефон есть',
            'message' => [
                'text' => 'Продолжим анкету',
                'text_format' => Message::TEXT_FORMAT_PLAIN_TEXT,
            ],
            'buttons' => null,
            'wait_reply_edges' => [],
            'automatic_edges' => [],
            'default_target_block_id' => null,
        ]);
        data_set($schema, 'builder_v3_runtime.edges', data_get($schema, 'builder_v3_runtime.blocks.ask_phone.automatic_edges'));

        $scenario = $this->createPublishedScenario('v3_request_phone_auto_after_share', $schema);

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
            'message_parameter' => null,
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();

        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('ask_phone', $run->current_step);
        $this->assertTrue(app(ScenarioRegistry::class)->makeRuntime($scenario->code)->supportsContactShareContinuation($run));

        $contact->phoneNumbers()->create([
            'phone_raw' => '+79263527111',
            'phone_normalized' => '+79263527111',
            'source' => ContactPhoneNumber::SOURCE_TELEGRAM_CONTACT_SHARE,
            'is_primary' => true,
        ]);

        $phoneShare = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_CONTACT_SHARE,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => null,
        ]);

        (new ProcessScenarioInboundJob($phoneShare->id, $run->id))
            ->handle(app(ScenarioRegistry::class));

        $run->refresh();

        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('after_phone', $run->current_step);
        $this->assertSame('after_phone', data_get($run->state_payload, 'v3.current_block_id'));

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === $dialog->external_chat_id
            && $request['text'] === 'Продолжим анкету'
            && data_get($request->data(), 'reply_markup.remove_keyboard') === true);
    }

    public function test_v3_plain_inbound_reevaluates_current_block_automatic_edges(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9411]]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $edge = [
            'id' => 'edge_auto_next',
            'edge_key' => 'edge_auto_next',
            'mode' => 'automatic',
            'priority' => 10,
            'transition_limit' => 0,
            'source_block_id' => 'current',
            'target_block_id' => 'next',
            'from_output_id' => null,
            'label' => 'Дальше',
            'match' => [
                'type' => 'any_inbound',
                'text' => '',
                'variants' => [],
            ],
        ];
        $scenario = $this->createPublishedScenario('v3_plain_inbound_auto_edge', [
            'version' => 3,
            'builder_v3_runtime' => [
                'schema_version' => 3,
                'source_revision' => 'v3:test',
                'compiled_at' => now()->toISOString(),
                'entrypoints' => [],
                'blocks' => [
                    'current' => [
                        'id' => 'current',
                        'db_id' => 1,
                        'kind' => 'state',
                        'title' => 'Текущий',
                        'message' => null,
                        'buttons' => null,
                        'wait_reply_edges' => [],
                        'automatic_edges' => [$edge],
                        'default_target_block_id' => null,
                    ],
                    'next' => [
                        'id' => 'next',
                        'db_id' => 2,
                        'kind' => 'state',
                        'title' => 'Следующий',
                        'message' => [
                            'text' => 'Дальше пошли',
                            'text_format' => Message::TEXT_FORMAT_PLAIN_TEXT,
                        ],
                        'buttons' => null,
                        'wait_reply_edges' => [],
                        'automatic_edges' => [],
                        'default_target_block_id' => null,
                    ],
                ],
                'edges' => [$edge],
            ],
        ]);
        $run = ScenarioRun::query()->create([
            'scenario_code' => $scenario->code,
            'dialog_id' => $dialog->id,
            'status' => ScenarioRun::STATUS_ACTIVE,
            'current_step' => 'current',
            'state_payload' => [
                'v3' => [
                    'schema_version' => 3,
                    'current_block_id' => 'current',
                    'published_version_id' => $scenario->publishedVersion->id,
                ],
            ],
            'started_at' => now(),
        ]);
        $message = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => '1',
        ]);

        (new ProcessScenarioInboundJob($message->id, $run->id))
            ->handle(app(ScenarioRegistry::class));

        $run->refresh();

        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('next', $run->current_step);
        $this->assertSame('next', data_get($run->state_payload, 'v3.current_block_id'));

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === $dialog->external_chat_id
            && $request['text'] === 'Дальше пошли');
    }

    public function test_v3_request_phone_share_cleans_reply_keyboard_before_next_inline_buttons(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9411]])
                ->push(['ok' => true, 'result' => ['message_id' => 9412]])
                ->push(['ok' => true, 'result' => true])
                ->push(['ok' => true, 'result' => ['message_id' => 9413]]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $schema = $this->v3RequestPhoneButtonRuntimeSchema($channel->id);
        data_set($schema, 'builder_v3_runtime.blocks.thanks.buttons', [
            'placement' => 'inline_message',
            'rows' => [[
                [
                    'id' => 'btn_gender_male',
                    'text' => 'Мужской',
                    'type' => 'text',
                    'normalized_text' => 'мужской',
                    'output_id' => 'gender_male',
                    'target_block_id' => null,
                ],
            ]],
        ]);
        $scenario = $this->createPublishedScenario('v3_request_phone_then_inline', $schema);

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
            'message_parameter' => null,
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();
        $phoneShare = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_CONTACT_SHARE,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => null,
        ]);

        (new ProcessScenarioInboundJob($phoneShare->id, $run->id))
            ->handle(app(ScenarioRegistry::class));

        $run->refresh();
        $this->assertFalse((bool) data_get($run->state_payload, 'v3.pending_remove_telegram_keyboard', false));

        Http::assertSentCount(4);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && data_get($request->data(), 'reply_markup.keyboard.0.0.request_contact') === true);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && data_get($request->data(), 'reply_markup.remove_keyboard') === true
            && ($request['disable_notification'] ?? false) === true);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/deleteMessage'
            && $request['chat_id'] === $dialog->external_chat_id
            && (string) $request['message_id'] === '9412');
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['text'] === 'Спасибо, телефон получен'
            && data_get($request->data(), 'reply_markup.inline_keyboard.0.0.text') === 'Мужской'
            && data_get($request->data(), 'reply_markup.remove_keyboard') === null);
    }

    public function test_v3_inline_message_buttons_render_telegram_inline_and_manual_text_advances(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9501]])
                ->push(['ok' => true, 'result' => ['message_id' => 9502]]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario(
            'v3_inline_text_button',
            $this->v3CatalogRuntimeSchema($channel->id, placement: 'inline_message'),
        );

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();

        $this->assertSame(['btn_catalog'], data_get($run->state_payload, 'v3.waiting_output_ids'));
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && data_get($request->data(), 'reply_markup.inline_keyboard.0.0.text') === 'Получить каталог'
            && data_get($request->data(), 'reply_markup.inline_keyboard.0.0.callback_data') === 'v3b:start:btn_catalog'
            && data_get($request->data(), 'reply_markup.keyboard') === null);

        $buttonText = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'Получить каталог',
        ]);

        (new ProcessScenarioInboundJob($buttonText->id, $run->id))
            ->handle(app(ScenarioRegistry::class));

        $run->refresh();

        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('catalog', $run->current_step);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['text'] === 'Вот каталог');
    }

    public function test_v3_inline_message_callback_must_match_current_block(): void
    {
        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $schema = $this->v3CatalogRuntimeSchema($channel->id, placement: 'inline_message');
        data_set($schema, 'builder_v3_runtime.blocks.catalog.buttons', [
            'placement' => 'inline_message',
            'rows' => [[[
                'id' => 'btn_catalog',
                'text' => 'Повторить каталог',
                'type' => 'text',
                'normalized_text' => 'повторить каталог',
                'output_id' => 'btn_catalog',
                'target_block_id' => 'start',
            ]]],
        ]);
        data_set($schema, 'builder_v3_runtime.edges.1', [
            'id' => 'edge_back',
            'source_block_id' => 'catalog',
            'target_block_id' => 'start',
            'from_output_id' => 'btn_catalog',
            'label' => 'Назад',
        ]);
        $scenario = $this->createPublishedScenario('v3_inline_callback_block_guard', $schema);
        $run = ScenarioRun::query()->create([
            'dialog_id' => $dialog->id,
            'scenario_code' => $scenario->code,
            'status' => ScenarioRun::STATUS_ACTIVE,
            'current_step' => 'catalog',
            'state_payload' => [
                'v3' => [
                    'schema_version' => 3,
                    'current_block_id' => 'catalog',
                    'status' => 'waiting_input',
                    'waiting_output_ids' => ['btn_catalog'],
                ],
            ],
            'started_at' => now()->subMinute(),
        ]);

        $runtime = app(ScenarioRegistry::class)->makeRuntime($scenario->code);

        $this->assertNotNull($runtime);
        $this->assertFalse($runtime->supportsTelegramCallbackContinuation($run, 'v3b:btn_catalog'));
        $this->assertFalse($runtime->supportsTelegramCallbackContinuation($run, 'v3b:start:btn_catalog'));
        $this->assertTrue($runtime->supportsTelegramCallbackContinuation($run, 'v3b:catalog:btn_catalog'));
    }

    public function test_v3_link_button_renders_telegram_inline_url(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 9521],
            ]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario(
            'v3_telegram_link_button',
            $this->v3LinkButtonRuntimeSchema($channel->id),
        );

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && data_get($request->data(), 'reply_markup.inline_keyboard.0.0.text') === 'Открыть сайт'
            && data_get($request->data(), 'reply_markup.inline_keyboard.0.0.url') === 'https://example.com/form'
            && data_get($request->data(), 'reply_markup.inline_keyboard.0.0.callback_data') === null
            && data_get($request->data(), 'reply_markup.keyboard') === null);
    }

    public function test_v3_link_button_renders_max_link_attachment(): void
    {
        Http::fake([
            'https://platform-api.max.ru/*' => Http::response([
                'message' => ['message_id' => 'max-v3-link-1'],
            ]),
        ]);

        $channel = $this->createMaxChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext(
            $channel,
            identityOverrides: ['external_user_id' => 'max-user-500'],
            dialogOverrides: ['external_chat_id' => 'max-chat-700'],
        );
        $scenario = $this->createPublishedScenario(
            'v3_max_link_button',
            $this->v3LinkButtonRuntimeSchema($channel->id),
        );

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://platform-api.max.ru/messages')
            && data_get($request->data(), 'attachments.0.type') === 'inline_keyboard'
            && data_get($request->data(), 'attachments.0.payload.buttons.0.0.type') === 'link'
            && data_get($request->data(), 'attachments.0.payload.buttons.0.0.text') === 'Открыть сайт'
            && data_get($request->data(), 'attachments.0.payload.buttons.0.0.url') === 'https://example.com/form');
    }

    public function test_v3_inline_message_request_phone_is_hidden_for_telegram_but_contact_share_advances(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9511]])
                ->push(['ok' => true, 'result' => ['message_id' => 9512]]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario(
            'v3_inline_hidden_phone',
            $this->v3RequestPhoneButtonRuntimeSchema($channel->id, placement: 'inline_message'),
        );

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();

        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('ask_phone', $run->current_step);
        $this->assertSame([], data_get($run->state_payload, 'v3.waiting_output_ids'));
        $this->assertTrue(app(ScenarioRegistry::class)->makeRuntime($scenario->code)->supportsContactShareContinuation($run));
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['text'] === 'Поделитесь телефоном'
            && data_get($request->data(), 'reply_markup') === null);

        $phoneShare = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_CONTACT_SHARE,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => null,
        ]);

        (new ProcessScenarioInboundJob($phoneShare->id, $run->id))
            ->handle(app(ScenarioRegistry::class));

        $run->refresh();

        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('thanks', $run->current_step);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['text'] === 'Спасибо, телефон получен');
    }

    public function test_v3_reply_keyboard_buttons_are_sent_as_inline_keyboard_for_max(): void
    {
        Http::fake([
            'https://platform-api.max.ru/*' => Http::sequence()
                ->push(['message' => ['mid' => 'max-out-1']])
                ->push(['message' => ['mid' => 'max-out-2']]),
        ]);

        $channel = $this->createMaxChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext(
            $channel,
            identityOverrides: ['external_user_id' => 'max-user-500'],
            dialogOverrides: ['external_chat_id' => 'max-chat-700'],
        );
        $scenario = $this->createPublishedScenario(
            'v3_max_reply_keyboard_as_inline',
            $this->v3CatalogRuntimeSchema($channel->id, placement: 'reply_keyboard'),
        );

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();

        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('start', $run->current_step);
        $this->assertSame(['btn_catalog'], data_get($run->state_payload, 'v3.waiting_output_ids'));
        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://platform-api.max.ru/messages')
            && $request['text'] === 'Выберите действие'
            && data_get($request->data(), 'attachments.0.type') === 'inline_keyboard'
            && data_get($request->data(), 'attachments.0.payload.buttons.0.0.type') === 'message'
            && data_get($request->data(), 'attachments.0.payload.buttons.0.0.text') === 'Получить каталог');

        $buttonText = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'Получить каталог',
        ]);

        (new ProcessScenarioInboundJob($buttonText->id, $run->id))
            ->handle(app(ScenarioRegistry::class));

        $run->refresh();

        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('catalog', $run->current_step);
        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://platform-api.max.ru/messages')
            && $request['text'] === 'Вот каталог');
    }

    public function test_v3_request_phone_button_to_non_state_keeps_current_state_after_contact_share(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9451]])
                ->push(['ok' => true, 'result' => ['message_id' => 9452]])
                ->push(['ok' => true, 'result' => ['message_id' => 9453]]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario(
            'v3_request_phone_non_state_target',
            $this->v3RequestPhoneNonStateRuntimeSchema($channel->id),
        );

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
            'message_parameter' => null,
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();
        $this->assertSame('ask_phone', $run->current_step);

        $phoneShare = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_CONTACT_SHARE,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => null,
        ]);

        (new ProcessScenarioInboundJob($phoneShare->id, $run->id))
            ->handle(app(ScenarioRegistry::class));

        $run->refresh();

        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('ask_phone', $run->current_step);
        $this->assertSame('ask_phone', data_get($run->state_payload, 'v3.current_block_id'));
        $this->assertSame(['btn_phone'], data_get($run->state_payload, 'v3.waiting_output_ids'));

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === $dialog->external_chat_id
            && $request['text'] === 'Телефон получен серым блоком');

        $secondPhoneShare = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_CONTACT_SHARE,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => null,
        ]);

        (new ProcessScenarioInboundJob($secondPhoneShare->id, $run->id))
            ->handle(app(ScenarioRegistry::class));

        $run->refresh();

        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('ask_phone', $run->current_step);
        $this->assertSame('ask_phone', data_get($run->state_payload, 'v3.current_block_id'));
        $this->assertSame(['btn_phone'], data_get($run->state_payload, 'v3.waiting_output_ids'));

        $sentTexts = Http::recorded()
            ->map(fn (array $record): string => (string) $record[0]['text'])
            ->values()
            ->all();

        $this->assertSame([
            'Поделитесь телефоном',
            'Телефон получен серым блоком',
            'Телефон получен серым блоком',
        ], $sentTexts);
        $this->assertNotContains('Телефон принят из серого контекста', $sentTexts);
    }

    public function test_v3_non_state_start_runs_without_cancelling_current_state(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9301]])
                ->push(['ok' => true, 'result' => ['message_id' => 9302]]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario('v3_non_state_start_overlay', $this->v3NonStateStartOverlayRuntimeSchema($channel->id));

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $run = ScenarioRun::query()->create([
            'dialog_id' => $dialog->id,
            'scenario_code' => $scenario->code,
            'status' => ScenarioRun::STATUS_ACTIVE,
            'current_step' => 'block_1',
            'state_payload' => [
                'v3' => [
                    'schema_version' => 3,
                    'published_version_id' => $scenario->publishedVersion?->id,
                    'status' => 'waiting_input',
                    'current_block_id' => 'block_1',
                    'waiting_output_ids' => ['btn_1'],
                ],
            ],
            'started_at' => now()->subMinute(),
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт серый',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run->refresh();

        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('block_1', $run->current_step);
        $this->assertSame('block_1', data_get($run->state_payload, 'v3.current_block_id'));
        $this->assertSame(['btn_1'], data_get($run->state_payload, 'v3.waiting_output_ids'));

        $buttonMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'Кнопка 1',
        ]);

        (new ProcessScenarioInboundJob($buttonMessage->id, $run->id))
            ->handle(app(ScenarioRegistry::class));

        $run->refresh();

        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('after_button', $run->current_step);
        $this->assertSame('waiting_input', data_get($run->state_payload, 'v3.status'));
        $this->assertSame('after_button', data_get($run->state_payload, 'v3.current_block_id'));

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === $dialog->external_chat_id
            && $request['text'] === 'Серый ответ');
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === $dialog->external_chat_id
            && $request['text'] === 'Кнопка сработала');
    }

    public function test_v3_non_state_start_does_not_follow_outgoing_edges(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 9311]])
                ->push(['ok' => true, 'result' => ['message_id' => 9312]]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $schema = $this->v3NonStateStartOverlayRuntimeSchema($channel->id);
        data_set($schema, 'builder_v3_runtime.blocks.overlay.default_target_block_id', 'after_overlay');
        data_set($schema, 'builder_v3_runtime.blocks.after_overlay', [
            'id' => 'after_overlay',
            'db_id' => 4,
            'kind' => 'state',
            'title' => 'После серого',
            'message' => [
                'text' => 'Этого сообщения быть не должно',
                'text_format' => Message::TEXT_FORMAT_PLAIN_TEXT,
            ],
            'buttons' => null,
            'default_target_block_id' => null,
        ]);
        $scenario = $this->createPublishedScenario('v3_non_state_ignores_edges', $schema);

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $run = ScenarioRun::query()->create([
            'dialog_id' => $dialog->id,
            'scenario_code' => $scenario->code,
            'status' => ScenarioRun::STATUS_ACTIVE,
            'current_step' => 'block_1',
            'state_payload' => [
                'v3' => [
                    'schema_version' => 3,
                    'published_version_id' => $scenario->publishedVersion?->id,
                    'status' => 'waiting_input',
                    'current_block_id' => 'block_1',
                    'waiting_output_ids' => ['btn_1'],
                ],
            ],
            'started_at' => now()->subMinute(),
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт серый',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run->refresh();

        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('block_1', $run->current_step);
        $this->assertSame('block_1', data_get($run->state_payload, 'v3.current_block_id'));

        $sentTexts = Http::recorded()
            ->map(fn (array $record): string => (string) $record[0]['text'])
            ->values()
            ->all();

        $this->assertSame(['Серый ответ'], $sentTexts);
    }

    public function test_v3_runtime_accepts_legacy_db_id_current_step_and_moves_to_card_id(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 9501]]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario(
            'v3_legacy_db_id_current_step',
            $this->v3CardIdRuntimeSchema($channel->id),
        );

        $run = ScenarioRun::query()->create([
            'dialog_id' => $dialog->id,
            'scenario_code' => $scenario->code,
            'status' => ScenarioRun::STATUS_ACTIVE,
            'current_step' => '116',
            'state_payload' => [
                'v3' => [
                    'schema_version' => 3,
                    'published_version_id' => $scenario->publishedVersion?->id,
                    'status' => 'waiting_input',
                    'current_block_id' => '116',
                    'waiting_output_ids' => ['btn_next'],
                ],
            ],
            'started_at' => now()->subMinute(),
        ]);

        $buttonMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'Далее',
        ]);

        (new ProcessScenarioInboundJob($buttonMessage->id, $run->id))
            ->handle(app(ScenarioRegistry::class));

        $run->refresh();

        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('73', $run->current_step);
        $this->assertSame('73', data_get($run->state_payload, 'v3.current_block_id'));
        $this->assertSame([], data_get($run->state_payload, 'v3.waiting_output_ids'));

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === $dialog->external_chat_id
            && $request['text'] === 'Следующий блок');
    }

    public function test_v3_start_cancels_existing_active_run_before_starting(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 9101]]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario('v3_priority_runtime', $this->v3CatalogRuntimeSchema($channel->id));
        $oldRun = ScenarioRun::query()->create([
            'dialog_id' => $dialog->id,
            'scenario_code' => 'warmup',
            'status' => ScenarioRun::STATUS_ACTIVE,
            'current_step' => 'awaiting_reaction',
            'state_payload' => [],
            'started_at' => now()->subMinute(),
        ]);

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $oldRun->refresh();
        $newRun = ScenarioRun::query()
            ->where('scenario_code', $scenario->code)
            ->firstOrFail();

        $this->assertSame(ScenarioRun::STATUS_CANCELLED, $oldRun->status);
        $this->assertSame('interrupted_by_v3_start', $oldRun->exit_outcome);
        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $newRun->status);
        $this->assertSame('start', $newRun->current_step);
    }

    public function test_dispatcher_checks_v3_start_before_continuing_active_run(): void
    {
        Queue::fake();

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario('v3_dispatch_priority', $this->v3CatalogRuntimeSchema($channel->id));

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);
        ScenarioRun::query()->create([
            'dialog_id' => $dialog->id,
            'scenario_code' => 'warmup',
            'status' => ScenarioRun::STATUS_ACTIVE,
            'current_step' => 'awaiting_reaction',
            'state_payload' => [],
            'started_at' => now()->subMinute(),
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт',
        ]);

        $handled = app(DispatchStoredInboundScenarioAction::class)->handle($channel, $startMessage);

        $this->assertTrue($handled);
        Queue::assertPushed(ProcessScenarioStartJob::class, fn (ProcessScenarioStartJob $job): bool => $job->scenarioCode === $scenario->code);
        Queue::assertNotPushed(ProcessScenarioInboundJob::class);
    }

    public function test_published_builder_start_condition_only_starts_on_selected_channels(): void
    {
        $selectedChannel = $this->createTelegramChannel([
            'name' => 'Selected Telegram',
        ]);
        $otherChannel = $this->createTelegramChannel([
            'name' => 'Other Telegram',
        ]);
        [$selectedContact, $selectedIdentity, $selectedDialog] = $this->createDialogContext($selectedChannel, identityOverrides: [
            'external_user_id' => 'selected-user',
        ], dialogOverrides: [
            'external_chat_id' => 'selected-chat',
        ]);
        [$otherContact, $otherIdentity, $otherDialog] = $this->createDialogContext($otherChannel, identityOverrides: [
            'external_user_id' => 'other-user',
        ], dialogOverrides: [
            'external_chat_id' => 'other-chat',
        ]);
        $scenario = $this->createPublishedScenario('builder_channel_gate', [
            'version' => 1,
            'start_block_id' => 'welcome',
            'triggers' => [
                [
                    'type' => 'parameter',
                    'value' => 'green_start',
                    'match_scope' => AutoReplyRule::MATCH_SCOPE_CONTAINS_TEXT,
                ],
            ],
            'blocks' => [
                'welcome' => [
                    'type' => 'message',
                    'text' => 'Ответ выбранного канала.',
                    'next' => 'done',
                ],
                'done' => [
                    'type' => 'complete',
                ],
            ],
        ]);

        ScenarioChannelBinding::query()->insert([
            [
                'channel_id' => $selectedChannel->id,
                'scenario_code' => $scenario->code,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'channel_id' => $otherChannel->id,
                'scenario_code' => $scenario->code,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $builderBlock = ScenarioBuilderBlock::query()->create([
            'scenario_version_id' => $scenario->publishedVersion?->id,
            'type' => ScenarioBuilderBlock::TYPE_START_CONDITION,
            'title' => 'Старт по выбранному каналу',
            'position_x' => 120,
            'position_y' => 160,
            'settings_payload' => [
                'condition' => [
                    'match' => AutoReplyRule::MATCH_SCOPE_CONTAINS_TEXT,
                ],
            ],
        ]);
        $builderBlock->channels()->sync([$selectedChannel->id]);
        ScenarioBuilderCondition::query()->create([
            'scenario_builder_block_id' => $builderBlock->id,
            'type' => ScenarioBuilderCondition::TYPE_MESSAGE_PARAMETER,
            'match_operator' => AutoReplyRule::MATCH_SCOPE_CONTAINS_TEXT,
            'variable' => ScenarioBuilderCondition::VARIABLE_MESSAGE_PARAMETER,
            'value' => 'green_start',
            'sort_order' => 1,
        ]);

        app(ScenarioRegistry::class)->forgetCachedDefinitions();

        $selectedMessage = Message::factory()->create([
            'contact_id' => $selectedContact->id,
            'contact_identity_id' => $selectedIdentity->id,
            'channel_id' => $selectedChannel->id,
            'dialog_id' => $selectedDialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $selectedDialog->external_chat_id,
            'text' => 'Пожалуйста, запусти green_start сейчас',
            'message_parameter' => null,
        ]);
        $otherMessage = Message::factory()->create([
            'contact_id' => $otherContact->id,
            'contact_identity_id' => $otherIdentity->id,
            'channel_id' => $otherChannel->id,
            'dialog_id' => $otherDialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $otherDialog->external_chat_id,
            'text' => 'Пожалуйста, запусти green_start сейчас',
            'message_parameter' => null,
        ]);

        $runtime = app(ScenarioRegistry::class)->makeRuntime($scenario->code);

        $this->assertNotNull($runtime);
        $this->assertTrue($runtime->shouldStart($selectedMessage));
        $this->assertFalse($runtime->shouldStart($otherMessage));
    }

    public function test_empty_published_builder_start_condition_keeps_schema_trigger_fallback(): void
    {
        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario('builder_empty_fallback', [
            'version' => 1,
            'start_block_id' => 'welcome',
            'triggers' => [
                [
                    'type' => 'parameter',
                    'value' => 'legacy_start',
                ],
            ],
            'blocks' => [
                'welcome' => [
                    'type' => 'message',
                    'text' => 'Старый старт работает.',
                    'next' => 'done',
                ],
                'done' => [
                    'type' => 'complete',
                ],
            ],
        ]);

        ScenarioBuilderBlock::query()->create([
            'scenario_version_id' => $scenario->publishedVersion?->id,
            'type' => ScenarioBuilderBlock::TYPE_START_CONDITION,
            'title' => 'Пустой просмотренный блок',
            'position_x' => 120,
            'position_y' => 160,
            'settings_payload' => [
                'condition' => [
                    'match' => AutoReplyRule::MATCH_SCOPE_EXACT_PARAMETER,
                ],
            ],
        ]);

        app(ScenarioRegistry::class)->forgetCachedDefinitions();

        $message = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => '/start legacy_start',
            'message_parameter' => 'legacy_start',
        ]);

        $runtime = app(ScenarioRegistry::class)->makeRuntime($scenario->code);

        $this->assertNotNull($runtime);
        $this->assertTrue($runtime->shouldStart($message));
    }

    public function test_published_builder_start_condition_expression_filters_entrypoint(): void
    {
        $channel = $this->createTelegramChannel();
        [$contact, $identity, $matchingDialog] = $this->createDialogContext($channel, dialogOverrides: [
            'external_chat_id' => 'start-expression-match',
            'fields_payload' => ['start_param' => '123321'],
        ]);
        [, , $otherDialog] = $this->createDialogContext($channel, identityOverrides: [
            'external_user_id' => 'start-expression-other',
        ], dialogOverrides: [
            'external_chat_id' => 'start-expression-other',
            'fields_payload' => ['start_param' => 'other'],
        ]);
        ContactPhoneNumber::factory()->create([
            'contact_id' => $contact->id,
            'phone_raw' => '+7 926 352 71 11',
            'phone_normalized' => '+79263527111',
        ]);
        $scenario = $this->createPublishedScenario(
            'builder_start_expression_filter',
            $this->v3StartExpressionRuntimeSchema(
                $channel->id,
                '{{dialog.start_param}} == "123321" and {{contact.phone}} != ""',
                AutoReplyRule::CONTACT_PHONE_CONDITION_HAS_PHONE,
            ),
        );

        $matchingMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $matchingDialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $matchingDialog->external_chat_id,
            'text' => '/start gate',
            'message_parameter' => 'gate',
        ]);
        $otherMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $otherDialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $otherDialog->external_chat_id,
            'text' => '/start gate',
            'message_parameter' => 'gate',
        ]);

        $runtime = app(ScenarioRegistry::class)->makeRuntime($scenario->code);

        $this->assertNotNull($runtime);
        $this->assertTrue($runtime->shouldStart($matchingMessage));
        $this->assertFalse($runtime->shouldStart($otherMessage));
    }

    public function test_published_builder_start_condition_expression_can_read_dialog_phone(): void
    {
        $channel = $this->createTelegramChannel();
        [$contact, $identity, $matchingDialog] = $this->createDialogContext($channel, dialogOverrides: [
            'external_chat_id' => 'start-expression-dialog-phone-match',
            'confirmed_phone_raw' => '+7 926 352 71 11',
            'confirmed_phone_normalized' => '+79263527111',
        ]);
        [, , $otherDialog] = $this->createDialogContext($channel, identityOverrides: [
            'external_user_id' => 'start-expression-dialog-phone-other',
        ], dialogOverrides: [
            'external_chat_id' => 'start-expression-dialog-phone-other',
            'confirmed_phone_raw' => null,
            'confirmed_phone_normalized' => null,
        ]);
        $scenario = $this->createPublishedScenario(
            'builder_start_expression_dialog_phone',
            $this->v3StartExpressionRuntimeSchema($channel->id, '{{dialog.phone}} != ""'),
        );

        $matchingMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $matchingDialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $matchingDialog->external_chat_id,
            'text' => '/start gate',
            'message_parameter' => 'gate',
        ]);
        $otherMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'dialog_id' => $otherDialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $otherDialog->external_chat_id,
            'text' => '/start gate',
            'message_parameter' => 'gate',
        ]);

        $runtime = app(ScenarioRegistry::class)->makeRuntime($scenario->code);

        $this->assertNotNull($runtime);
        $this->assertTrue($runtime->shouldStart($matchingMessage));
        $this->assertFalse($runtime->shouldStart($otherMessage));
    }

    public function test_published_builder_start_condition_broken_expression_is_skipped_without_crash(): void
    {
        Log::spy();

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario(
            'builder_start_expression_broken',
            $this->v3StartExpressionRuntimeSchema($channel->id, '{{dialog.start_param}} = "123321"'),
        );
        $message = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => '/start gate',
            'message_parameter' => 'gate',
        ]);

        $runtime = app(ScenarioRegistry::class)->makeRuntime($scenario->code);

        $this->assertNotNull($runtime);
        $this->assertFalse($runtime->shouldStart($message));
        Log::shouldHaveReceived('warning')
            ->with('V3 start expression condition failed.', Mockery::on(
                fn (array $context): bool => ($context['scenario_id'] ?? null) === $scenario->id
                    && ($context['block_id'] ?? null) === 'start'
                    && ($context['message_id'] ?? null) === $message->id,
            ))
            ->once();
    }

    public function test_published_builder_start_condition_starts_its_target_runtime_block(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 7101]]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $startTag = Tag::factory()->create([
            'name' => 'Secondary start',
        ]);
        $scenario = $this->createPublishedScenario('builder_target_start', [
            'version' => 1,
            'start_block_id' => 'welcome',
            'triggers' => [
                [
                    'type' => 'parameter',
                    'value' => 'primary_start',
                ],
            ],
            'blocks' => [
                'welcome' => [
                    'type' => 'message',
                    'text' => 'Основной старт.',
                    'next' => 'done',
                ],
                'alternate' => [
                    'type' => 'message',
                    'text' => 'Альтернативный старт.',
                    'next' => 'done',
                ],
                'done' => [
                    'type' => 'complete',
                ],
            ],
        ]);

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $primaryBlock = ScenarioBuilderBlock::query()->create([
            'scenario_version_id' => $scenario->publishedVersion?->id,
            'type' => ScenarioBuilderBlock::TYPE_START_CONDITION,
            'title' => 'Основной старт',
            'position_x' => 120,
            'position_y' => 160,
            'settings_payload' => [
                'condition' => [
                    'match' => AutoReplyRule::MATCH_SCOPE_EXACT_PARAMETER,
                ],
                'start_block_id' => 'welcome',
            ],
        ]);
        $primaryBlock->channels()->sync([$channel->id]);
        ScenarioBuilderCondition::query()->create([
            'scenario_builder_block_id' => $primaryBlock->id,
            'type' => ScenarioBuilderCondition::TYPE_MESSAGE_PARAMETER,
            'match_operator' => AutoReplyRule::MATCH_SCOPE_EXACT_PARAMETER,
            'variable' => ScenarioBuilderCondition::VARIABLE_MESSAGE_PARAMETER,
            'value' => 'primary_start',
            'sort_order' => 1,
        ]);
        ScenarioBuilderEdge::query()->create([
            'scenario_version_id' => $scenario->publishedVersion?->id,
            'from_scenario_builder_block_id' => $primaryBlock->id,
            'to_scenario_builder_block_id' => null,
            'to_runtime_block_id' => 'welcome',
            'condition_payload' => [],
            'sort_order' => 1,
        ]);

        $secondaryBlock = ScenarioBuilderBlock::query()->create([
            'scenario_version_id' => $scenario->publishedVersion?->id,
            'type' => ScenarioBuilderBlock::TYPE_START_CONDITION,
            'title' => 'Альтернативный старт',
            'position_x' => 320,
            'position_y' => 160,
            'settings_payload' => [
                'condition' => [
                    'match' => AutoReplyRule::MATCH_SCOPE_EXACT_PARAMETER,
                ],
                'start_block_id' => 'alternate',
            ],
        ]);
        $secondaryBlock->channels()->sync([$channel->id]);
        ScenarioBuilderCondition::query()->create([
            'scenario_builder_block_id' => $secondaryBlock->id,
            'type' => ScenarioBuilderCondition::TYPE_MESSAGE_PARAMETER,
            'match_operator' => AutoReplyRule::MATCH_SCOPE_EXACT_PARAMETER,
            'variable' => ScenarioBuilderCondition::VARIABLE_MESSAGE_PARAMETER,
            'value' => 'secondary_start',
            'sort_order' => 1,
        ]);
        $secondaryRuntimeBlockId = 'builder_start_'.$secondaryBlock->id;
        $publishedVersion = $scenario->publishedVersion()->firstOrFail();
        $schemaPayload = $publishedVersion->schema_payload;
        $schemaPayload['blocks'][$secondaryRuntimeBlockId] = [
            'type' => 'message',
            'text' => 'Ответ второго стартового условия.',
            'text_format' => 'plain_text',
            'next' => 'done',
            'actions' => [
                [
                    'type' => 'set_tag',
                    'value' => $startTag->slug,
                ],
            ],
        ];
        $publishedVersion->forceFill([
            'schema_payload' => $schemaPayload,
        ])->save();
        ScenarioBuilderEdge::query()->create([
            'scenario_version_id' => $scenario->publishedVersion?->id,
            'from_scenario_builder_block_id' => $secondaryBlock->id,
            'to_scenario_builder_block_id' => null,
            'to_runtime_block_id' => $secondaryRuntimeBlockId,
            'condition_payload' => [],
            'sort_order' => 1,
        ]);

        app(ScenarioRegistry::class)->forgetCachedDefinitions();

        $message = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => '/start secondary_start',
            'message_parameter' => 'secondary_start',
        ]);

        (new ProcessScenarioStartJob($message->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();
        $outboundMessage = Message::query()
            ->where('direction', Message::DIRECTION_OUTBOUND)
            ->firstOrFail();

        $this->assertSame(ScenarioRun::STATUS_COMPLETED, $run->status);
        $this->assertSame('Ответ второго стартового условия.', $outboundMessage->text);
        $contact->refresh()->load('tags');
        $this->assertSame([$startTag->slug], $contact->tags->pluck('slug')->all());
    }

    public function test_database_backed_scenario_saves_answers_and_completes_linear_flow(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 7001]])
                ->push(['ok' => true, 'result' => ['message_id' => 7002]])
                ->push(['ok' => true, 'result' => ['message_id' => 7003]]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario('vip_ibiza_apply', $this->linearSchema('vip_ibiza_apply'));

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => '/start vip_ibiza_apply',
            'message_parameter' => 'vip_ibiza_apply',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();

        $firstAnswer = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'Анна',
        ]);

        (new ProcessScenarioInboundJob($firstAnswer->id, $run->id))
            ->handle(app(ScenarioRegistry::class));

        $run->refresh();

        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('ask_budget', $run->current_step);
        $this->assertSame('Анна', data_get($run->state_payload, 'run.first_name'));

        $secondAnswer = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'middle',
        ]);

        (new ProcessScenarioInboundJob($secondAnswer->id, $run->id))
            ->handle(app(ScenarioRegistry::class));

        $run->refresh();
        $outboundMessages = Message::query()
            ->where('direction', Message::DIRECTION_OUTBOUND)
            ->orderBy('id')
            ->get();

        $this->assertSame(ScenarioRun::STATUS_COMPLETED, $run->status);
        $this->assertNull($run->current_step);
        $this->assertSame('completed', $run->exit_outcome);
        $this->assertNotNull($run->finished_at);
        $this->assertSame('Анна', data_get($run->state_payload, 'run.first_name'));
        $this->assertSame('middle', data_get($run->state_payload, 'run.budget_tier'));
        $this->assertCount(3, $outboundMessages);
        $this->assertSame('Какой у вас бюджет?', $outboundMessages[2]->text);
    }

    public function test_database_backed_scenario_suppresses_next_outbound_when_dialog_is_blocked_mid_run(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 7101]])
                ->push(['ok' => true, 'result' => ['message_id' => 7102]]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario('vip_ibiza_apply', $this->linearSchema('vip_ibiza_apply'));

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => '/start vip_ibiza_apply',
            'message_parameter' => 'vip_ibiza_apply',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();

        $dialog->forceFill([
            'bot_subscription_status' => Dialog::BOT_SUBSCRIPTION_STATUS_BLOCKED_BY_USER,
            'bot_subscription_changed_at' => now(),
        ])->save();

        $firstAnswer = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'Анна',
        ]);

        (new ProcessScenarioInboundJob($firstAnswer->id, $run->id))
            ->handle(app(ScenarioRegistry::class));

        $run->refresh();
        $outboundMessages = Message::query()
            ->where('direction', Message::DIRECTION_OUTBOUND)
            ->orderBy('id')
            ->get();

        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('ask_budget', $run->current_step);
        $this->assertSame('Анна', data_get($run->state_payload, 'run.first_name'));
        $this->assertTrue((bool) data_get($run->state_payload, 'run.pending_prompt_delivery'));
        $this->assertCount(2, $outboundMessages);
    }

    public function test_database_backed_scenario_replays_pending_prompt_after_unblock_before_accepting_next_answer(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 7201]])
                ->push(['ok' => true, 'result' => ['message_id' => 7202]])
                ->push(['ok' => true, 'result' => ['message_id' => 7203]]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario('vip_ibiza_apply', $this->linearSchema('vip_ibiza_apply'));

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => '/start vip_ibiza_apply',
            'message_parameter' => 'vip_ibiza_apply',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();

        $dialog->forceFill([
            'bot_subscription_status' => Dialog::BOT_SUBSCRIPTION_STATUS_BLOCKED_BY_USER,
            'bot_subscription_changed_at' => now(),
        ])->save();

        $firstAnswer = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'Анна',
        ]);

        (new ProcessScenarioInboundJob($firstAnswer->id, $run->id))
            ->handle(app(ScenarioRegistry::class));

        $dialog->forceFill([
            'bot_subscription_status' => null,
            'bot_subscription_changed_at' => now()->addSecond(),
        ])->save();

        $resumeMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'middle',
        ]);

        (new ProcessScenarioInboundJob($resumeMessage->id, $run->id))
            ->handle(app(ScenarioRegistry::class));

        $run->refresh();
        $outboundMessages = Message::query()
            ->where('direction', Message::DIRECTION_OUTBOUND)
            ->orderBy('id')
            ->get();

        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('ask_budget', $run->current_step);
        $this->assertSame('Анна', data_get($run->state_payload, 'run.first_name'));
        $this->assertNull(data_get($run->state_payload, 'run.budget_tier'));
        $this->assertFalse((bool) data_get($run->state_payload, 'run.pending_prompt_delivery'));
        $this->assertCount(3, $outboundMessages);
        $this->assertSame('Какой у вас бюджет?', $outboundMessages[2]->text);
    }

    public function test_database_backed_scenario_stores_outbound_against_current_dialog_route_source_for_stale_trigger_message(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 7151]])
                ->push(['ok' => true, 'result' => ['message_id' => 7152]]),
        ]);

        $channel = $this->createTelegramChannel();
        $contact = Contact::factory()->create([
            'is_auto_reply_enabled' => true,
        ]);
        $legacyIdentity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'legacy-user-500',
        ]);
        $currentIdentity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'current-user-500',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $currentIdentity->id,
            'external_chat_id' => 'telegram-chat-799',
            'last_message_at' => now(),
            'last_inbound_at' => now(),
        ]);
        $scenario = $this->createPublishedScenario('vip_ibiza_apply', $this->linearSchema('vip_ibiza_apply'));

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $message = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $legacyIdentity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => 'telegram-chat-700',
            'text' => '/start vip_ibiza_apply',
            'message_parameter' => 'vip_ibiza_apply',
            'raw_payload' => [
                'message' => [
                    'text' => '/start vip_ibiza_apply',
                ],
            ],
        ]);

        (new ProcessScenarioStartJob($message->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $outboundMessages = Message::query()
            ->where('direction', Message::DIRECTION_OUTBOUND)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $outboundMessages);
        $this->assertSame($dialog->id, $outboundMessages[0]->dialog_id);
        $this->assertSame($currentIdentity->id, $outboundMessages[0]->contact_identity_id);
        $this->assertSame('telegram-chat-799', $outboundMessages[0]->external_chat_id);
        $this->assertSame($dialog->id, $outboundMessages[1]->dialog_id);
        $this->assertSame($currentIdentity->id, $outboundMessages[1]->contact_identity_id);
        $this->assertSame('telegram-chat-799', $outboundMessages[1]->external_chat_id);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === 'telegram-chat-799'
            && $request['text'] === 'Добро пожаловать в сценарий.');
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === 'telegram-chat-799'
            && $request['text'] === 'Как вас зовут?');
    }

    public function test_database_backed_scenario_waits_for_contact_share_and_completes_phone_capture_flow(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 7201]])
                ->push(['ok' => true, 'result' => ['message_id' => 7202]])
                ->push(['ok' => true, 'result' => ['message_id' => 7203]]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario('vip_phone_capture', $this->phoneCaptureSchema('vip_phone_capture'));

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => '/start vip_phone_capture',
            'message_parameter' => 'vip_phone_capture',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();
        $outboundMessages = Message::query()
            ->where('direction', Message::DIRECTION_OUTBOUND)
            ->orderBy('id')
            ->get();

        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('capture_phone', $run->current_step);
        $this->assertCount(2, $outboundMessages);
        $this->assertSame('Добро пожаловать в сценарий.', $outboundMessages[0]->text);
        $this->assertSame('Поделитесь номером телефона.', $outboundMessages[1]->text);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === $dialog->external_chat_id
            && $request['text'] === 'Поделитесь номером телефона.'
            && data_get($request->data(), 'reply_markup.keyboard.0.0.request_contact') === true
            && data_get($request->data(), 'reply_markup.keyboard.0.0.text') === 'Поделиться номером телефона');

        $phoneShare = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_CONTACT_SHARE,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'raw_payload' => [
                'message' => [
                    'contact' => [
                        'phone_number' => '+7 999 123 45 67',
                    ],
                ],
            ],
        ]);

        (new ProcessScenarioInboundJob($phoneShare->id, $run->id))
            ->handle(app(ScenarioRegistry::class));

        $run->refresh();
        $outboundMessages = Message::query()
            ->where('direction', Message::DIRECTION_OUTBOUND)
            ->orderBy('id')
            ->get();

        $this->assertSame(ScenarioRun::STATUS_COMPLETED, $run->status);
        $this->assertNull($run->current_step);
        $this->assertSame('completed', $run->exit_outcome);
        $this->assertCount(3, $outboundMessages);
        $this->assertSame('Спасибо, номер получили.', $outboundMessages[2]->text);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === $dialog->external_chat_id
            && $request['text'] === 'Спасибо, номер получили.'
            && data_get($request->data(), 'reply_markup.remove_keyboard') === true);
    }

    public function test_database_backed_scenario_keeps_waiting_when_text_arrives_on_phone_capture_step(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 7301]])
                ->push(['ok' => true, 'result' => ['message_id' => 7302]]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $scenario = $this->createPublishedScenario('vip_phone_capture', $this->phoneCaptureSchema('vip_phone_capture'));

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => '/start vip_phone_capture',
            'message_parameter' => 'vip_phone_capture',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();

        $textReply = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'Не хочу делиться номером',
        ]);

        (new ProcessScenarioInboundJob($textReply->id, $run->id))
            ->handle(app(ScenarioRegistry::class));

        $run->refresh();
        $outboundMessages = Message::query()
            ->where('direction', Message::DIRECTION_OUTBOUND)
            ->orderBy('id')
            ->get();

        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('capture_phone', $run->current_step);
        $this->assertNull($run->exit_outcome);
        $this->assertSame([], $run->state_payload);
        $this->assertCount(2, $outboundMessages);
    }

    public function test_database_backed_scenario_branches_by_condition_and_applies_tag_effects(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 7001]])
                ->push(['ok' => true, 'result' => ['message_id' => 7002]])
                ->push(['ok' => true, 'result' => ['message_id' => 7003]]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $strongTag = Tag::factory()->create([
            'name' => 'VIP strong',
        ]);
        $weakTag = Tag::factory()->create([
            'name' => 'VIP weak',
        ]);

        $contact->tags()->attach($weakTag->id, [
            'assigned_at' => now(),
            'assigned_by_user_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $scenario = $this->createPublishedScenario(
            'vip_ibiza_apply',
            $this->conditionalSchema('vip_ibiza_apply', $strongTag->slug, $weakTag->slug),
        );

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => '/start vip_ibiza_apply',
            'message_parameter' => 'vip_ibiza_apply',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();

        $budgetAnswer = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'high',
        ]);

        (new ProcessScenarioInboundJob($budgetAnswer->id, $run->id))
            ->handle(app(ScenarioRegistry::class));

        $run->refresh();
        $contact->refresh()->load('tags');
        $outboundMessages = Message::query()
            ->where('direction', Message::DIRECTION_OUTBOUND)
            ->orderBy('id')
            ->get();

        $this->assertSame(ScenarioRun::STATUS_COMPLETED, $run->status);
        $this->assertSame('high', data_get($run->state_payload, 'run.budget_tier'));
        $this->assertSame(['vip-strong'], $contact->tags->pluck('slug')->all());
        $this->assertCount(3, $outboundMessages);
        $this->assertSame('Какой у вас бюджет?', $outboundMessages[1]->text);
        $this->assertSame('Отлично, этот формат вам подходит.', $outboundMessages[2]->text);
    }

    public function test_database_backed_scenario_uses_default_condition_branch_when_rule_is_false(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 7001]])
                ->push(['ok' => true, 'result' => ['message_id' => 7002]])
                ->push(['ok' => true, 'result' => ['message_id' => 7003]]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $strongTag = Tag::factory()->create([
            'name' => 'VIP strong',
        ]);
        $weakTag = Tag::factory()->create([
            'name' => 'VIP weak',
        ]);

        $scenario = $this->createPublishedScenario(
            'vip_ibiza_apply',
            $this->conditionalSchema('vip_ibiza_apply', $strongTag->slug, $weakTag->slug),
        );

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => '/start vip_ibiza_apply',
            'message_parameter' => 'vip_ibiza_apply',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();

        $budgetAnswer = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'low',
        ]);

        (new ProcessScenarioInboundJob($budgetAnswer->id, $run->id))
            ->handle(app(ScenarioRegistry::class));

        $run->refresh();
        $contact->refresh()->load('tags');
        $outboundMessages = Message::query()
            ->where('direction', Message::DIRECTION_OUTBOUND)
            ->orderBy('id')
            ->get();

        $this->assertSame(ScenarioRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(['vip-weak'], $contact->tags->pluck('slug')->all());
        $this->assertSame('Спасибо, пока это не ваш уровень бюджета.', $outboundMessages[2]->text);
    }

    public function test_database_backed_scenario_applies_tag_actions_in_declared_order(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 7101]]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $vipTag = Tag::factory()->create([
            'name' => 'VIP status',
        ]);

        $contact->tags()->attach($vipTag->id, [
            'assigned_at' => now(),
            'assigned_by_user_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $scenario = $this->createPublishedScenario('vip_retag', [
            'version' => 1,
            'start_block_id' => 'welcome',
            'triggers' => [
                [
                    'type' => 'parameter',
                    'value' => 'vip_retag',
                ],
            ],
            'blocks' => [
                'welcome' => [
                    'type' => 'message',
                    'text' => 'Переназначаем тег.',
                    'actions' => [
                        [
                            'type' => 'remove_tag',
                            'value' => $vipTag->slug,
                        ],
                        [
                            'type' => 'set_tag',
                            'value' => $vipTag->slug,
                        ],
                    ],
                    'next' => 'done',
                ],
                'done' => [
                    'type' => 'complete',
                ],
            ],
        ]);

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => '/start vip_retag',
            'message_parameter' => 'vip_retag',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $contact->refresh()->load('tags');

        $this->assertSame(['vip-status'], $contact->tags->pluck('slug')->all());
    }

    public function test_ibiza_mvp_strong_branch_requests_phone_then_instagram_and_sets_strong_tag(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 7301],
            ]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $strongTag = Tag::factory()->create([
            'name' => 'vip strong',
        ]);
        $borderlineTag = Tag::factory()->create([
            'name' => 'vip borderline',
        ]);
        $weakTag = Tag::factory()->create([
            'name' => 'vip weak',
        ]);

        $scenario = $this->createPublishedScenario('vip_ibiza', $this->ibizaMvpSchema(
            $strongTag->slug,
            $borderlineTag->slug,
            $weakTag->slug,
        ));

        $this->attachContactTags($contact, $borderlineTag, $weakTag);

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => '/start vip_ibiza_apply',
            'message_parameter' => 'vip_ibiza_apply',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();

        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Герман');
        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Да, готова');
        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Новые знакомства');
        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Полностью');
        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Высокий');
        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Москва');

        $run->refresh();
        $outboundMessages = Message::query()
            ->where('direction', Message::DIRECTION_OUTBOUND)
            ->orderBy('id')
            ->get();

        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('capture_phone_strong', $run->current_step);
        $this->assertSame('Герман', data_get($run->state_payload, 'run.first_name'));
        $this->assertSame('Да, готова', data_get($run->state_payload, 'run.dates_response'));
        $this->assertSame('Высокий', data_get($run->state_payload, 'run.budget_tier'));
        $this->assertCount(8, $outboundMessages);
        $this->assertSame('Поделитесь номером телефона.', $outboundMessages->last()?->text);

        $this->processScenarioPhoneShare($channel, $contact, $identity, $dialog, $run);

        $run->refresh();
        $outboundMessages = Message::query()
            ->where('direction', Message::DIRECTION_OUTBOUND)
            ->orderBy('id')
            ->get();

        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('ask_instagram', $run->current_step);
        $this->assertCount(9, $outboundMessages);
        $this->assertSame('Какой у вас Instagram?', $outboundMessages->last()?->text);

        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, '@german');

        $run->refresh();
        $contact->refresh()->load('tags');
        $outboundMessages = Message::query()
            ->where('direction', Message::DIRECTION_OUTBOUND)
            ->orderBy('id')
            ->get();

        $this->assertSame(ScenarioRun::STATUS_COMPLETED, $run->status);
        $this->assertNull($run->current_step);
        $this->assertSame('completed', $run->exit_outcome);
        $this->assertSame('@german', data_get($run->state_payload, 'run.instagram_handle'));
        $this->assertSame('Герман', $contact->first_name);
        $this->assertSame(Contact::FIRST_NAME_SOURCE_CONTACT_CONFIRMED, $contact->first_name_source);
        $this->assertSame(['vip-strong'], $contact->tags->pluck('slug')->all());
        $this->assertCount(10, $outboundMessages);
        $this->assertSame('Спасибо, вы подходите под VIP-формат.', $outboundMessages->last()?->text);
    }

    public function test_ibiza_mvp_borderline_branch_requests_phone_and_sets_borderline_tag(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 7401],
            ]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $strongTag = Tag::factory()->create([
            'name' => 'vip strong',
        ]);
        $borderlineTag = Tag::factory()->create([
            'name' => 'vip borderline',
        ]);
        $weakTag = Tag::factory()->create([
            'name' => 'vip weak',
        ]);

        $scenario = $this->createPublishedScenario('vip_ibiza', $this->ibizaMvpSchema(
            $strongTag->slug,
            $borderlineTag->slug,
            $weakTag->slug,
        ));

        $this->attachContactTags($contact, $strongTag, $weakTag);

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => '/start vip_ibiza_tg1',
            'message_parameter' => 'vip_ibiza_tg1',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();

        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Анна');
        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Да, готова');
        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Новые впечатления');
        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Частично');
        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Низкий');
        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Санкт-Петербург');

        $run->refresh();
        $outboundMessages = Message::query()
            ->where('direction', Message::DIRECTION_OUTBOUND)
            ->orderBy('id')
            ->get();

        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('capture_phone_borderline', $run->current_step);
        $this->assertCount(8, $outboundMessages);
        $this->assertSame('Поделитесь номером телефона.', $outboundMessages->last()?->text);

        $this->processScenarioPhoneShare($channel, $contact, $identity, $dialog, $run);

        $run->refresh();
        $contact->refresh()->load('tags');
        $outboundMessages = Message::query()
            ->where('direction', Message::DIRECTION_OUTBOUND)
            ->orderBy('id')
            ->get();

        $this->assertSame(ScenarioRun::STATUS_COMPLETED, $run->status);
        $this->assertNull($run->current_step);
        $this->assertSame('completed', $run->exit_outcome);
        $this->assertSame('Анна', $contact->first_name);
        $this->assertSame(Contact::FIRST_NAME_SOURCE_CONTACT_CONFIRMED, $contact->first_name_source);
        $this->assertSame(['vip-borderline'], $contact->tags->pluck('slug')->all());
        $this->assertCount(9, $outboundMessages);
        $this->assertSame('Спасибо, посмотрим формат полегче.', $outboundMessages->last()?->text);
    }

    public function test_ibiza_mvp_weak_branch_completes_without_phone_and_sets_weak_tag(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 7501],
            ]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $strongTag = Tag::factory()->create([
            'name' => 'vip strong',
        ]);
        $borderlineTag = Tag::factory()->create([
            'name' => 'vip borderline',
        ]);
        $weakTag = Tag::factory()->create([
            'name' => 'vip weak',
        ]);

        $scenario = $this->createPublishedScenario('vip_ibiza', $this->ibizaMvpSchema(
            $strongTag->slug,
            $borderlineTag->slug,
            $weakTag->slug,
        ));

        $this->attachContactTags($contact, $strongTag, $borderlineTag);

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => '/start vip_ibiza_inst1',
            'message_parameter' => 'vip_ibiza_inst1',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();

        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Мария');
        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Пока нет');
        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Посмотреть формат');
        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'exploring');
        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Средний');
        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Казань');

        $run->refresh();
        $contact->refresh()->load('tags');
        $outboundMessages = Message::query()
            ->where('direction', Message::DIRECTION_OUTBOUND)
            ->orderBy('id')
            ->get();

        $this->assertSame(ScenarioRun::STATUS_COMPLETED, $run->status);
        $this->assertNull($run->current_step);
        $this->assertSame('completed', $run->exit_outcome);
        $this->assertSame('Мария', $contact->first_name);
        $this->assertSame(Contact::FIRST_NAME_SOURCE_CONTACT_CONFIRMED, $contact->first_name_source);
        $this->assertSame(['vip-weak'], $contact->tags->pluck('slug')->all());
        $this->assertCount(8, $outboundMessages);
        $this->assertSame('Спасибо! Пока предложим более мягкий формат участия.', $outboundMessages->last()?->text);
        $this->assertNotContains('Поделитесь номером телефона.', $outboundMessages->pluck('text')->all());
    }

    public function test_ibiza_mvp_skips_name_question_when_contact_first_name_is_already_confirmed(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 7554],
            ]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel, contactOverrides: [
            'first_name' => 'Герман',
            'first_name_source' => Contact::FIRST_NAME_SOURCE_CONTACT_CONFIRMED,
        ]);
        $strongTag = Tag::factory()->create([
            'name' => 'vip strong',
        ]);
        $borderlineTag = Tag::factory()->create([
            'name' => 'vip borderline',
        ]);
        $weakTag = Tag::factory()->create([
            'name' => 'vip weak',
        ]);

        $scenario = $this->createPublishedScenario('vip_ibiza', $this->ibizaMvpSchema(
            $strongTag->slug,
            $borderlineTag->slug,
            $weakTag->slug,
        ));

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => '/start vip_ibiza_apply',
            'message_parameter' => 'vip_ibiza_apply',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();
        $outboundMessages = Message::query()
            ->where('direction', Message::DIRECTION_OUTBOUND)
            ->where('dialog_id', $dialog->id)
            ->orderBy('id')
            ->get();

        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('ask_dates', $run->current_step);
        $this->assertSame([], $run->state_payload);
        $this->assertCount(2, $outboundMessages);
        $this->assertSame('Добро пожаловать', $outboundMessages[0]->text);
        $this->assertStringStartsWith('Готовы ли вы участвовать', (string) $outboundMessages[1]->text);
        $this->assertNotContains('Как вас зовут?', $outboundMessages->pluck('text')->all());
    }

    public function test_ibiza_mvp_skips_name_question_when_contact_first_name_is_manual(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 7555],
            ]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel, contactOverrides: [
            'first_name' => 'Герман',
            'first_name_source' => Contact::FIRST_NAME_SOURCE_MANUAL,
        ]);
        $strongTag = Tag::factory()->create([
            'name' => 'vip strong',
        ]);
        $borderlineTag = Tag::factory()->create([
            'name' => 'vip borderline',
        ]);
        $weakTag = Tag::factory()->create([
            'name' => 'vip weak',
        ]);

        $scenario = $this->createPublishedScenario('vip_ibiza', $this->ibizaMvpSchema(
            $strongTag->slug,
            $borderlineTag->slug,
            $weakTag->slug,
        ));

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => '/start vip_ibiza_apply',
            'message_parameter' => 'vip_ibiza_apply',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();
        $outboundMessages = Message::query()
            ->where('direction', Message::DIRECTION_OUTBOUND)
            ->where('dialog_id', $dialog->id)
            ->orderBy('id')
            ->get();

        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('ask_dates', $run->current_step);
        $this->assertSame([], $run->state_payload);
        $this->assertCount(2, $outboundMessages);
        $this->assertSame('Добро пожаловать', $outboundMessages[0]->text);
        $this->assertStringStartsWith('Готовы ли вы участвовать', (string) $outboundMessages[1]->text);
        $this->assertNotContains('Как вас зовут?', $outboundMessages->pluck('text')->all());
    }

    public function test_ibiza_mvp_does_not_skip_name_question_for_auto_unknown_or_empty_manual_name(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 7556],
            ]),
        ]);

        $strongTag = Tag::factory()->create([
            'name' => 'vip strong',
        ]);
        $borderlineTag = Tag::factory()->create([
            'name' => 'vip borderline',
        ]);
        $weakTag = Tag::factory()->create([
            'name' => 'vip weak',
        ]);

        $scenario = $this->createPublishedScenario('vip_ibiza', $this->ibizaMvpSchema(
            $strongTag->slug,
            $borderlineTag->slug,
            $weakTag->slug,
        ));

        foreach ([
            [
                'first_name' => 'Уже есть',
                'first_name_source' => Contact::FIRST_NAME_SOURCE_AUTO,
            ],
            [
                'first_name' => 'Уже есть',
                'first_name_source' => null,
            ],
            [
                'first_name' => null,
                'first_name_source' => Contact::FIRST_NAME_SOURCE_MANUAL,
            ],
        ] as $contactOverrides) {
            $channel = $this->createTelegramChannel();
            [$contact, $identity, $dialog] = $this->createDialogContext($channel, contactOverrides: $contactOverrides);

            ScenarioChannelBinding::query()->create([
                'channel_id' => $channel->id,
                'scenario_code' => $scenario->code,
                'is_active' => true,
            ]);

            $startMessage = Message::factory()->create([
                'contact_id' => $contact->id,
                'contact_identity_id' => $identity->id,
                'channel_id' => $channel->id,
                'dialog_id' => $dialog->id,
                'direction' => Message::DIRECTION_INBOUND,
                'message_kind' => Message::KIND_INBOUND_USER,
                'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
                'external_chat_id' => $dialog->external_chat_id,
                'text' => '/start vip_ibiza_inst1',
                'message_parameter' => 'vip_ibiza_inst1',
            ]);

            (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
                ->handle(app(ScenarioRegistry::class));

            $run = ScenarioRun::query()
                ->where('scenario_code', $scenario->code)
                ->where('dialog_id', $dialog->id)
                ->firstOrFail();
            $outboundMessages = Message::query()
                ->where('direction', Message::DIRECTION_OUTBOUND)
                ->where('dialog_id', $dialog->id)
                ->orderBy('id')
                ->get();

            $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
            $this->assertSame('ask_name', $run->current_step);
            $this->assertSame('Как вас зовут?', $outboundMessages->last()?->text);
        }
    }

    public function test_ibiza_mvp_skips_phone_capture_when_contact_already_has_phone_number(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 7557],
            ]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $contact->phoneNumbers()->create([
            'phone_raw' => '+7 926 352 71 11',
            'phone_normalized' => '+79263527111',
            'source' => 'manual',
            'is_primary' => true,
        ]);

        $strongTag = Tag::factory()->create([
            'name' => 'vip strong',
        ]);
        $borderlineTag = Tag::factory()->create([
            'name' => 'vip borderline',
        ]);
        $weakTag = Tag::factory()->create([
            'name' => 'vip weak',
        ]);

        $scenario = $this->createPublishedScenario('vip_ibiza', $this->ibizaMvpSchema(
            $strongTag->slug,
            $borderlineTag->slug,
            $weakTag->slug,
        ));

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => '/start vip_ibiza_apply',
            'message_parameter' => 'vip_ibiza_apply',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();

        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Герман');
        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Да, готова');
        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Новые знакомства');
        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Полностью');
        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Высокий');
        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Москва');

        $run->refresh();
        $outboundMessages = Message::query()
            ->where('direction', Message::DIRECTION_OUTBOUND)
            ->where('dialog_id', $dialog->id)
            ->orderBy('id')
            ->get();

        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('ask_instagram', $run->current_step);
        $this->assertCount(8, $outboundMessages);
        $this->assertSame('Какой у вас Instagram?', $outboundMessages->last()?->text);
        $this->assertNotContains('Поделитесь номером телефона.', $outboundMessages->pluck('text')->all());
    }

    public function test_ibiza_mvp_does_not_skip_phone_capture_when_only_dialog_has_confirmed_phone(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 7558],
            ]),
        ]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel, dialogOverrides: [
            'confirmed_phone_raw' => '+7 926 352 71 11',
            'confirmed_phone_normalized' => '+79263527111',
        ]);

        $strongTag = Tag::factory()->create([
            'name' => 'vip strong',
        ]);
        $borderlineTag = Tag::factory()->create([
            'name' => 'vip borderline',
        ]);
        $weakTag = Tag::factory()->create([
            'name' => 'vip weak',
        ]);

        $scenario = $this->createPublishedScenario('vip_ibiza', $this->ibizaMvpSchema(
            $strongTag->slug,
            $borderlineTag->slug,
            $weakTag->slug,
        ));

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => '/start vip_ibiza_tg1',
            'message_parameter' => 'vip_ibiza_tg1',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();

        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Анна');
        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Да, готова');
        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Новые впечатления');
        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Частично');
        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Низкий');
        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Санкт-Петербург');

        $run->refresh();
        $outboundMessages = Message::query()
            ->where('direction', Message::DIRECTION_OUTBOUND)
            ->where('dialog_id', $dialog->id)
            ->orderBy('id')
            ->get();

        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('capture_phone_borderline', $run->current_step);
        $this->assertCount(8, $outboundMessages);
        $this->assertSame('Поделитесь номером телефона.', $outboundMessages->last()?->text);
    }

    public function test_ibiza_mvp_completion_dispatches_gender_job_and_bitrix_queue_when_first_name_changes(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 7601],
            ]),
        ]);

        Queue::fake([InferContactGenderFromFirstNameJob::class]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel, identityOverrides: [
            'display_name' => 'Telegram Клиент',
        ]);

        $queueBitrix24ContactSyncAction = Mockery::mock(QueueBitrix24ContactSyncAction::class);
        $queueBitrix24ContactSyncAction
            ->shouldReceive('handle')
            ->once()
            ->with(Mockery::on(fn ($value): bool => $value instanceof Contact && $value->is($contact)))
            ->andReturn($this->bitrix24ContactSyncQueued($contact));
        app()->instance(QueueBitrix24ContactSyncAction::class, $queueBitrix24ContactSyncAction);

        $strongTag = Tag::factory()->create([
            'name' => 'vip strong',
        ]);
        $borderlineTag = Tag::factory()->create([
            'name' => 'vip borderline',
        ]);
        $weakTag = Tag::factory()->create([
            'name' => 'vip weak',
        ]);

        $scenario = $this->createPublishedScenario('vip_ibiza', $this->ibizaMvpSchema(
            $strongTag->slug,
            $borderlineTag->slug,
            $weakTag->slug,
        ));

        $this->attachContactTags($contact, $strongTag, $borderlineTag);

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => '/start vip_ibiza_inst1',
            'message_parameter' => 'vip_ibiza_inst1',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();

        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Мария');
        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Пока нет');
        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Посмотреть формат');
        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'exploring');
        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Средний');
        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Казань');

        $contact->refresh();

        $this->assertSame('Мария', $contact->first_name);
        $this->assertSame(Contact::FIRST_NAME_SOURCE_CONTACT_CONFIRMED, $contact->first_name_source);

        Queue::assertPushed(InferContactGenderFromFirstNameJob::class, function (InferContactGenderFromFirstNameJob $job) use ($contact): bool {
            return $job->contactId === $contact->id
                && $job->expectedFirstName === 'Мария';
        });
    }

    public function test_ibiza_mvp_upgrades_existing_auto_first_name_to_contact_confirmed_and_dispatches_side_effects(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 7551],
            ]),
        ]);

        Queue::fake([InferContactGenderFromFirstNameJob::class]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $identity->forceFill([
            'display_name' => 'Telegram Клиент',
        ])->save();
        $contact->forceFill([
            'first_name' => 'Assistant',
            'first_name_source' => Contact::FIRST_NAME_SOURCE_AUTO,
        ])->save();

        $queueBitrix24ContactSyncAction = Mockery::mock(QueueBitrix24ContactSyncAction::class);
        $queueBitrix24ContactSyncAction
            ->shouldReceive('handle')
            ->once()
            ->with(Mockery::on(fn ($value): bool => $value instanceof Contact && $value->is($contact)))
            ->andReturn($this->bitrix24ContactSyncQueued($contact));
        app()->instance(QueueBitrix24ContactSyncAction::class, $queueBitrix24ContactSyncAction);

        $strongTag = Tag::factory()->create([
            'name' => 'vip strong',
        ]);
        $borderlineTag = Tag::factory()->create([
            'name' => 'vip borderline',
        ]);
        $weakTag = Tag::factory()->create([
            'name' => 'vip weak',
        ]);

        $scenario = $this->createPublishedScenario('vip_ibiza', $this->ibizaMvpSchema(
            $strongTag->slug,
            $borderlineTag->slug,
            $weakTag->slug,
        ));

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => '/start vip_ibiza_inst1',
            'message_parameter' => 'vip_ibiza_inst1',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();

        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Юля');
        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Пока нет');
        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Посмотреть формат');
        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Частично');
        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Средний');
        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Казань');

        $run->refresh();
        $contact->refresh();

        $this->assertSame(ScenarioRun::STATUS_COMPLETED, $run->status);
        $this->assertSame('Юля', data_get($run->state_payload, 'run.first_name'));
        $this->assertSame('Юля', $contact->first_name);
        $this->assertSame(Contact::FIRST_NAME_SOURCE_CONTACT_CONFIRMED, $contact->first_name_source);

        Queue::assertPushed(InferContactGenderFromFirstNameJob::class, function (InferContactGenderFromFirstNameJob $job) use ($contact): bool {
            return $job->contactId === $contact->id
                && $job->expectedFirstName === 'Юля';
        });
    }

    public function test_ibiza_mvp_upgrades_unknown_first_name_source_to_contact_confirmed_even_when_value_matches(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 7552],
            ]),
        ]);

        Queue::fake([InferContactGenderFromFirstNameJob::class]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel);
        $identity->forceFill([
            'display_name' => 'Telegram Клиент',
        ])->save();
        $contact->forceFill([
            'first_name' => 'Юля',
            'first_name_source' => null,
        ])->save();

        $queueBitrix24ContactSyncAction = Mockery::mock(QueueBitrix24ContactSyncAction::class);
        $queueBitrix24ContactSyncAction
            ->shouldReceive('handle')
            ->once()
            ->with(Mockery::on(fn ($value): bool => $value instanceof Contact && $value->is($contact)))
            ->andReturn($this->bitrix24ContactSyncQueued($contact));
        app()->instance(QueueBitrix24ContactSyncAction::class, $queueBitrix24ContactSyncAction);

        $strongTag = Tag::factory()->create([
            'name' => 'vip strong',
        ]);
        $borderlineTag = Tag::factory()->create([
            'name' => 'vip borderline',
        ]);
        $weakTag = Tag::factory()->create([
            'name' => 'vip weak',
        ]);

        $scenario = $this->createPublishedScenario('vip_ibiza', $this->ibizaMvpSchema(
            $strongTag->slug,
            $borderlineTag->slug,
            $weakTag->slug,
        ));

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => '/start vip_ibiza_inst1',
            'message_parameter' => 'vip_ibiza_inst1',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();

        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Юля');
        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Пока нет');
        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Посмотреть формат');
        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Частично');
        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Средний');
        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Казань');

        $run->refresh();
        $contact->refresh();

        $this->assertSame(ScenarioRun::STATUS_COMPLETED, $run->status);
        $this->assertSame('Юля', data_get($run->state_payload, 'run.first_name'));
        $this->assertSame('Юля', $contact->first_name);
        $this->assertSame(Contact::FIRST_NAME_SOURCE_CONTACT_CONFIRMED, $contact->first_name_source);

        Queue::assertPushed(InferContactGenderFromFirstNameJob::class, function (InferContactGenderFromFirstNameJob $job) use ($contact): bool {
            return $job->contactId === $contact->id
                && $job->expectedFirstName === 'Юля';
        });
    }

    public function test_ibiza_mvp_does_not_overwrite_existing_confirmed_contact_first_name_or_dispatch_side_effects(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 7553],
            ]),
        ]);

        Queue::fake([InferContactGenderFromFirstNameJob::class]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel, contactOverrides: [
            'first_name' => 'Алина',
            'first_name_source' => Contact::FIRST_NAME_SOURCE_CONTACT_CONFIRMED,
        ]);
        $identity->forceFill([
            'display_name' => 'Telegram Клиент',
        ])->save();

        $queueBitrix24ContactSyncAction = Mockery::mock(QueueBitrix24ContactSyncAction::class);
        $queueBitrix24ContactSyncAction->shouldReceive('handle')->never();
        app()->instance(QueueBitrix24ContactSyncAction::class, $queueBitrix24ContactSyncAction);

        $strongTag = Tag::factory()->create([
            'name' => 'vip strong',
        ]);
        $borderlineTag = Tag::factory()->create([
            'name' => 'vip borderline',
        ]);
        $weakTag = Tag::factory()->create([
            'name' => 'vip weak',
        ]);

        $scenario = $this->createPublishedScenario('vip_ibiza', $this->ibizaMvpSchema(
            $strongTag->slug,
            $borderlineTag->slug,
            $weakTag->slug,
        ));

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => '/start vip_ibiza_inst1',
            'message_parameter' => 'vip_ibiza_inst1',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();

        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Юля');
        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Пока нет');
        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Посмотреть формат');
        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Частично');
        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Средний');
        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Казань');

        $run->refresh();
        $contact->refresh();

        $this->assertSame(ScenarioRun::STATUS_COMPLETED, $run->status);
        $this->assertNull(data_get($run->state_payload, 'run.first_name'));
        $this->assertSame('Алина', $contact->first_name);
        $this->assertSame(Contact::FIRST_NAME_SOURCE_CONTACT_CONFIRMED, $contact->first_name_source);

        Queue::assertNotPushed(InferContactGenderFromFirstNameJob::class);
    }

    public function test_ibiza_mvp_skips_manual_name_question_and_completes_without_name_side_effects(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 7602],
            ]),
        ]);

        Queue::fake([InferContactGenderFromFirstNameJob::class]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext($channel, contactOverrides: [
            'first_name' => 'Старое имя',
            'first_name_source' => Contact::FIRST_NAME_SOURCE_MANUAL,
        ]);

        $queueBitrix24ContactSyncAction = Mockery::mock(QueueBitrix24ContactSyncAction::class);
        $queueBitrix24ContactSyncAction->shouldReceive('handle')->never();
        app()->instance(QueueBitrix24ContactSyncAction::class, $queueBitrix24ContactSyncAction);

        $strongTag = Tag::factory()->create([
            'name' => 'vip strong',
        ]);
        $borderlineTag = Tag::factory()->create([
            'name' => 'vip borderline',
        ]);
        $weakTag = Tag::factory()->create([
            'name' => 'vip weak',
        ]);

        $scenario = $this->createPublishedScenario('vip_ibiza', $this->ibizaMvpSchema(
            $strongTag->slug,
            $borderlineTag->slug,
            $weakTag->slug,
        ));

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => '/start vip_ibiza_inst1',
            'message_parameter' => 'vip_ibiza_inst1',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();
        $outboundMessages = Message::query()
            ->where('direction', Message::DIRECTION_OUTBOUND)
            ->where('dialog_id', $dialog->id)
            ->orderBy('id')
            ->get();

        $this->assertSame(ScenarioRun::STATUS_ACTIVE, $run->status);
        $this->assertSame('ask_dates', $run->current_step);
        $this->assertNotContains('Как вас зовут?', $outboundMessages->pluck('text')->all());

        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Пока нет');
        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Посмотреть формат');
        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Частично');
        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Средний');
        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Казань');

        $run->refresh();
        $contact->refresh();

        $this->assertSame(ScenarioRun::STATUS_COMPLETED, $run->status);
        $this->assertNull(data_get($run->state_payload, 'run.first_name'));
        $this->assertSame('Старое имя', $contact->first_name);
        $this->assertSame(Contact::FIRST_NAME_SOURCE_MANUAL, $contact->first_name_source);

        Queue::assertNotPushed(InferContactGenderFromFirstNameJob::class);
    }

    public function test_ibiza_mvp_uses_identity_display_name_as_extractor_context_and_skips_retry_result(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 7603],
            ]),
        ]);

        Queue::fake([InferContactGenderFromFirstNameJob::class]);

        $channel = $this->createTelegramChannel();
        [$contact, $identity, $dialog] = $this->createDialogContext(
            $channel,
            contactOverrides: [
                'name' => 'Legacy target name',
            ],
            identityOverrides: [
                'display_name' => 'Telegram Клиент',
            ],
        );

        $queueBitrix24ContactSyncAction = Mockery::mock(QueueBitrix24ContactSyncAction::class);
        $queueBitrix24ContactSyncAction->shouldReceive('handle')->never();
        app()->instance(QueueBitrix24ContactSyncAction::class, $queueBitrix24ContactSyncAction);

        $extractFirstNameAction = Mockery::mock(ExtractFirstNameAction::class);
        $extractFirstNameAction
            ->shouldReceive('handle')
            ->once()
            ->with('Это секрет', 'Telegram Клиент')
            ->andReturn([
                'decision' => ExtractFirstNameAction::DECISION_RETRY,
                'first_name' => null,
            ]);
        app()->instance(ExtractFirstNameAction::class, $extractFirstNameAction);
        $strongTag = Tag::factory()->create([
            'name' => 'vip strong',
        ]);
        $borderlineTag = Tag::factory()->create([
            'name' => 'vip borderline',
        ]);
        $weakTag = Tag::factory()->create([
            'name' => 'vip weak',
        ]);

        $scenario = $this->createPublishedScenario('vip_ibiza', $this->ibizaMvpSchema(
            $strongTag->slug,
            $borderlineTag->slug,
            $weakTag->slug,
        ));

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $startMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => '/start vip_ibiza_inst1',
            'message_parameter' => 'vip_ibiza_inst1',
        ]);

        (new ProcessScenarioStartJob($startMessage->id, $dialog->id, $scenario->code))
            ->handle(app(ScenarioRegistry::class));

        $run = ScenarioRun::query()->where('scenario_code', $scenario->code)->firstOrFail();

        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Это секрет');
        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Пока нет');
        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Посмотреть формат');
        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Частично');
        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Средний');
        $this->processScenarioTextReply($channel, $contact, $identity, $dialog, $run, 'Казань');

        $run->refresh();
        $contact->refresh();

        $this->assertSame(ScenarioRun::STATUS_COMPLETED, $run->status);
        $this->assertSame('Это секрет', data_get($run->state_payload, 'run.first_name'));
        $this->assertNull($contact->first_name);
        $this->assertNull($contact->first_name_source);

        Queue::assertNotPushed(InferContactGenderFromFirstNameJob::class);
    }

    private function attachContactTags(Contact $contact, Tag ...$tags): void
    {
        foreach ($tags as $tag) {
            $contact->tags()->attach($tag->id, [
                'assigned_at' => now(),
                'assigned_by_user_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function createTelegramChannel(): Channel
    {
        return Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
            ],
            'bot_token_present' => true,
        ]);
    }

    private function createMaxChannel(): Channel
    {
        return Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
            ],
            'bot_token_present' => true,
        ]);
    }

    /**
     * @return array{0: Contact, 1: ContactIdentity, 2: Dialog}
     */
    private function createDialogContext(
        Channel $channel,
        array $contactOverrides = [],
        array $identityOverrides = [],
        array $dialogOverrides = [],
    ): array {
        $contact = Contact::factory()->create(array_merge([
            'is_auto_reply_enabled' => true,
        ], $contactOverrides));

        $identity = ContactIdentity::factory()->create(array_merge([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'telegram-user-500',
        ], $identityOverrides));

        $dialog = Dialog::factory()->create(array_merge([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'telegram-chat-700',
        ], $dialogOverrides));

        return [$contact, $identity, $dialog];
    }

    /**
     * @param  array<string, mixed>  $rawPayload
     */
    private function dispatchStoredInboundText(
        Channel $channel,
        Contact $contact,
        ContactIdentity $identity,
        Dialog $dialog,
        string $text,
        array $rawPayload = [],
    ): Message {
        $message = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => $text,
            'raw_payload' => $rawPayload,
        ]);

        app(DispatchStoredInboundBotMessageAction::class)->handle(
            $channel,
            new StoredInboundMessageResult($message->fresh(['contact', 'channel', 'dialog'])),
        );

        return $message;
    }

    /**
     * @param  array<string, mixed>  $schemaPayload
     */
    private function createPublishedScenario(string $code, array $schemaPayload): Scenario
    {
        $scenario = Scenario::query()->create([
            'code' => $code,
            'name' => 'VIP Ibiza',
            'is_active' => true,
            'is_archived' => false,
        ]);

        ScenarioVersion::query()->create([
            'scenario_id' => $scenario->id,
            'version_number' => 1,
            'status' => ScenarioVersion::STATUS_PUBLISHED,
            'schema_payload' => $schemaPayload,
        ]);

        return $scenario->fresh('publishedVersion');
    }

    private function v3StartExpressionRuntimeSchema(
        int $channelId,
        string $expression,
        string $contactPhoneCondition = '',
    ): array {
        return [
            'version' => 3,
            'builder_v3_runtime' => [
                'schema_version' => 3,
                'source_revision' => 'v3:test',
                'compiled_at' => now()->toISOString(),
                'entrypoints' => [
                    [
                        'block_id' => 'start',
                        'channel_ids' => [$channelId],
                        'match' => AutoReplyRule::MATCH_SCOPE_EXACT_PARAMETER,
                        'values' => ['gate'],
                        'contact_phone_condition' => $contactPhoneCondition,
                        'dialog_phone_condition' => '',
                        'expression' => $expression,
                        'priority' => 10,
                    ],
                ],
                'blocks' => [
                    'start' => [
                        'id' => 'start',
                        'db_id' => 1,
                        'kind' => 'state',
                        'title' => 'Старт',
                        'message' => [
                            'text' => 'Старт сработал',
                            'text_format' => 'plain_text',
                        ],
                        'buttons' => ['placement' => 'auto', 'rows' => []],
                        'actions' => [],
                        'wait_reply_edges' => [],
                        'automatic_edges' => [],
                        'action_result_edges' => [],
                    ],
                ],
                'edges' => [],
            ],
        ];
    }

    private function successfulBotSendResult(Dialog $dialog, string $text, string $externalMessageId): BotDialogTextSendResult
    {
        return new BotDialogTextSendResult(
            routeStatus: new DialogRouteStatusData(
                code: DialogRouteStatusData::CODE_READY,
                label: 'Готов',
                tone: 'success',
                isSendable: true,
                blockedReason: null,
            ),
            dialog: $dialog->fresh(['channel', 'currentContactIdentity']),
            deliveryResult: new AutoReplyDeliveryResult(
                text: $text,
                externalMessageId: $externalMessageId,
                rawPayload: ['ok' => true],
            ),
        );
    }

    private function blockedBotSendResult(Dialog $dialog): BotDialogTextSendResult
    {
        return new BotDialogTextSendResult(
            routeStatus: new DialogRouteStatusData(
                code: DialogRouteStatusData::CODE_MISSING_ROUTE_SOURCE,
                label: 'Маршрут недоступен',
                tone: 'warning',
                isSendable: false,
                blockedReason: 'Тестовая ошибка доставки.',
            ),
            dialog: $dialog->fresh(['channel', 'currentContactIdentity']),
            deliveryResult: null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function v3CatalogRuntimeSchema(int $channelId, string $placement = 'auto'): array
    {
        return [
            'version' => 3,
            'builder_v3_runtime' => [
                'schema_version' => 3,
                'source_revision' => 'v3:test',
                'compiled_at' => now()->toISOString(),
                'entrypoints' => [[
                    'block_id' => 'start',
                    'channel_ids' => [$channelId],
                    'match' => 'strict',
                    'values' => ['старт'],
                    'priority' => 10,
                ]],
                'blocks' => [
                    'start' => [
                        'id' => 'start',
                        'db_id' => 1,
                        'title' => 'Старт',
                        'message' => [
                            'text' => 'Выберите действие',
                            'text_format' => Message::TEXT_FORMAT_PLAIN_TEXT,
                        ],
                        'buttons' => [
                            'placement' => $placement,
                            'rows' => [[
                                [
                                    'id' => 'btn_catalog',
                                    'text' => 'Получить каталог',
                                    'normalized_text' => 'получить каталог',
                                    'output_id' => 'btn_catalog',
                                    'target_block_id' => 'catalog',
                                ],
                            ]],
                        ],
                        'default_target_block_id' => null,
                    ],
                    'catalog' => [
                        'id' => 'catalog',
                        'db_id' => 2,
                        'title' => 'Каталог',
                        'message' => [
                            'text' => 'Вот каталог',
                            'text_format' => Message::TEXT_FORMAT_PLAIN_TEXT,
                        ],
                        'buttons' => null,
                        'default_target_block_id' => null,
                    ],
                ],
                'edges' => [[
                    'id' => 'edge_1',
                    'source_block_id' => 'start',
                    'target_block_id' => 'catalog',
                    'from_output_id' => 'btn_catalog',
                    'label' => 'Получить каталог',
                ]],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function v3CheckDataNameRuntimeSchema(int $channelId): array
    {
        $waitEdge = $this->v3WaitReplyEdge('10', 'edge_to_check', 'check_name');
        $foundEdge = [
            'id' => '20',
            'edge_key' => 'edge_data_found',
            'mode' => 'automatic',
            'priority' => 10,
            'transition_limit' => 0,
            'source_block_id' => 'check_name',
            'target_block_id' => 'accepted',
            'from_output_id' => 'data_found',
            'label' => 'Найдено',
            'match' => [
                'type' => 'any_inbound',
                'text' => '',
                'variants' => [],
            ],
            'input_capture' => [
                'enabled' => false,
                'field_scope' => 'dialog',
                'field_key' => '',
                'data_type' => 'any_text',
            ],
        ];
        $manualRequiredEdge = [
            'id' => '25',
            'edge_key' => 'edge_data_manual_required',
            'mode' => 'action_result',
            'priority' => 10,
            'transition_limit' => 0,
            'source_block_id' => 'check_name',
            'target_block_id' => 'manual_required',
            'from_output_id' => 'data_manual_required',
            'label' => 'Требует уточнения',
            'match' => [
                'type' => 'any_inbound',
                'text' => '',
                'variants' => [],
            ],
            'input_capture' => [
                'enabled' => false,
                'field_scope' => 'dialog',
                'field_key' => '',
                'data_type' => 'any_text',
            ],
        ];
        $notFoundEdge = [
            'id' => '30',
            'edge_key' => 'edge_data_not_found',
            'mode' => 'action_result',
            'priority' => 10,
            'transition_limit' => 0,
            'source_block_id' => 'check_name',
            'target_block_id' => 'retry',
            'from_output_id' => 'data_not_found',
            'label' => 'Не найдено',
            'match' => [
                'type' => 'any_inbound',
                'text' => '',
                'variants' => [],
            ],
            'input_capture' => [
                'enabled' => false,
                'field_scope' => 'dialog',
                'field_key' => '',
                'data_type' => 'any_text',
            ],
        ];

        return [
            'version' => 3,
            'builder_v3_runtime' => [
                'schema_version' => 3,
                'source_revision' => 'v3:test',
                'compiled_at' => now()->toISOString(),
                'entrypoints' => [[
                    'block_id' => 'ask_name',
                    'channel_ids' => [$channelId],
                    'match' => 'strict',
                    'values' => ['старт'],
                    'priority' => 10,
                ]],
                'blocks' => [
                    'ask_name' => [
                        'id' => 'ask_name',
                        'db_id' => 1,
                        'kind' => 'state',
                        'title' => 'Спрашиваем имя',
                        'message' => [
                            'text' => 'Как вас зовут?',
                            'text_format' => Message::TEXT_FORMAT_PLAIN_TEXT,
                        ],
                        'buttons' => null,
                        'wait_reply_edges' => [$waitEdge],
                        'automatic_edges' => [],
                        'default_target_block_id' => null,
                    ],
                    'check_name' => [
                        'id' => 'check_name',
                        'db_id' => 2,
                        'kind' => 'state',
                        'title' => 'Проверяем имя',
                        'actions' => [
                            [
                                'type' => 'check_data',
                                'source_type' => 'inbound_message',
                                'check_source' => 'current_inbound_message',
                                'dictionary_key' => 'names',
                                'lookup_field' => 'lookup_value',
                                'result_field' => 'result_value',
                                'target_variable_key' => 'first_name',
                            ],
                        ],
                        'action_result_edges' => [$foundEdge, $manualRequiredEdge, $notFoundEdge],
                        'message' => null,
                        'buttons' => null,
                        'wait_reply_edges' => [],
                        'automatic_edges' => [],
                        'default_target_block_id' => null,
                    ],
                    'accepted' => [
                        'id' => 'accepted',
                        'db_id' => 3,
                        'kind' => 'state',
                        'title' => 'Имя принято',
                        'actions' => [
                            [
                                'type' => 'write_contact_field',
                                'source_type' => 'ai_data',
                                'source_block_id' => 'legacy_ai_block',
                                'source_field_key' => 'first_name',
                                'target_scope' => 'contact',
                                'target_field' => 'first_name',
                            ],
                        ],
                        'message' => [
                            'text' => 'Имя принято',
                            'text_format' => Message::TEXT_FORMAT_PLAIN_TEXT,
                        ],
                        'buttons' => null,
                        'wait_reply_edges' => [],
                        'automatic_edges' => [],
                        'default_target_block_id' => null,
                    ],
                    'retry' => [
                        'id' => 'retry',
                        'db_id' => 4,
                        'kind' => 'state',
                        'title' => 'Повторить вопрос',
                        'message' => [
                            'text' => 'Не нашли',
                            'text_format' => Message::TEXT_FORMAT_PLAIN_TEXT,
                        ],
                        'buttons' => null,
                        'wait_reply_edges' => [],
                        'automatic_edges' => [],
                        'default_target_block_id' => null,
                    ],
                    'manual_required' => [
                        'id' => 'manual_required',
                        'db_id' => 5,
                        'kind' => 'state',
                        'title' => 'Нужно уточнить',
                        'message' => [
                            'text' => 'Нужно уточнить',
                            'text_format' => Message::TEXT_FORMAT_PLAIN_TEXT,
                        ],
                        'buttons' => null,
                        'wait_reply_edges' => [],
                        'automatic_edges' => [],
                        'default_target_block_id' => null,
                    ],
                ],
                'edges' => [$waitEdge, $foundEdge, $manualRequiredEdge, $notFoundEdge],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function v3StaticChangeDataRuntimeSchema(int $channelId): array
    {
        return [
            'version' => 3,
            'builder_v3_runtime' => [
                'schema_version' => 3,
                'source_revision' => 'v3:test',
                'compiled_at' => now()->toISOString(),
                'entrypoints' => [[
                    'block_id' => 'change_data',
                    'channel_ids' => [$channelId],
                    'match' => 'strict',
                    'values' => ['старт'],
                    'priority' => 10,
                ]],
                'blocks' => [
                    'change_data' => [
                        'id' => 'change_data',
                        'db_id' => 1,
                        'kind' => 'state',
                        'title' => 'Изменить данные',
                        'actions' => [
                            [
                                'type' => 'write_contact_field',
                                'source_type' => 'static_value',
                                'static_value' => 'Московская область',
                                'source_block_id' => '',
                                'source_field_key' => '',
                                'target_scope' => 'contact',
                                'target_field' => 'region',
                            ],
                            [
                                'type' => 'write_contact_field',
                                'source_type' => 'static_value',
                                'static_value' => 'female',
                                'source_block_id' => '',
                                'source_field_key' => '',
                                'target_scope' => 'contact',
                                'target_field' => 'gender',
                            ],
                            [
                                'type' => 'write_contact_field',
                                'source_type' => 'static_value',
                                'static_value' => 'female',
                                'source_block_id' => '',
                                'source_field_key' => '',
                                'target_scope' => 'dialog',
                                'target_field' => 'selected_gender',
                            ],
                        ],
                        'message' => null,
                        'buttons' => null,
                        'wait_reply_edges' => [],
                        'automatic_edges' => [],
                        'default_target_block_id' => null,
                    ],
                ],
                'edges' => [],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function v3VariablesRuntimeSchema(int $channelId): array
    {
        $doneEdge = [
            'id' => '10',
            'edge_key' => 'edge_variables_done',
            'mode' => 'automatic',
            'priority' => 10,
            'transition_limit' => 0,
            'source_block_id' => 'variables',
            'target_block_id' => 'done',
            'from_output_id' => null,
            'label' => 'Дальше',
        ];

        return [
            'version' => 3,
            'builder_v3_runtime' => [
                'schema_version' => 3,
                'source_revision' => 'v3:test',
                'compiled_at' => now()->toISOString(),
                'entrypoints' => [[
                    'block_id' => 'variables',
                    'channel_ids' => [$channelId],
                    'match' => 'strict',
                    'values' => ['старт'],
                    'priority' => 10,
                ]],
                'blocks' => [
                    'variables' => [
                        'id' => 'variables',
                        'db_id' => 1,
                        'kind' => 'state',
                        'title' => 'Калькулятор',
                        'actions' => [[
                            'type' => 'variables',
                            'operations' => [[
                                'operation' => 'increment',
                                'field_key' => 'спросили_имя',
                                'amount' => 1,
                            ]],
                        ]],
                        'message' => null,
                        'buttons' => null,
                        'wait_reply_edges' => [],
                        'automatic_edges' => [$doneEdge],
                        'action_result_edges' => [],
                        'default_target_block_id' => null,
                    ],
                    'done' => [
                        'id' => 'done',
                        'db_id' => 2,
                        'kind' => 'state',
                        'title' => 'Готово',
                        'message' => [
                            'text' => 'Попытка записана',
                            'text_format' => Message::TEXT_FORMAT_PLAIN_TEXT,
                        ],
                        'buttons' => null,
                        'wait_reply_edges' => [],
                        'automatic_edges' => [],
                        'default_target_block_id' => null,
                    ],
                ],
                'edges' => [$doneEdge],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function v3SimulateStartParameterRuntimeSchema(int $channelId): array
    {
        $afterNoopEdge = [
            'id' => '20',
            'edge_key' => 'edge_after_noop',
            'mode' => 'automatic',
            'priority' => 10,
            'transition_limit' => 0,
            'source_block_id' => 'simulate',
            'target_block_id' => 'after_noop',
            'from_output_id' => null,
            'label' => 'Дальше',
        ];

        return [
            'version' => 3,
            'builder_v3_runtime' => [
                'schema_version' => 3,
                'source_revision' => 'v3:test',
                'compiled_at' => now()->toISOString(),
                'entrypoints' => [
                    [
                        'block_id' => 'simulate',
                        'channel_ids' => [$channelId],
                        'match' => 'strict',
                        'values' => ['анкета'],
                        'priority' => 10,
                    ],
                    [
                        'block_id' => 'payload_start',
                        'channel_ids' => [$channelId],
                        'match' => AutoReplyRule::MATCH_SCOPE_EXACT_PARAMETER,
                        'values' => ['payload_42'],
                        'priority' => 10,
                    ],
                ],
                'blocks' => [
                    'simulate' => [
                        'id' => 'simulate',
                        'db_id' => 1,
                        'kind' => 'state',
                        'title' => 'Имитировать старт',
                        'actions' => [[
                            'type' => 'simulate_start_parameter',
                            'source_scope' => 'dialog',
                            'source_field_key' => 'start_param',
                        ]],
                        'message' => null,
                        'buttons' => null,
                        'wait_reply_edges' => [],
                        'automatic_edges' => [$afterNoopEdge],
                        'action_result_edges' => [],
                        'default_target_block_id' => null,
                    ],
                    'payload_start' => [
                        'id' => 'payload_start',
                        'db_id' => 2,
                        'kind' => 'state',
                        'title' => 'Старт по параметру',
                        'message' => [
                            'text' => 'Сработал параметр {{input.start_param}}',
                            'text_format' => Message::TEXT_FORMAT_PLAIN_TEXT,
                        ],
                        'buttons' => null,
                        'wait_reply_edges' => [],
                        'automatic_edges' => [],
                        'default_target_block_id' => null,
                    ],
                    'after_noop' => [
                        'id' => 'after_noop',
                        'db_id' => 3,
                        'kind' => 'state',
                        'title' => 'Обычное продолжение',
                        'message' => [
                            'text' => 'Обычное продолжение',
                            'text_format' => Message::TEXT_FORMAT_PLAIN_TEXT,
                        ],
                        'buttons' => null,
                        'wait_reply_edges' => [],
                        'automatic_edges' => [],
                        'default_target_block_id' => null,
                    ],
                ],
                'edges' => [$afterNoopEdge],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function v3VariableMessageRuntimeSchema(int $channelId): array
    {
        return [
            'version' => 3,
            'builder_v3_runtime' => [
                'schema_version' => 3,
                'source_revision' => 'v3:test',
                'compiled_at' => now()->toISOString(),
                'entrypoints' => [[
                    'block_id' => 'ask_name',
                    'channel_ids' => [$channelId],
                    'match' => 'strict',
                    'values' => ['старт'],
                    'priority' => 10,
                ]],
                'blocks' => [
                    'ask_name' => [
                        'id' => 'ask_name',
                        'db_id' => 1,
                        'kind' => 'state',
                        'title' => 'Спросить имя',
                        'message' => [
                            'text' => 'Как тебя зовут?',
                            'text_format' => Message::TEXT_FORMAT_PLAIN_TEXT,
                            'text_mode' => 'by_dialog_variable',
                            'variable_key' => 'спросили_имя',
                            'variable_text_variants' => [
                                [
                                    'value' => '1',
                                    'text' => 'Как тебя зовут?',
                                ],
                                [
                                    'value' => '2',
                                    'text' => 'Напиши имя полностью',
                                ],
                            ],
                            'fallback_text' => 'Напиши своё имя',
                        ],
                        'buttons' => null,
                        'wait_reply_edges' => [],
                        'automatic_edges' => [],
                        'default_target_block_id' => null,
                    ],
                ],
                'edges' => [],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function v3GeoCityRuntimeSchema(
        int $channelId,
        bool $legacyGeoOutputs = false,
        array $geoAction = [],
    ): array {
        $waitEdge = $this->v3WaitReplyEdge('10', 'edge_to_geo', 'resolve_geo');
        $foundEdge = $this->v3GeoCityActionResultEdge('20', 'edge_geo_found', 'geo_found', 'geo_found_done', 'Город найден');
        $manualRequiredEdge = $this->v3GeoCityActionResultEdge('30', 'edge_geo_manual_required', 'geo_manual_required', 'geo_manual_required_done', 'Нужно уточнить');
        $ambiguousEdge = $this->v3GeoCityActionResultEdge('40', 'edge_geo_ambiguous', 'geo_ambiguous', 'geo_ambiguous_done', 'Несколько вариантов');
        $notFoundEdge = $this->v3GeoCityActionResultEdge('50', 'edge_geo_not_found', 'geo_not_found', 'geo_not_found_done', 'Не найдено');
        $belowThresholdEdge = $this->v3GeoCityActionResultEdge('60', 'edge_geo_below_threshold', 'geo_below_threshold', 'geo_below_threshold_done', 'Низкая уверенность');
        $inactiveEdge = $this->v3GeoCityActionResultEdge('70', 'edge_geo_inactive', 'geo_inactive', 'geo_inactive_done', 'Отключено');
        $failedEdge = $this->v3GeoCityActionResultEdge('80', 'edge_geo_failed', 'geo_failed', 'geo_failed_done', 'Ошибка');
        $limitEdge = $this->v3GeoCityActionResultEdge('90', 'edge_geo_limit_reached', 'geo_limit_reached', 'geo_limit_reached_done', 'Лимит');
        $resultEdges = $legacyGeoOutputs
            ? [
                $foundEdge,
                $manualRequiredEdge,
                $ambiguousEdge,
                $notFoundEdge,
                $belowThresholdEdge,
                $inactiveEdge,
                $failedEdge,
                $limitEdge,
            ]
            : [
                $foundEdge,
                $manualRequiredEdge,
                $notFoundEdge,
            ];
        $doneBlockLabels = [
            'geo_found_done' => 'Город найден',
            'geo_manual_required_done' => 'Нужно уточнить город',
            'geo_not_found_done' => 'Город не найден',
        ];

        if ($legacyGeoOutputs) {
            $doneBlockLabels += [
                'geo_ambiguous_done' => 'Нашли несколько вариантов',
                'geo_below_threshold_done' => 'Нужна проверка города',
                'geo_inactive_done' => 'Вариант отключён',
                'geo_failed_done' => 'Ошибка распознавания',
                'geo_limit_reached_done' => 'Лимит попыток',
            ];
        }

        $doneBlocks = collect($doneBlockLabels)->mapWithKeys(fn (string $text, string $id): array => [
            $id => [
                'id' => $id,
                'db_id' => crc32($id),
                'kind' => 'state',
                'title' => $text,
                'message' => [
                    'text' => $text,
                    'text_format' => Message::TEXT_FORMAT_PLAIN_TEXT,
                ],
                'buttons' => null,
                'wait_reply_edges' => [],
                'automatic_edges' => [],
                'default_target_block_id' => null,
            ],
        ])->all();

        return [
            'version' => 3,
            'builder_v3_runtime' => [
                'schema_version' => 3,
                'source_revision' => 'v3:test',
                'compiled_at' => now()->toISOString(),
                'entrypoints' => [[
                    'block_id' => 'start',
                    'channel_ids' => [$channelId],
                    'match' => 'strict',
                    'values' => ['старт'],
                    'priority' => 10,
                ]],
                'blocks' => array_merge([
                    'start' => [
                        'id' => 'start',
                        'db_id' => 1,
                        'kind' => 'state',
                        'title' => 'Спросить город',
                        'message' => [
                            'text' => 'В каком городе живёте?',
                            'text_format' => Message::TEXT_FORMAT_PLAIN_TEXT,
                        ],
                        'buttons' => null,
                        'wait_reply_edges' => [$waitEdge],
                        'automatic_edges' => [],
                        'default_target_block_id' => null,
                    ],
                    'resolve_geo' => [
                        'id' => 'resolve_geo',
                        'db_id' => 2,
                        'kind' => 'state',
                        'title' => 'Распознать город',
                        'actions' => [array_replace([
                            'type' => 'resolve_geo_city',
                            'source' => 'current_inbound_message',
                        ], $geoAction)],
                        'message' => null,
                        'buttons' => null,
                        'wait_reply_edges' => [],
                        'automatic_edges' => [],
                        'action_result_edges' => $resultEdges,
                        'default_target_block_id' => null,
                    ],
                ], $doneBlocks),
                'edges' => array_merge([$waitEdge], $resultEdges),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function v3GeoCityAiDataAction(): array
    {
        return [
            'type' => 'resolve_geo_city',
            'source' => 'ai_data',
            'source_block_client_key' => 'block_ai_city',
            'source_block_id' => 'ai_city',
            'city_field_key' => 'geo_city',
            'region_field_key' => 'geo_region',
            'country_field_key' => 'geo_country',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function v3GeoCityActionResultEdge(
        string $id,
        string $edgeKey,
        string $outputId,
        string $targetBlockId,
        string $label,
    ): array {
        return [
            'id' => $id,
            'edge_key' => $edgeKey,
            'mode' => 'action_result',
            'priority' => 10,
            'transition_limit' => 0,
            'source_block_id' => 'resolve_geo',
            'target_block_id' => $targetBlockId,
            'from_output_id' => $outputId,
            'label' => $label,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function v3DistanceToMoscowRuntimeSchema(int $channelId): array
    {
        $resolvedEdge = [
            'id' => 'edge_distance_resolved',
            'edge_key' => 'edge_distance_resolved',
            'mode' => 'action_result',
            'priority' => 10,
            'transition_limit' => 0,
            'source_block_id' => 'distance',
            'target_block_id' => 'done',
            'from_output_id' => 'distance_resolved',
            'label' => 'Рассчитано',
        ];

        return [
            'version' => 3,
            'builder_v3_runtime' => [
                'schema_version' => 3,
                'source_revision' => 'v3:test',
                'compiled_at' => now()->toISOString(),
                'entrypoints' => [[
                    'block_id' => 'distance',
                    'channel_ids' => [$channelId],
                    'match' => 'strict',
                    'values' => ['старт'],
                    'priority' => 10,
                ]],
                'blocks' => [
                    'distance' => [
                        'id' => 'distance',
                        'db_id' => 1,
                        'kind' => 'state',
                        'title' => 'Расстояние до Москвы',
                        'actions' => [[
                            'type' => 'calculate_distance_to_moscow',
                        ]],
                        'message' => null,
                        'buttons' => null,
                        'wait_reply_edges' => [],
                        'automatic_edges' => [],
                        'action_result_edges' => [$resolvedEdge],
                        'default_target_block_id' => null,
                    ],
                    'done' => [
                        'id' => 'done',
                        'db_id' => 2,
                        'kind' => 'state',
                        'title' => 'Готово',
                        'message' => [
                            'text' => 'Расстояние рассчитано',
                            'text_format' => Message::TEXT_FORMAT_PLAIN_TEXT,
                        ],
                        'buttons' => null,
                        'wait_reply_edges' => [],
                        'automatic_edges' => [],
                        'default_target_block_id' => null,
                    ],
                ],
                'edges' => [$resolvedEdge],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function v3UnsupportedActionRuntimeSchema(int $channelId): array
    {
        $waitingEdge = [
            'id' => 'edge_waiting',
            'edge_key' => 'edge_waiting',
            'mode' => 'action_result',
            'priority' => 10,
            'transition_limit' => 0,
            'source_block_id' => 'legacy_action',
            'target_block_id' => 'after_waiting',
            'from_output_id' => 'waiting',
            'label' => 'Ждёт ответ',
        ];
        $completedEdge = [
            'id' => 'edge_completed',
            'edge_key' => 'edge_completed',
            'mode' => 'action_result',
            'priority' => 10,
            'transition_limit' => 0,
            'source_block_id' => 'legacy_action',
            'target_block_id' => 'after_completed',
            'from_output_id' => 'completed',
            'label' => 'Заполнена',
        ];

        return [
            'version' => 3,
            'builder_v3_runtime' => [
                'schema_version' => 3,
                'source_revision' => 'v3:test',
                'compiled_at' => now()->toISOString(),
                'entrypoints' => [[
                    'block_id' => 'legacy_action',
                    'channel_ids' => [$channelId],
                    'match' => 'strict',
                    'values' => ['старт'],
                    'priority' => 10,
                ]],
                'blocks' => [
                    'legacy_action' => [
                        'id' => 'legacy_action',
                        'db_id' => 1,
                        'kind' => 'state',
                        'title' => 'Старое действие',
                        'actions' => [[
                            'type' => 'legacy_removed_action',
                        ]],
                        'message' => null,
                        'buttons' => null,
                        'wait_reply_edges' => [],
                        'automatic_edges' => [],
                        'action_result_edges' => [$waitingEdge, $completedEdge],
                        'default_target_block_id' => null,
                    ],
                    'after_waiting' => [
                        'id' => 'after_waiting',
                        'db_id' => 2,
                        'kind' => 'state',
                        'title' => 'После ожидания',
                        'message' => [
                            'text' => 'Не должно отправиться сразу',
                            'text_format' => Message::TEXT_FORMAT_PLAIN_TEXT,
                        ],
                        'buttons' => null,
                        'wait_reply_edges' => [],
                        'automatic_edges' => [],
                        'default_target_block_id' => null,
                    ],
                    'after_completed' => [
                        'id' => 'after_completed',
                        'db_id' => 3,
                        'kind' => 'state',
                        'title' => 'Старое действие завершено',
                        'message' => [
                            'text' => 'Старое действие завершено',
                            'text_format' => Message::TEXT_FORMAT_PLAIN_TEXT,
                        ],
                        'buttons' => null,
                        'wait_reply_edges' => [],
                        'automatic_edges' => [],
                        'default_target_block_id' => null,
                    ],
                ],
                'edges' => [$waitingEdge, $completedEdge],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function v3EditMessageRemoveButtonsRuntimeSchema(
        int $channelId,
        bool $nextHasInlineButtons = false,
        string $operation = 'remove_buttons',
        string $target = 'last_current_run_outbound_with_inline_buttons',
    ): array {
        $edgeToAction = [
            'id' => 'edge_remove_buttons',
            'source_block_id' => 'start',
            'target_block_id' => 'remove_buttons',
            'from_output_id' => 'btn_remove',
            'label' => 'Убрать кнопки',
        ];
        $edgeToDone = [
            'id' => 'edge_done',
            'edge_key' => 'edge_done',
            'mode' => 'automatic',
            'priority' => 10,
            'transition_limit' => 0,
            'source_block_id' => 'remove_buttons',
            'target_block_id' => 'done',
            'from_output_id' => null,
            'label' => 'Дальше',
            'match' => [
                'type' => 'any_inbound',
                'text' => '',
                'variants' => [],
            ],
            'input_capture' => [
                'enabled' => false,
                'field_scope' => 'dialog',
                'field_key' => '',
                'data_type' => 'any_text',
            ],
        ];

        return [
            'version' => 3,
            'builder_v3_runtime' => [
                'schema_version' => 3,
                'source_revision' => 'v3:test',
                'compiled_at' => now()->toISOString(),
                'entrypoints' => [[
                    'block_id' => 'start',
                    'channel_ids' => [$channelId],
                    'match' => 'strict',
                    'values' => ['старт'],
                    'priority' => 10,
                ]],
                'blocks' => [
                    'start' => [
                        'id' => 'start',
                        'db_id' => 1,
                        'kind' => 'state',
                        'title' => 'Старт',
                        'message' => [
                            'text' => 'Сообщение с inline-кнопкой',
                            'text_format' => Message::TEXT_FORMAT_PLAIN_TEXT,
                        ],
                        'buttons' => [
                            'placement' => 'inline_message',
                            'rows' => [[
                                [
                                    'id' => 'btn_remove',
                                    'text' => 'Убрать кнопки',
                                    'type' => 'text',
                                    'normalized_text' => 'убрать кнопки',
                                    'output_id' => 'btn_remove',
                                    'target_block_id' => 'remove_buttons',
                                ],
                            ]],
                        ],
                        'wait_reply_edges' => [],
                        'automatic_edges' => [],
                        'default_target_block_id' => null,
                    ],
                    'remove_buttons' => [
                        'id' => 'remove_buttons',
                        'db_id' => 2,
                        'kind' => 'state',
                        'title' => 'Убрать кнопки',
                        'actions' => [[
                            'type' => 'edit_message',
                            'operation' => $operation,
                            'target' => $target,
                        ]],
                        'message' => null,
                        'buttons' => null,
                        'wait_reply_edges' => [],
                        'automatic_edges' => [$edgeToDone],
                        'default_target_block_id' => 'done',
                    ],
                    'done' => [
                        'id' => 'done',
                        'db_id' => 3,
                        'kind' => 'state',
                        'title' => 'Готово',
                        'message' => [
                            'text' => $nextHasInlineButtons ? 'Выберите следующий шаг' : 'Кнопки убраны',
                            'text_format' => Message::TEXT_FORMAT_PLAIN_TEXT,
                        ],
                        'buttons' => $nextHasInlineButtons ? [
                            'placement' => 'inline_message',
                            'rows' => [[
                                [
                                    'id' => 'btn_next',
                                    'text' => 'Дальше',
                                    'type' => 'text',
                                    'normalized_text' => 'дальше',
                                    'output_id' => 'btn_next',
                                    'target_block_id' => null,
                                ],
                            ]],
                        ] : null,
                        'wait_reply_edges' => [],
                        'automatic_edges' => [],
                        'default_target_block_id' => null,
                    ],
                ],
                'edges' => [$edgeToAction, $edgeToDone],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function v3EditMessageDeleteOnStartRuntimeSchema(int $channelId): array
    {
        return [
            'version' => 3,
            'builder_v3_runtime' => [
                'schema_version' => 3,
                'source_revision' => 'v3:test',
                'compiled_at' => now()->toISOString(),
                'entrypoints' => [[
                    'block_id' => 'start',
                    'channel_ids' => [$channelId],
                    'match' => 'strict',
                    'values' => ['старт'],
                    'priority' => 10,
                ]],
                'blocks' => [
                    'start' => [
                        'id' => 'start',
                        'db_id' => 1,
                        'kind' => 'state',
                        'title' => 'Старт',
                        'actions' => [[
                            'type' => 'edit_message',
                            'operation' => 'delete_message',
                            'target' => 'last_current_run_outbound',
                        ]],
                        'message' => [
                            'text' => 'Новое стартовое сообщение',
                            'text_format' => Message::TEXT_FORMAT_PLAIN_TEXT,
                        ],
                        'buttons' => null,
                        'wait_reply_edges' => [],
                        'automatic_edges' => [],
                        'default_target_block_id' => null,
                    ],
                ],
                'edges' => [],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function v3AiNameRuntimeSchema(int $channelId): array
    {
        $waitEdge = $this->v3WaitReplyEdge('10', 'edge_to_ai', 'ai');
        $acceptedEdge = [
            'id' => '20',
            'edge_key' => 'edge_name_accepted',
            'mode' => 'ai_analysis',
            'priority' => 10,
            'transition_limit' => 0,
            'source_block_id' => 'ai',
            'target_block_id' => 'accepted',
            'from_output_id' => 'name_accepted',
            'label' => 'Имя найдено',
            'match' => [
                'type' => 'any_inbound',
                'text' => '',
                'variants' => [],
            ],
            'input_capture' => [
                'enabled' => false,
                'field_scope' => 'dialog',
                'field_key' => '',
                'data_type' => 'any_text',
            ],
        ];
        $retryEdge = [
            'id' => '30',
            'edge_key' => 'edge_name_retry',
            'mode' => 'ai_analysis',
            'priority' => 10,
            'transition_limit' => 0,
            'source_block_id' => 'ai',
            'target_block_id' => 'retry',
            'from_output_id' => 'name_retry',
            'label' => 'Имя не найдено',
            'match' => [
                'type' => 'any_inbound',
                'text' => '',
                'variants' => [],
            ],
            'input_capture' => [
                'enabled' => false,
                'field_scope' => 'dialog',
                'field_key' => '',
                'data_type' => 'any_text',
            ],
        ];

        return [
            'version' => 3,
            'builder_v3_runtime' => [
                'schema_version' => 3,
                'source_revision' => 'v3:test',
                'compiled_at' => now()->toISOString(),
                'entrypoints' => [[
                    'block_id' => 'ask_name',
                    'channel_ids' => [$channelId],
                    'match' => 'strict',
                    'values' => ['старт'],
                    'priority' => 10,
                ]],
                'blocks' => [
                    'ask_name' => [
                        'id' => 'ask_name',
                        'db_id' => 1,
                        'kind' => 'state',
                        'title' => 'Спрашиваем имя',
                        'message' => [
                            'text' => 'Как вас зовут?',
                            'text_format' => Message::TEXT_FORMAT_PLAIN_TEXT,
                        ],
                        'buttons' => null,
                        'wait_reply_edges' => [$waitEdge],
                        'automatic_edges' => [],
                        'default_target_block_id' => null,
                    ],
                    'ai' => [
                        'id' => 'ai',
                        'db_id' => 2,
                        'kind' => 'state',
                        'title' => 'ИИ анализирует имя',
                        'message' => null,
                        'buttons' => null,
                        'wait_reply_edges' => [],
                        'automatic_edges' => [],
                        'default_target_block_id' => null,
                        'ai_analysis' => [
                            'prompt' => 'Определи, есть ли в сообщениях клиента имя.',
                            'source' => 'inbound_messages_after_previous_bot_message',
                            'extract_fields' => [
                                [
                                    'key' => 'first_name',
                                    'label' => 'Имя клиента',
                                    'type' => 'text',
                                ],
                            ],
                            'outputs' => [
                                [
                                    'id' => 'name_accepted',
                                    'label' => 'Имя найдено',
                                    'delay_seconds' => 0,
                                    'target_block_id' => 'accepted',
                                    'edge' => $acceptedEdge,
                                ],
                                [
                                    'id' => 'name_retry',
                                    'label' => 'Имя не найдено',
                                    'delay_seconds' => 10,
                                    'target_block_id' => 'retry',
                                    'edge' => $retryEdge,
                                ],
                            ],
                        ],
                    ],
                    'accepted' => [
                        'id' => 'accepted',
                        'db_id' => 3,
                        'kind' => 'state',
                        'title' => 'Имя принято',
                        'actions' => [
                            [
                                'type' => 'write_contact_field',
                                'source_type' => 'ai_data',
                                'source_block_id' => '',
                                'source_field_key' => 'first_name',
                                'target_scope' => 'contact',
                                'target_field' => 'first_name',
                            ],
                        ],
                        'message' => [
                            'text' => 'Имя принято',
                            'text_format' => Message::TEXT_FORMAT_PLAIN_TEXT,
                        ],
                        'buttons' => null,
                        'wait_reply_edges' => [],
                        'automatic_edges' => [],
                        'default_target_block_id' => null,
                    ],
                    'retry' => [
                        'id' => 'retry',
                        'db_id' => 4,
                        'kind' => 'state',
                        'title' => 'Повторить вопрос',
                        'message' => [
                            'text' => 'Повторите имя',
                            'text_format' => Message::TEXT_FORMAT_PLAIN_TEXT,
                        ],
                        'buttons' => null,
                        'wait_reply_edges' => [],
                        'automatic_edges' => [],
                        'default_target_block_id' => null,
                    ],
                ],
                'edges' => [$waitEdge, $acceptedEdge, $retryEdge],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function v3LinkButtonRuntimeSchema(int $channelId, string $placement = 'auto'): array
    {
        return [
            'version' => 3,
            'builder_v3_runtime' => [
                'schema_version' => 3,
                'source_revision' => 'v3:test',
                'compiled_at' => now()->toISOString(),
                'entrypoints' => [[
                    'block_id' => 'start',
                    'channel_ids' => [$channelId],
                    'match' => 'strict',
                    'values' => ['старт'],
                    'priority' => 10,
                ]],
                'blocks' => [
                    'start' => [
                        'id' => 'start',
                        'db_id' => 1,
                        'title' => 'Старт',
                        'message' => [
                            'text' => 'Откройте ссылку',
                            'text_format' => Message::TEXT_FORMAT_PLAIN_TEXT,
                        ],
                        'buttons' => [
                            'placement' => $placement,
                            'rows' => [[
                                [
                                    'id' => 'btn_link',
                                    'type' => 'link',
                                    'text' => 'Открыть сайт',
                                    'url' => 'https://example.com/form',
                                    'normalized_text' => 'открыть сайт',
                                    'output_id' => 'btn_link',
                                    'target_block_id' => null,
                                ],
                            ]],
                        ],
                        'default_target_block_id' => null,
                    ],
                ],
                'edges' => [],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function v3ButtonAndWaitReplyRuntimeSchema(int $channelId): array
    {
        $buttonEdge = [
            'id' => '10',
            'edge_key' => 'edge_button_catalog',
            'mode' => 'button',
            'priority' => 10,
            'transition_limit' => 0,
            'source_block_id' => 'start',
            'target_block_id' => 'catalog',
            'from_output_id' => 'btn_catalog',
            'label' => 'Получить каталог',
            'match' => [
                'type' => 'exact_text',
                'text' => 'Получить каталог',
                'variants' => ['Получить каталог'],
            ],
            'input_capture' => [
                'enabled' => false,
                'field_scope' => 'dialog',
                'field_key' => '',
                'data_type' => 'any_text',
            ],
        ];

        $waitReplyEdge = $this->v3WaitReplyEdge('20', 'edge_manual', 'manual', priority: 20);

        return [
            'version' => 3,
            'builder_v3_runtime' => [
                'schema_version' => 3,
                'source_revision' => 'v3:test',
                'compiled_at' => now()->toISOString(),
                'entrypoints' => [[
                    'block_id' => 'start',
                    'channel_ids' => [$channelId],
                    'match' => 'strict',
                    'values' => ['старт'],
                    'priority' => 10,
                ]],
                'blocks' => [
                    'start' => [
                        'id' => 'start',
                        'db_id' => 1,
                        'kind' => 'state',
                        'title' => 'Старт',
                        'message' => [
                            'text' => 'Выберите действие',
                            'text_format' => Message::TEXT_FORMAT_PLAIN_TEXT,
                        ],
                        'buttons' => [
                            'placement' => 'auto',
                            'rows' => [[
                                [
                                    'id' => 'btn_catalog',
                                    'text' => 'Получить каталог',
                                    'type' => 'text',
                                    'normalized_text' => 'получить каталог',
                                    'output_id' => 'btn_catalog',
                                    'target_block_id' => 'catalog',
                                    'edge' => $buttonEdge,
                                ],
                            ]],
                        ],
                        'wait_reply_edges' => [$waitReplyEdge],
                        'default_target_block_id' => null,
                    ],
                    'catalog' => [
                        'id' => 'catalog',
                        'db_id' => 2,
                        'kind' => 'state',
                        'title' => 'Каталог',
                        'message' => [
                            'text' => 'Сработала кнопка',
                            'text_format' => Message::TEXT_FORMAT_PLAIN_TEXT,
                        ],
                        'buttons' => null,
                        'wait_reply_edges' => [],
                        'default_target_block_id' => null,
                    ],
                    'manual' => [
                        'id' => 'manual',
                        'db_id' => 3,
                        'kind' => 'state',
                        'title' => 'Обычная стрелка',
                        'message' => [
                            'text' => 'Сработала обычная стрелка с большим приоритетом',
                            'text_format' => Message::TEXT_FORMAT_PLAIN_TEXT,
                        ],
                        'buttons' => null,
                        'wait_reply_edges' => [],
                        'default_target_block_id' => null,
                    ],
                ],
                'edges' => [$buttonEdge, $waitReplyEdge],
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $waitReplyEdges
     * @param  array<string, string>  $targetMessages
     * @return array<string, mixed>
     */
    private function v3WaitReplyRuntimeSchema(int $channelId, array $waitReplyEdges, array $targetMessages): array
    {
        $targetBlocks = [];

        foreach ($targetMessages as $blockId => $messageText) {
            $targetBlocks[$blockId] = [
                'id' => $blockId,
                'db_id' => crc32($blockId),
                'kind' => 'state',
                'title' => $blockId,
                'message' => [
                    'text' => $messageText,
                    'text_format' => Message::TEXT_FORMAT_PLAIN_TEXT,
                ],
                'buttons' => null,
                'wait_reply_edges' => [],
                'default_target_block_id' => null,
            ];
        }

        return [
            'version' => 3,
            'builder_v3_runtime' => [
                'schema_version' => 3,
                'source_revision' => 'v3:test',
                'compiled_at' => now()->toISOString(),
                'entrypoints' => [[
                    'block_id' => 'start',
                    'channel_ids' => [$channelId],
                    'match' => 'strict',
                    'values' => ['старт'],
                    'priority' => 10,
                ]],
                'blocks' => array_merge([
                    'start' => [
                        'id' => 'start',
                        'db_id' => 1,
                        'kind' => 'state',
                        'title' => 'Старт',
                        'message' => [
                            'text' => 'Напишите ответ',
                            'text_format' => Message::TEXT_FORMAT_PLAIN_TEXT,
                        ],
                        'buttons' => null,
                        'wait_reply_edges' => $waitReplyEdges,
                        'default_target_block_id' => null,
                    ],
                ], $targetBlocks),
                'edges' => collect($waitReplyEdges)
                    ->map(fn (array $edge): array => [
                        'id' => $edge['id'],
                        'edge_key' => $edge['edge_key'],
                        'mode' => 'wait_reply',
                        'priority' => $edge['priority'] ?? 10,
                        'transition_limit' => $edge['transition_limit'] ?? 0,
                        'source_block_id' => 'start',
                        'target_block_id' => $edge['target_block_id'],
                        'from_output_id' => null,
                        'label' => $edge['label'] ?? '',
                        'transition_actions' => $edge['transition_actions'] ?? [],
                    ])
                    ->values()
                    ->all(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function v3AutomaticRuntimeSchema(int $channelId, int $transitionLimit = 0, ?array $delay = null): array
    {
        $delay ??= [
            'type' => 'immediate',
            'value' => 0,
            'unit' => 'sec',
            'cancel_if_left_source_block' => true,
        ];

        $automaticEdge = [
            'id' => '30',
            'edge_key' => 'edge_auto_next',
            'mode' => 'automatic',
            'priority' => 10,
            'transition_limit' => $transitionLimit,
            'delay' => $delay,
            'source_block_id' => 'start',
            'target_block_id' => 'next',
            'from_output_id' => null,
            'label' => 'Дальше',
            'match' => [
                'type' => 'any_inbound',
                'text' => '',
                'variants' => [],
            ],
            'input_capture' => [
                'enabled' => false,
                'field_scope' => 'dialog',
                'field_key' => '',
                'data_type' => 'any_text',
            ],
        ];

        return [
            'version' => 3,
            'builder_v3_runtime' => [
                'schema_version' => 3,
                'source_revision' => 'v3:test',
                'compiled_at' => now()->toISOString(),
                'entrypoints' => [[
                    'block_id' => 'start',
                    'channel_ids' => [$channelId],
                    'match' => 'strict',
                    'values' => ['старт'],
                    'priority' => 10,
                ]],
                'blocks' => [
                    'start' => [
                        'id' => 'start',
                        'db_id' => 1,
                        'kind' => 'state',
                        'title' => 'Старт',
                        'message' => [
                            'text' => 'Стартовый блок',
                            'text_format' => Message::TEXT_FORMAT_PLAIN_TEXT,
                        ],
                        'buttons' => null,
                        'wait_reply_edges' => [],
                        'automatic_edges' => [$automaticEdge],
                        'default_target_block_id' => 'next',
                    ],
                    'next' => [
                        'id' => 'next',
                        'db_id' => 2,
                        'kind' => 'state',
                        'title' => 'Следующий',
                        'message' => [
                            'text' => 'Автоматический переход',
                            'text_format' => Message::TEXT_FORMAT_PLAIN_TEXT,
                        ],
                        'buttons' => null,
                        'wait_reply_edges' => [],
                        'automatic_edges' => [],
                        'default_target_block_id' => null,
                    ],
                ],
                'edges' => [$automaticEdge],
            ],
        ];
    }

    /**
     * @param  list<string>  $variants
     * @param  array<string, mixed>  $inputCapture
     * @return array<string, mixed>
     */
    private function v3WaitReplyEdge(
        string $id,
        string $edgeKey,
        string $targetBlockId,
        string $matchType = 'any_inbound',
        array $variants = [],
        int $priority = 10,
        int $transitionLimit = 0,
        array $inputCapture = [],
        string $contactPhoneCondition = '',
        string $dialogPhoneCondition = '',
        array $fieldCondition = [],
        string $expression = '',
        array $transitionActions = [],
    ): array {
        return [
            'id' => $id,
            'edge_key' => $edgeKey,
            'mode' => 'wait_reply',
            'priority' => $priority,
            'transition_limit' => $transitionLimit,
            'contact_phone_condition' => $contactPhoneCondition,
            'dialog_phone_condition' => $dialogPhoneCondition,
            'expression' => $expression,
            'field_condition' => array_merge([
                'enabled' => false,
                'field_scope' => 'dialog',
                'field_key' => '',
                'operator' => 'filled',
                'value' => '',
            ], $fieldCondition),
            'source_block_id' => 'start',
            'target_block_id' => $targetBlockId,
            'from_output_id' => null,
            'label' => '',
            'match' => [
                'type' => $matchType,
                'text' => implode("\n", $variants),
                'variants' => $variants,
            ],
            'input_capture' => array_merge([
                'enabled' => false,
                'field_scope' => 'dialog',
                'field_key' => '',
                'data_type' => 'any_text',
            ], $inputCapture),
            'transition_actions' => $transitionActions,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function v3RequestPhoneButtonRuntimeSchema(int $channelId, string $placement = 'auto'): array
    {
        return [
            'version' => 3,
            'builder_v3_runtime' => [
                'schema_version' => 3,
                'source_revision' => 'v3:test',
                'compiled_at' => now()->toISOString(),
                'entrypoints' => [[
                    'block_id' => 'ask_phone',
                    'channel_ids' => [$channelId],
                    'match' => 'strict',
                    'values' => ['старт'],
                    'priority' => 10,
                ]],
                'blocks' => [
                    'ask_phone' => [
                        'id' => 'ask_phone',
                        'db_id' => 1,
                        'kind' => 'state',
                        'title' => 'Запрос телефона',
                        'message' => [
                            'text' => 'Поделитесь телефоном',
                            'text_format' => Message::TEXT_FORMAT_PLAIN_TEXT,
                        ],
                        'buttons' => [
                            'placement' => $placement,
                            'rows' => [[
                                [
                                    'id' => 'btn_phone',
                                    'text' => 'Поделиться номером телефона',
                                    'type' => 'request_phone',
                                    'normalized_text' => 'поделиться номером телефона',
                                    'output_id' => 'btn_phone',
                                    'target_block_id' => 'thanks',
                                ],
                            ]],
                        ],
                        'default_target_block_id' => null,
                    ],
                    'thanks' => [
                        'id' => 'thanks',
                        'db_id' => 2,
                        'kind' => 'state',
                        'title' => 'Спасибо',
                        'message' => [
                            'text' => 'Спасибо, телефон получен',
                            'text_format' => Message::TEXT_FORMAT_PLAIN_TEXT,
                        ],
                        'buttons' => null,
                        'default_target_block_id' => null,
                    ],
                ],
                'edges' => [[
                    'id' => 'edge_phone',
                    'source_block_id' => 'ask_phone',
                    'target_block_id' => 'thanks',
                    'from_output_id' => 'btn_phone',
                    'label' => 'Поделиться номером телефона',
                ]],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function v3RequestPhoneNonStateRuntimeSchema(int $channelId): array
    {
        return [
            'version' => 3,
            'builder_v3_runtime' => [
                'schema_version' => 3,
                'source_revision' => 'v3:test',
                'compiled_at' => now()->toISOString(),
                'entrypoints' => [[
                    'block_id' => 'ask_phone',
                    'channel_ids' => [$channelId],
                    'match' => 'strict',
                    'values' => ['старт'],
                    'priority' => 10,
                ]],
                'blocks' => [
                    'ask_phone' => [
                        'id' => 'ask_phone',
                        'db_id' => 1,
                        'kind' => 'state',
                        'title' => 'Запрос телефона',
                        'message' => [
                            'text' => 'Поделитесь телефоном',
                            'text_format' => Message::TEXT_FORMAT_PLAIN_TEXT,
                        ],
                        'buttons' => [
                            'placement' => 'auto',
                            'rows' => [[
                                [
                                    'id' => 'btn_phone',
                                    'text' => 'Поделиться номером телефона',
                                    'type' => 'request_phone',
                                    'normalized_text' => 'поделиться номером телефона',
                                    'output_id' => 'btn_phone',
                                    'target_block_id' => 'phone_received',
                                ],
                            ]],
                        ],
                        'default_target_block_id' => null,
                    ],
                    'phone_received' => [
                        'id' => 'phone_received',
                        'db_id' => 2,
                        'kind' => 'non_state',
                        'title' => 'Телефон получен',
                        'message' => [
                            'text' => 'Телефон получен серым блоком',
                            'text_format' => Message::TEXT_FORMAT_PLAIN_TEXT,
                        ],
                        'buttons' => [
                            'placement' => 'auto',
                            'rows' => [[
                                [
                                    'id' => 'btn_gray_phone',
                                    'text' => 'Повторно поделиться телефоном',
                                    'type' => 'request_phone',
                                    'normalized_text' => 'повторно поделиться телефоном',
                                    'output_id' => 'btn_gray_phone',
                                    'target_block_id' => 'gray_phone_received',
                                ],
                            ]],
                        ],
                        'default_target_block_id' => null,
                    ],
                    'gray_phone_received' => [
                        'id' => 'gray_phone_received',
                        'db_id' => 3,
                        'kind' => 'state',
                        'title' => 'Серый контекст',
                        'message' => [
                            'text' => 'Телефон принят из серого контекста',
                            'text_format' => Message::TEXT_FORMAT_PLAIN_TEXT,
                        ],
                        'buttons' => null,
                        'default_target_block_id' => null,
                    ],
                ],
                'edges' => [
                    [
                        'id' => 'edge_phone',
                        'source_block_id' => 'ask_phone',
                        'target_block_id' => 'phone_received',
                        'from_output_id' => 'btn_phone',
                        'label' => 'Поделиться номером телефона',
                    ],
                    [
                        'id' => 'edge_gray_phone',
                        'source_block_id' => 'phone_received',
                        'target_block_id' => 'gray_phone_received',
                        'from_output_id' => 'btn_gray_phone',
                        'label' => 'Повторно поделиться телефоном',
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function v3NonStateRuntimeSchema(int $channelId): array
    {
        return [
            'version' => 3,
            'builder_v3_runtime' => [
                'schema_version' => 3,
                'source_revision' => 'v3:test',
                'compiled_at' => now()->toISOString(),
                'entrypoints' => [[
                    'block_id' => 'ask_phone',
                    'channel_ids' => [$channelId],
                    'match' => 'strict',
                    'values' => ['старт'],
                    'priority' => 10,
                ]],
                'blocks' => [
                    'ask_phone' => [
                        'id' => 'ask_phone',
                        'db_id' => 1,
                        'kind' => 'state',
                        'title' => 'Введите телефон',
                        'message' => [
                            'text' => 'Введите номер телефона',
                            'text_format' => Message::TEXT_FORMAT_PLAIN_TEXT,
                        ],
                        'buttons' => [
                            'placement' => 'auto',
                            'rows' => [[
                                [
                                    'id' => 'btn_invalid_phone',
                                    'text' => 'Это не телефон',
                                    'normalized_text' => 'это не телефон',
                                    'output_id' => 'btn_invalid_phone',
                                    'target_block_id' => 'invalid_phone',
                                ],
                            ]],
                        ],
                        'default_target_block_id' => null,
                    ],
                    'invalid_phone' => [
                        'id' => 'invalid_phone',
                        'db_id' => 2,
                        'kind' => 'non_state',
                        'title' => 'Ошибка телефона',
                        'message' => [
                            'text' => 'Это не номер телефона',
                            'text_format' => Message::TEXT_FORMAT_PLAIN_TEXT,
                        ],
                        'buttons' => null,
                        'default_target_block_id' => null,
                    ],
                ],
                'edges' => [[
                    'id' => 'edge_invalid_phone',
                    'source_block_id' => 'ask_phone',
                    'target_block_id' => 'invalid_phone',
                    'from_output_id' => 'btn_invalid_phone',
                    'label' => 'Это не телефон',
                ]],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function v3NonStateStartOverlayRuntimeSchema(int $channelId): array
    {
        return [
            'version' => 3,
            'builder_v3_runtime' => [
                'schema_version' => 3,
                'source_revision' => 'v3:test',
                'compiled_at' => now()->toISOString(),
                'entrypoints' => [[
                    'block_id' => 'overlay',
                    'channel_ids' => [$channelId],
                    'match' => 'strict',
                    'values' => ['старт серый'],
                    'priority' => 20,
                ]],
                'blocks' => [
                    'block_1' => [
                        'id' => 'block_1',
                        'db_id' => 1,
                        'kind' => 'state',
                        'title' => 'Блок 1',
                        'message' => null,
                        'buttons' => [
                            'placement' => 'auto',
                            'rows' => [[
                                [
                                    'id' => 'btn_1',
                                    'text' => 'Кнопка 1',
                                    'normalized_text' => 'кнопка 1',
                                    'output_id' => 'btn_1',
                                    'target_block_id' => 'after_button',
                                ],
                            ]],
                        ],
                        'default_target_block_id' => null,
                    ],
                    'overlay' => [
                        'id' => 'overlay',
                        'db_id' => 2,
                        'kind' => 'non_state',
                        'title' => 'Серый блок',
                        'message' => [
                            'text' => 'Серый ответ',
                            'text_format' => Message::TEXT_FORMAT_PLAIN_TEXT,
                        ],
                        'buttons' => null,
                        'default_target_block_id' => null,
                    ],
                    'after_button' => [
                        'id' => 'after_button',
                        'db_id' => 3,
                        'kind' => 'state',
                        'title' => 'После кнопки',
                        'message' => [
                            'text' => 'Кнопка сработала',
                            'text_format' => Message::TEXT_FORMAT_PLAIN_TEXT,
                        ],
                        'buttons' => null,
                        'default_target_block_id' => null,
                    ],
                ],
                'edges' => [[
                    'id' => 'edge_button',
                    'source_block_id' => 'block_1',
                    'target_block_id' => 'after_button',
                    'from_output_id' => 'btn_1',
                    'label' => 'Кнопка 1',
                ]],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function v3CardIdRuntimeSchema(int $channelId): array
    {
        return [
            'version' => 3,
            'builder_v3_runtime' => [
                'schema_version' => 3,
                'source_revision' => 'v3:test',
                'compiled_at' => now()->toISOString(),
                'entrypoints' => [[
                    'block_id' => '72',
                    'db_block_id' => 116,
                    'channel_ids' => [$channelId],
                    'match' => 'strict',
                    'values' => ['старт'],
                    'priority' => 10,
                ]],
                'blocks' => [
                    '72' => [
                        'id' => '72',
                        'card_id' => '72',
                        'db_id' => 116,
                        'kind' => 'state',
                        'title' => 'Блок 1',
                        'message' => null,
                        'buttons' => [
                            'placement' => 'auto',
                            'rows' => [[
                                [
                                    'id' => 'btn_next',
                                    'text' => 'Далее',
                                    'type' => 'text',
                                    'normalized_text' => 'далее',
                                    'output_id' => 'btn_next',
                                    'target_block_id' => '73',
                                ],
                            ]],
                        ],
                        'default_target_block_id' => null,
                    ],
                    '73' => [
                        'id' => '73',
                        'card_id' => '73',
                        'db_id' => 117,
                        'kind' => 'state',
                        'title' => 'Следующий',
                        'message' => [
                            'text' => 'Следующий блок',
                            'text_format' => Message::TEXT_FORMAT_PLAIN_TEXT,
                        ],
                        'buttons' => null,
                        'default_target_block_id' => null,
                    ],
                ],
                'edges' => [[
                    'id' => 'edge_next',
                    'source_block_id' => '72',
                    'target_block_id' => '73',
                    'source_db_block_id' => 116,
                    'target_db_block_id' => 117,
                    'from_output_id' => 'btn_next',
                    'label' => 'Далее',
                ]],
            ],
        ];
    }

    private function processScenarioTextReply(
        Channel $channel,
        Contact $contact,
        ContactIdentity $identity,
        Dialog $dialog,
        ScenarioRun $run,
        string $text,
        array $overrides = [],
    ): void {
        $message = Message::factory()->create(array_merge([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => $text,
        ], $overrides));

        (new ProcessScenarioInboundJob($message->id, $run->id))
            ->handle(app(ScenarioRegistry::class));
    }

    private function processScenarioPhoneShare(
        Channel $channel,
        Contact $contact,
        ContactIdentity $identity,
        Dialog $dialog,
        ScenarioRun $run,
    ): void {
        $message = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_CONTACT_SHARE,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'raw_payload' => [
                'message' => [
                    'contact' => [
                        'phone_number' => '+7 999 123 45 67',
                    ],
                ],
            ],
        ]);

        (new ProcessScenarioInboundJob($message->id, $run->id))
            ->handle(app(ScenarioRegistry::class));
    }

    private function bitrix24ContactSyncQueued(Contact $contact): Bitrix24ContactSyncQueueResultData
    {
        return new Bitrix24ContactSyncQueueResultData(
            queued: true,
            alreadyPending: false,
            ready: true,
            rootContactId: $contact->id,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function linearSchema(string $parameter): array
    {
        return [
            'version' => 1,
            'start_block_id' => 'welcome',
            'triggers' => [
                [
                    'type' => 'parameter',
                    'value' => $parameter,
                ],
            ],
            'blocks' => [
                'welcome' => [
                    'type' => 'message',
                    'text' => 'Добро пожаловать в сценарий.',
                    'next' => 'ask_name',
                ],
                'ask_name' => [
                    'type' => 'question',
                    'text' => 'Как вас зовут?',
                    'save_to' => 'run.first_name',
                    'next' => 'ask_budget',
                ],
                'ask_budget' => [
                    'type' => 'question',
                    'text' => 'Какой у вас бюджет?',
                    'save_to' => 'run.budget_tier',
                    'next' => 'end',
                ],
                'end' => [
                    'type' => 'complete',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function phoneCaptureSchema(string $parameter): array
    {
        return [
            'version' => 1,
            'start_block_id' => 'welcome',
            'triggers' => [
                [
                    'type' => 'parameter',
                    'value' => $parameter,
                ],
            ],
            'blocks' => [
                'welcome' => [
                    'type' => 'message',
                    'text' => 'Добро пожаловать в сценарий.',
                    'next' => 'capture_phone',
                ],
                'capture_phone' => [
                    'type' => 'phone_capture',
                    'text' => 'Поделитесь номером телефона.',
                    'next' => 'thank_you',
                ],
                'thank_you' => [
                    'type' => 'message',
                    'text' => 'Спасибо, номер получили.',
                    'next' => 'end',
                ],
                'end' => [
                    'type' => 'complete',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function conditionalSchema(string $parameter, string $strongTagSlug, string $weakTagSlug): array
    {
        return [
            'version' => 1,
            'start_block_id' => 'welcome',
            'triggers' => [
                [
                    'type' => 'parameter',
                    'value' => $parameter,
                ],
            ],
            'blocks' => [
                'welcome' => [
                    'type' => 'message',
                    'text' => 'Добро пожаловать в сценарий.',
                    'next' => 'ask_budget',
                ],
                'ask_budget' => [
                    'type' => 'question',
                    'text' => 'Какой у вас бюджет?',
                    'save_to' => 'run.budget_tier',
                    'next' => 'evaluate_budget',
                ],
                'evaluate_budget' => [
                    'type' => 'condition',
                    'branches' => [
                        [
                            'if' => [
                                'var' => 'run.budget_tier',
                                'in' => ['middle', 'high'],
                            ],
                            'then' => 'strong_branch',
                        ],
                        [
                            'default' => 'weak_branch',
                        ],
                    ],
                ],
                'strong_branch' => [
                    'type' => 'message',
                    'text' => 'Отлично, этот формат вам подходит.',
                    'actions' => [
                        [
                            'type' => 'set_tag',
                            'value' => $strongTagSlug,
                        ],
                        [
                            'type' => 'remove_tag',
                            'value' => $weakTagSlug,
                        ],
                    ],
                    'next' => 'done',
                ],
                'weak_branch' => [
                    'type' => 'message',
                    'text' => 'Спасибо, пока это не ваш уровень бюджета.',
                    'actions' => [
                        [
                            'type' => 'set_tag',
                            'value' => $weakTagSlug,
                        ],
                    ],
                    'next' => 'done',
                ],
                'done' => [
                    'type' => 'complete',
                ],
            ],
        ];
    }
}
