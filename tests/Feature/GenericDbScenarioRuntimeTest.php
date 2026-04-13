<?php

namespace Tests\Feature;

use App\Jobs\InferContactGenderFromFirstNameJob;
use App\Jobs\ProcessScenarioInboundJob;
use App\Jobs\ProcessScenarioStartJob;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Dialog;
use App\Models\Message;
use App\Models\Scenario;
use App\Models\ScenarioChannelBinding;
use App\Models\ScenarioRun;
use App\Models\ScenarioVersion;
use App\Models\Tag;
use App\Services\Bitrix24\QueueBitrix24ContactSyncAction;
use App\Services\DataCollection\ExtractFirstNameAction;
use App\Services\Scenarios\ScenarioRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;
use Tests\Feature\Concerns\BuildsIbizaMvpSchema;

class GenericDbScenarioRuntimeTest extends TestCase
{
    use RefreshDatabase;
    use BuildsIbizaMvpSchema;

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

        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === $dialog->external_chat_id
            && $request['text'] === 'Добро пожаловать в сценарий.');
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === $dialog->external_chat_id
            && $request['text'] === 'Как вас зовут?');
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
        Http::assertSentCount(3);
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

        Http::assertSentCount(2);
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
        Http::assertSentCount(3);
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
        Http::assertSentCount(2);
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
        Http::assertSentCount(3);
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
        Http::assertSentCount(3);
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
        Http::assertSentCount(1);
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
        Http::assertSentCount(10);
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
        Http::assertSentCount(9);
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
        Http::assertSentCount(8);
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
            ->with(Mockery::on(fn ($value): bool => $value instanceof Contact && $value->is($contact)));
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
            ->with(Mockery::on(fn ($value): bool => $value instanceof Contact && $value->is($contact)));
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
            ->with(Mockery::on(fn ($value): bool => $value instanceof Contact && $value->is($contact)));
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
        $this->assertSame('Юля', data_get($run->state_payload, 'run.first_name'));
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

    /**
     * @return array{0: Contact, 1: ContactIdentity, 2: Dialog}
     */
    private function createDialogContext(
        Channel $channel,
        array $contactOverrides = [],
        array $identityOverrides = [],
        array $dialogOverrides = [],
    ): array
    {
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
