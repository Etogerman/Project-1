<?php

namespace Tests\Feature;

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
use App\Services\Scenarios\ScenarioRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GenericDbScenarioRuntimeTest extends TestCase
{
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
    private function createDialogContext(Channel $channel): array
    {
        $contact = Contact::factory()->create([
            'is_auto_reply_enabled' => true,
        ]);

        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'telegram-user-500',
        ]);

        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'telegram-chat-700',
        ]);

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
