<?php

namespace Tests\Feature;

use App\Data\Bitrix24\Bitrix24ContactSyncQueueResultData;
use App\Jobs\InferContactGenderFromFirstNameJob;
use App\Jobs\ProcessScenarioInboundJob;
use App\Jobs\ProcessScenarioStartJob;
use App\Jobs\ProcessScenarioV3ScheduledTransitionJob;
use App\Models\AutoReplyRule;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\ContactPhoneNumber;
use App\Models\Dialog;
use App\Models\Message;
use App\Models\Scenario;
use App\Models\ScenarioBuilderBlock;
use App\Models\ScenarioBuilderCondition;
use App\Models\ScenarioBuilderEdge;
use App\Models\ScenarioChannelBinding;
use App\Models\ScenarioRun;
use App\Models\ScenarioV3ScheduledTransition;
use App\Models\ScenarioVersion;
use App\Models\Tag;
use App\Services\Bitrix24\QueueBitrix24ContactSyncAction;
use App\Services\DataCollection\ExtractFirstNameAction;
use App\Services\Scenarios\DispatchStoredInboundScenarioAction;
use App\Services\Scenarios\ScenarioRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
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
            && $request['text'] === 'Спасибо, телефон получен');
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
            && data_get($request->data(), 'reply_markup.inline_keyboard.0.0.callback_data') === 'v3b:btn_catalog'
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

    public function test_v3_reply_keyboard_buttons_are_hidden_for_max_but_manual_text_advances(): void
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
            'v3_max_hidden_reply_keyboard',
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
            && ! array_key_exists('attachments', $request->data()));

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
                        'source_block_id' => 'start',
                        'target_block_id' => $edge['target_block_id'],
                        'from_output_id' => null,
                        'label' => $edge['label'] ?? '',
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
    ): array {
        return [
            'id' => $id,
            'edge_key' => $edgeKey,
            'mode' => 'wait_reply',
            'priority' => $priority,
            'transition_limit' => $transitionLimit,
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
    ): void {
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
        ]);

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
