<?php

namespace Tests\Feature;

use App\Jobs\ProcessAutoReplyJob;
use App\Models\AutoReplyRule;
use App\Models\AutoReplyRuleTagCondition;
use App\Models\AutoReplyRuleTagEffect;
use App\Models\Channel;
use App\Models\ChannelRuntimeState;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\ContactPhoneNumber;
use App\Models\Dialog;
use App\Models\Message;
use App\Models\Scenario;
use App\Models\ScenarioBuilderBlock;
use App\Models\ScenarioBuilderCondition;
use App\Models\ScenarioVersion;
use App\Models\Tag;
use App\Models\TelegramAccountOutgoingMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProcessAutoReplyJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_sends_telegram_auto_reply_and_creates_outbound_message(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 9001,
                ],
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'auto_reply_mode' => Channel::AUTO_REPLY_MODE_RULES_ONLY,
            'credentials' => [
                'token' => 'telegram-token',
            ],
        ]);

        $rule = AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'keyword' => null,
            'normalized_keyword' => null,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
            'reply_text' => 'Тестовый queued ответ.',
            'is_active' => true,
        ]);

        $message = $this->createInboundMessage($channel, [
            'external_chat_id' => '300',
            'external_message_id' => '10',
            'provider_event_key' => 'telegram-10',
        ], [
            'external_user_id' => '200',
            'external_username' => 'telegram_user',
        ]);

        ProcessAutoReplyJob::dispatchSync($message->id);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
                && $request['chat_id'] === '300'
                && $request['text'] === 'Тестовый queued ответ.';
        });

        $message->refresh();
        $channel->refresh();
        $outboundMessage = Message::query()
            ->where('reply_to_message_id', $message->id)
            ->where('message_kind', Message::KIND_OUTBOUND_AUTO_REPLY)
            ->firstOrFail();

        $this->assertNotNull($message->auto_reply_sent_at);
        $this->assertNotNull($channel->last_reply_sent_at);
        $this->assertNull($channel->last_error_at);
        $this->assertSame(Channel::AUTO_REPLY_MODE_RULES_ONLY, $channel->auto_reply_mode);
        $this->assertNotNull($outboundMessage->dialog_id);
        $this->assertSame(Message::SENT_BY_TYPE_AUTO_REPLY, $outboundMessage->sent_by_type);
        $this->assertSame(Message::SENT_BY_SYSTEM_CODE_AUTO_REPLY_RULE, $outboundMessage->sent_by_system_code);
        $this->assertDatabaseHas('messages', [
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_AUTO_REPLY,
            'reply_to_message_id' => $message->id,
            'external_message_id' => '9001',
            'text' => 'Тестовый queued ответ.',
        ]);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'bot.reply_rule_matched',
            'level' => 'info',
        ]);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'bot.reply_sent',
            'level' => 'info',
        ]);
        $matchedLog = $channel->activityLogs()->where('event', 'bot.reply_rule_matched')->latest('id')->firstOrFail();
        $sentLog = $channel->activityLogs()->where('event', 'bot.reply_sent')->latest('id')->firstOrFail();

        $this->assertSame($rule->display_name, $matchedLog->context['rule_name']);
        $this->assertSame($rule->display_name, $sentLog->context['rule_name']);
    }

    public function test_job_queues_telegram_account_auto_reply_for_gateway(): void
    {
        Http::fake();

        $channel = $this->readyTelegramAccountChannel();

        AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'keyword' => null,
            'normalized_keyword' => null,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
            'reply_text' => 'Ответ через Gateway',
            'is_active' => true,
        ]);

        $message = $this->createInboundDialogMessage($channel, [
            'provider_event_key' => 'telegram-account-auto-reply',
            'text' => 'любой текст',
        ]);

        ProcessAutoReplyJob::dispatchSync($message->id);

        Http::assertNothingSent();

        $message->refresh();
        $channel->refresh();

        $outgoing = TelegramAccountOutgoingMessage::query()->firstOrFail();
        $outboundMessage = Message::query()
            ->where('reply_to_message_id', $message->id)
            ->where('sent_by_system_code', Message::SENT_BY_SYSTEM_CODE_AUTO_REPLY_RULE)
            ->firstOrFail();

        $this->assertNotNull($message->auto_reply_sent_at);
        $this->assertNull($channel->last_reply_sent_at);
        $this->assertSame(Message::KIND_OUTBOUND_AUTO_REPLY, $outboundMessage->message_kind);
        $this->assertSame(Message::SENT_BY_TYPE_AUTO_REPLY, $outboundMessage->sent_by_type);
        $this->assertSame('Ответ через Gateway', $outboundMessage->text);
        $this->assertSame('telegram_account_gateway', data_get($outboundMessage->raw_payload, 'provider'));
        $this->assertSame(TelegramAccountOutgoingMessage::STATUS_PENDING, data_get($outboundMessage->raw_payload, 'delivery_status'));
        $this->assertSame($outboundMessage->id, $outgoing->message_id);
        $this->assertSame(TelegramAccountOutgoingMessage::STATUS_PENDING, $outgoing->status);
        $this->assertSame('Ответ через Gateway', $outgoing->text);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'bot.reply_queued',
            'level' => 'info',
        ]);
    }

    public function test_job_queues_telegram_account_auto_reply_without_button_until_gateway_supports_buttons(): void
    {
        Http::fake();

        $channel = $this->readyTelegramAccountChannel();
        $tag = Tag::factory()->create();

        $rule = AutoReplyRule::factory()
            ->forChannel($channel)
            ->create([
                'channel_id' => $channel->id,
                'keyword' => null,
                'normalized_keyword' => null,
                'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
                'reply_text' => 'Ответ с кнопкой через Gateway',
                'is_active' => true,
            ]);
        $rule->channels()->sync([
            $channel->id => [
                'button_type' => AutoReplyRule::BUTTON_TYPE_INLINE_KEYBOARD,
                'button_text' => 'Открыть форму',
                'button_url' => 'https://example.com/form',
            ],
        ]);
        AutoReplyRuleTagEffect::query()->create([
            'auto_reply_rule_id' => $rule->id,
            'tag_id' => $tag->id,
            'effect' => AutoReplyRuleTagEffect::EFFECT_ADD,
        ]);

        $message = $this->createInboundDialogMessage($channel, [
            'provider_event_key' => 'telegram-account-auto-reply-button',
            'text' => 'любой текст',
        ]);

        ProcessAutoReplyJob::dispatchSync($message->id);

        Http::assertNothingSent();

        $message->refresh();

        $outgoing = TelegramAccountOutgoingMessage::query()->firstOrFail();
        $outboundMessage = Message::query()
            ->where('reply_to_message_id', $message->id)
            ->where('sent_by_system_code', Message::SENT_BY_SYSTEM_CODE_AUTO_REPLY_RULE)
            ->firstOrFail();

        $this->assertNotNull($message->auto_reply_sent_at);
        $this->assertSame('Ответ с кнопкой через Gateway', $outboundMessage->text);
        $this->assertSame('telegram_account_gateway', data_get($outboundMessage->raw_payload, 'provider'));
        $this->assertSame(TelegramAccountOutgoingMessage::STATUS_PENDING, data_get($outboundMessage->raw_payload, 'delivery_status'));
        $this->assertNull(data_get($outboundMessage->raw_payload, 'button_type'));
        $this->assertSame($outboundMessage->id, $outgoing->message_id);
        $this->assertSame('Ответ с кнопкой через Gateway', $outgoing->text);
        $this->assertDatabaseHas('messages', [
            'reply_to_message_id' => $message->id,
            'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_AUTO_REPLY_RULE,
        ]);
        $this->assertDatabaseHas('contact_tag', [
            'contact_id' => $message->contact_id,
            'tag_id' => $tag->id,
        ]);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'bot.reply_gateway_button_omitted',
            'level' => 'warning',
        ]);

        $log = $channel->activityLogs()
            ->where('event', 'bot.reply_gateway_button_omitted')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(AutoReplyRule::BUTTON_TYPE_INLINE_KEYBOARD, $log->context['button_type']);
        $this->assertSame($rule->id, $log->context['rule_id']);
    }

    public function test_job_keeps_draft_scenario_builder_out_of_live_auto_reply_runtime(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 9101,
                ],
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'auto_reply_mode' => Channel::AUTO_REPLY_MODE_RULES_ONLY,
            'credentials' => [
                'token' => 'telegram-token',
            ],
        ]);
        $scenario = Scenario::query()->create([
            'code' => 'local_constructor',
            'name' => 'Локальный конструктор',
            'is_active' => true,
            'is_archived' => false,
        ]);
        $draftVersion = ScenarioVersion::query()->create([
            'scenario_id' => $scenario->id,
            'version_number' => 1,
            'status' => ScenarioVersion::STATUS_DRAFT,
            'schema_payload' => [],
        ]);
        $builderBlock = ScenarioBuilderBlock::query()->create([
            'scenario_version_id' => $draftVersion->id,
            'type' => ScenarioBuilderBlock::TYPE_START_CONDITION,
            'title' => 'Тестовый блок',
            'position_x' => 120,
            'position_y' => 140,
            'settings_payload' => [
                'condition' => [
                    'match' => AutoReplyRule::MATCH_SCOPE_CONTAINS_TEXT,
                    'variable' => ScenarioBuilderCondition::VARIABLE_MESSAGE_PARAMETER,
                ],
                'message_text' => 'Ответ из конструктора.',
            ],
        ]);
        $builderBlock->channels()->sync([$channel->id]);
        ScenarioBuilderCondition::query()->create([
            'scenario_builder_block_id' => $builderBlock->id,
            'type' => ScenarioBuilderCondition::TYPE_MESSAGE_PARAMETER,
            'match_operator' => AutoReplyRule::MATCH_SCOPE_CONTAINS_TEXT,
            'variable' => ScenarioBuilderCondition::VARIABLE_MESSAGE_PARAMETER,
            'value' => 'тест',
            'sort_order' => 1,
        ]);
        AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_CONTAINS_TEXT,
            'keyword' => 'тест',
            'normalized_keyword' => AutoReplyRule::normalizeKeyword('тест'),
            'reply_text' => 'Ответ обычного правила.',
            'is_active' => true,
        ]);

        $message = $this->createInboundMessage($channel, [
            'text' => 'это тестовое сообщение',
            'external_chat_id' => '300',
            'external_message_id' => '11',
            'provider_event_key' => 'telegram-11',
        ], [
            'external_user_id' => '200',
            'external_username' => 'telegram_user',
        ]);

        ProcessAutoReplyJob::dispatchSync($message->id);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
                && $request['chat_id'] === '300'
                && $request['text'] === 'Ответ обычного правила.';
        });

        $message->refresh();
        $channel->refresh();

        $this->assertNotNull($message->auto_reply_sent_at);
        $this->assertNotNull($channel->last_reply_sent_at);
        $this->assertDatabaseHas('messages', [
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_AUTO_REPLY,
            'reply_to_message_id' => $message->id,
            'external_message_id' => '9101',
            'sent_by_type' => Message::SENT_BY_TYPE_AUTO_REPLY,
            'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_AUTO_REPLY_RULE,
            'text' => 'Ответ обычного правила.',
        ]);
        $this->assertDatabaseMissing('messages', [
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_SCENARIO_MESSAGE,
            'reply_to_message_id' => $message->id,
            'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_SCENARIO_BUILDER_START_CONDITION,
            'text' => 'Ответ из конструктора.',
        ]);
        $this->assertDatabaseMissing('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'bot.scenario_builder_start_condition_matched',
        ]);
    }

    public function test_job_sends_max_auto_reply_and_creates_outbound_message(): void
    {
        Http::fake([
            'https://platform-api.max.ru/*' => Http::response([
                'message' => [
                    'message_id' => 'max-out-9001',
                ],
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'auto_reply_mode' => Channel::AUTO_REPLY_MODE_RULES_ONLY,
            'credentials' => [
                'token' => 'max-token',
            ],
        ]);

        AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'keyword' => null,
            'normalized_keyword' => null,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
            'reply_text' => 'MAX queued ответ.',
            'is_active' => true,
        ]);

        $message = $this->createInboundMessage($channel, [
            'external_chat_id' => '700',
            'external_message_id' => 'max-10',
            'provider_event_key' => 'max-10',
        ], [
            'external_user_id' => '500',
            'external_username' => 'max_user',
        ]);

        ProcessAutoReplyJob::dispatchSync($message->id);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://platform-api.max.ru/messages?chat_id=700'
                && $request->hasHeader('Authorization', 'max-token')
                && $request['text'] === 'MAX queued ответ.';
        });

        $message->refresh();

        $this->assertNotNull($message->auto_reply_sent_at);
        $this->assertDatabaseHas('messages', [
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_AUTO_REPLY,
            'reply_to_message_id' => $message->id,
            'external_message_id' => 'max-out-9001',
        ]);
    }

    public function test_job_assigns_configured_tags_after_successful_auto_reply(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 9201,
                ],
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'auto_reply_mode' => Channel::AUTO_REPLY_MODE_RULES_ONLY,
            'credentials' => [
                'token' => 'telegram-token',
            ],
        ]);
        $assignedTag = Tag::factory()->create([
            'name' => 'VIP',
            'color' => Tag::COLOR_SUCCESS,
        ]);

        $rule = AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'keyword' => null,
            'normalized_keyword' => null,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
            'reply_text' => 'Автоответ с тегом.',
            'is_active' => true,
        ]);
        AutoReplyRuleTagEffect::query()->create([
            'auto_reply_rule_id' => $rule->id,
            'tag_id' => $assignedTag->id,
            'effect' => AutoReplyRuleTagEffect::EFFECT_ASSIGN,
        ]);

        $message = $this->createInboundMessage($channel, [
            'provider_event_key' => 'telegram-tag-assign',
        ]);

        ProcessAutoReplyJob::dispatchSync($message->id);

        $this->assertDatabaseHas('contact_tag', [
            'contact_id' => $message->contact_id,
            'tag_id' => $assignedTag->id,
            'assigned_by_user_id' => null,
        ]);
    }

    public function test_job_logs_custom_rule_name_in_failure_context(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => false,
            ], 500),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
            ],
        ]);

        $rule = AutoReplyRule::factory()->create([
            'name' => 'VIP fallback',
            'channel_id' => $channel->id,
            'keyword' => null,
            'normalized_keyword' => null,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
            'reply_text' => 'Ответ, который сломается на отправке',
            'is_active' => true,
        ]);

        $message = $this->createInboundMessage($channel, [
            'provider_event_key' => 'telegram-failure-rule-name',
        ]);

        try {
            ProcessAutoReplyJob::dispatchSync($message->id);
            $this->fail('Expected auto reply job to throw on failed delivery.');
        } catch (\Throwable) {
        }

        $failedLog = $channel->activityLogs()->where('event', 'bot.reply_failed')->latest('id')->firstOrFail();

        $this->assertSame($rule->id, $failedLog->context['rule_id']);
        $this->assertSame('VIP fallback', $failedLog->context['rule_name']);
    }

    public function test_job_removes_configured_tags_after_successful_auto_reply(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 9202,
                ],
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'auto_reply_mode' => Channel::AUTO_REPLY_MODE_RULES_ONLY,
            'credentials' => [
                'token' => 'telegram-token',
            ],
        ]);
        $tagToRemove = Tag::factory()->create([
            'name' => 'Новый',
            'color' => Tag::COLOR_WARNING,
        ]);

        $rule = AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'keyword' => null,
            'normalized_keyword' => null,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
            'reply_text' => 'Снимаем тег.',
            'is_active' => true,
        ]);
        AutoReplyRuleTagEffect::query()->create([
            'auto_reply_rule_id' => $rule->id,
            'tag_id' => $tagToRemove->id,
            'effect' => AutoReplyRuleTagEffect::EFFECT_REMOVE,
        ]);

        $message = $this->createInboundMessage($channel, [
            'provider_event_key' => 'telegram-tag-remove',
        ]);
        Contact::query()->findOrFail($message->contact_id)->tags()->attach($tagToRemove->id, [
            'assigned_at' => now(),
            'assigned_by_user_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        ProcessAutoReplyJob::dispatchSync($message->id);

        $this->assertDatabaseMissing('contact_tag', [
            'contact_id' => $message->contact_id,
            'tag_id' => $tagToRemove->id,
        ]);
    }

    public function test_job_can_replace_old_tag_with_new_one_after_successful_auto_reply(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 9203,
                ],
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'auto_reply_mode' => Channel::AUTO_REPLY_MODE_RULES_ONLY,
            'credentials' => [
                'token' => 'telegram-token',
            ],
        ]);
        $oldTag = Tag::factory()->create([
            'name' => 'Новый клиент',
            'color' => Tag::COLOR_WARNING,
        ]);
        $newTag = Tag::factory()->create([
            'name' => 'Прогретый',
            'color' => Tag::COLOR_SUCCESS,
        ]);

        $rule = AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'keyword' => null,
            'normalized_keyword' => null,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
            'reply_text' => 'Меняем сегмент.',
            'is_active' => true,
        ]);
        AutoReplyRuleTagEffect::query()->insert([
            [
                'auto_reply_rule_id' => $rule->id,
                'tag_id' => $newTag->id,
                'effect' => AutoReplyRuleTagEffect::EFFECT_ASSIGN,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'auto_reply_rule_id' => $rule->id,
                'tag_id' => $oldTag->id,
                'effect' => AutoReplyRuleTagEffect::EFFECT_REMOVE,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $message = $this->createInboundMessage($channel, [
            'provider_event_key' => 'telegram-tag-replace',
        ]);
        Contact::query()->findOrFail($message->contact_id)->tags()->attach($oldTag->id, [
            'assigned_at' => now(),
            'assigned_by_user_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        ProcessAutoReplyJob::dispatchSync($message->id);

        $this->assertDatabaseMissing('contact_tag', [
            'contact_id' => $message->contact_id,
            'tag_id' => $oldTag->id,
        ]);
        $this->assertDatabaseHas('contact_tag', [
            'contact_id' => $message->contact_id,
            'tag_id' => $newTag->id,
        ]);
    }

    public function test_job_does_not_duplicate_tag_effects_on_repeat_dispatch(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 9204,
                ],
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'auto_reply_mode' => Channel::AUTO_REPLY_MODE_RULES_ONLY,
            'credentials' => [
                'token' => 'telegram-token',
            ],
        ]);
        $assignedTag = Tag::factory()->create([
            'name' => 'Повторный',
            'color' => Tag::COLOR_PRIMARY,
        ]);

        $rule = AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'keyword' => null,
            'normalized_keyword' => null,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
            'reply_text' => 'Ретрай без дублей.',
            'is_active' => true,
        ]);
        AutoReplyRuleTagEffect::query()->create([
            'auto_reply_rule_id' => $rule->id,
            'tag_id' => $assignedTag->id,
            'effect' => AutoReplyRuleTagEffect::EFFECT_ASSIGN,
        ]);

        $message = $this->createInboundMessage($channel, [
            'provider_event_key' => 'telegram-tag-repeat',
        ]);

        ProcessAutoReplyJob::dispatchSync($message->id);
        ProcessAutoReplyJob::dispatchSync($message->id);

        $this->assertDatabaseCount('contact_tag', 1);
        $this->assertDatabaseCount('messages', 2);
        $this->assertDatabaseHas('contact_tag', [
            'contact_id' => $message->contact_id,
            'tag_id' => $assignedTag->id,
        ]);
    }

    public function test_job_sends_all_matching_rules_in_priority_order(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push([
                    'ok' => true,
                    'result' => [
                        'message_id' => 9301,
                    ],
                ])
                ->push([
                    'ok' => true,
                    'result' => [
                        'message_id' => 9302,
                    ],
                ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'auto_reply_mode' => Channel::AUTO_REPLY_MODE_RULES_ONLY,
            'credentials' => [
                'token' => 'telegram-token',
            ],
        ]);

        $secondRule = AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD,
            'keyword' => 'Мульти',
            'normalized_keyword' => AutoReplyRule::normalizeKeyword('Мульти'),
            'reply_text' => 'Второй ответ',
            'priority' => 20,
            'is_active' => true,
        ]);

        $firstRule = AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
            'keyword' => null,
            'normalized_keyword' => null,
            'reply_text' => 'Первый ответ',
            'priority' => 5,
            'is_active' => true,
        ]);

        $message = $this->createInboundMessage($channel, [
            'provider_event_key' => 'telegram-multi-rule',
            'text' => 'мульти',
        ]);

        ProcessAutoReplyJob::dispatchSync($message->id);

        Http::assertSentCount(2);

        $this->assertSame(
            ['Первый ответ', 'Второй ответ'],
            Message::query()
                ->where('direction', Message::DIRECTION_OUTBOUND)
                ->where('reply_to_message_id', $message->id)
                ->orderBy('id')
                ->pluck('text')
                ->all(),
        );

        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'bot.reply_sent',
        ]);
        $this->assertDatabaseCount('messages', 3);
        $this->assertSame([$firstRule->id, $secondRule->id], [$firstRule->id, $secondRule->id]);
    }

    public function test_job_logs_actual_failed_rule_after_partial_multi_rule_success(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push([
                    'ok' => true,
                    'result' => [
                        'message_id' => 9401,
                    ],
                ])
                ->push([
                    'ok' => false,
                ], 500),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'auto_reply_mode' => Channel::AUTO_REPLY_MODE_RULES_ONLY,
            'credentials' => [
                'token' => 'telegram-token',
            ],
        ]);
        $vipTag = Tag::factory()->create([
            'name' => 'VIP',
            'color' => Tag::COLOR_SUCCESS,
        ]);

        $firstRule = AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
            'keyword' => null,
            'normalized_keyword' => null,
            'reply_text' => 'Первый ответ',
            'priority' => 5,
            'is_active' => true,
        ]);
        AutoReplyRuleTagEffect::query()->create([
            'auto_reply_rule_id' => $firstRule->id,
            'tag_id' => $vipTag->id,
            'effect' => AutoReplyRuleTagEffect::EFFECT_REMOVE,
        ]);

        $secondRule = AutoReplyRule::factory()
            ->forChannel($channel)
            ->create([
                'channel_id' => $channel->id,
                'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD,
                'keyword' => 'Мульти',
                'normalized_keyword' => AutoReplyRule::normalizeKeyword('Мульти'),
                'reply_text' => 'Второй ответ',
                'priority' => 20,
                'is_active' => true,
            ]);
        $secondRule->channels()->sync([
            $channel->id => [
                'button_type' => AutoReplyRule::BUTTON_TYPE_INLINE_KEYBOARD,
                'button_text' => 'Открыть форму',
                'button_url' => 'https://example.com/form',
            ],
        ]);
        AutoReplyRuleTagCondition::query()->create([
            'auto_reply_rule_id' => $secondRule->id,
            'tag_id' => $vipTag->id,
            'condition' => AutoReplyRuleTagCondition::CONDITION_REQUIRED,
        ]);

        $message = $this->createInboundMessage($channel, [
            'provider_event_key' => 'telegram-multi-fail-log',
            'text' => 'мульти',
        ]);

        Contact::query()->findOrFail($message->contact_id)->tags()->attach($vipTag->id, [
            'assigned_at' => now(),
            'assigned_by_user_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            ProcessAutoReplyJob::dispatchSync($message->id);
            $this->fail('Expected auto reply job to throw on second delivery failure.');
        } catch (\Throwable) {
        }

        $failedLog = $channel->activityLogs()
            ->where('event', 'bot.reply_failed')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('rule', $failedLog->context['auto_reply_source']);
        $this->assertSame(AutoReplyRule::BUTTON_TYPE_INLINE_KEYBOARD, $failedLog->context['button_type']);
        $this->assertSame($secondRule->id, $failedLog->context['rule_id']);
        $this->assertSame(AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD, $failedLog->context['match_scope']);
        $this->assertDatabaseMissing('contact_tag', [
            'contact_id' => $message->contact_id,
            'tag_id' => $vipTag->id,
        ]);
        $this->assertDatabaseHas('messages', [
            'direction' => Message::DIRECTION_OUTBOUND,
            'reply_to_message_id' => $message->id,
            'text' => 'Первый ответ',
        ]);
    }

    public function test_retry_after_partial_multi_rule_success_resumes_remaining_rules_without_duplicating_first_reply(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push([
                    'ok' => true,
                    'result' => [
                        'message_id' => 9501,
                    ],
                ])
                ->push([
                    'ok' => false,
                ], 500)
                ->push([
                    'ok' => true,
                    'result' => [
                        'message_id' => 9502,
                    ],
                ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'auto_reply_mode' => Channel::AUTO_REPLY_MODE_RULES_ONLY,
            'credentials' => [
                'token' => 'telegram-token',
            ],
        ]);

        AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
            'keyword' => null,
            'normalized_keyword' => null,
            'reply_text' => 'Первый ответ',
            'priority' => 5,
            'is_active' => true,
        ]);

        $secondRule = AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD,
            'keyword' => 'Мульти',
            'normalized_keyword' => AutoReplyRule::normalizeKeyword('Мульти'),
            'reply_text' => 'Второй ответ',
            'priority' => 20,
            'is_active' => true,
        ]);

        $message = $this->createInboundMessage($channel, [
            'provider_event_key' => 'telegram-partial-multi-resume',
            'text' => 'мульти',
        ]);

        try {
            ProcessAutoReplyJob::dispatchSync($message->id);
            $this->fail('Expected first attempt to fail on the second matched rule.');
        } catch (\Throwable) {
        }

        ProcessAutoReplyJob::dispatchSync($message->id);
        ProcessAutoReplyJob::dispatchSync($message->id);

        Http::assertSentCount(3);

        $outboundTexts = Message::query()
            ->where('direction', Message::DIRECTION_OUTBOUND)
            ->where('reply_to_message_id', $message->id)
            ->orderBy('id')
            ->pluck('text')
            ->all();

        $this->assertSame(['Первый ответ', 'Второй ответ'], $outboundTexts);

        $failedLog = $channel->activityLogs()
            ->where('event', 'bot.reply_failed')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame([$secondRule->id], $failedLog->context['remaining_rule_ids']);

        $resumeCompletedLog = $channel->activityLogs()
            ->where('event', 'bot.reply_resume_completed')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($failedLog->id, $resumeCompletedLog->context['resume_failure_log_id']);
        $this->assertSame([$secondRule->id], $resumeCompletedLog->context['remaining_rule_ids']);
    }

    public function test_retry_after_partial_multi_rule_success_does_not_resume_when_contact_auto_reply_was_disabled(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push([
                    'ok' => true,
                    'result' => [
                        'message_id' => 9601,
                    ],
                ])
                ->push([
                    'ok' => false,
                ], 500),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'auto_reply_mode' => Channel::AUTO_REPLY_MODE_RULES_ONLY,
            'credentials' => [
                'token' => 'telegram-token',
            ],
        ]);

        AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
            'keyword' => null,
            'normalized_keyword' => null,
            'reply_text' => 'Первый ответ',
            'priority' => 5,
            'is_active' => true,
        ]);

        AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD,
            'keyword' => 'Мульти',
            'normalized_keyword' => AutoReplyRule::normalizeKeyword('Мульти'),
            'reply_text' => 'Второй ответ',
            'priority' => 20,
            'is_active' => true,
        ]);

        $message = $this->createInboundMessage($channel, [
            'provider_event_key' => 'telegram-partial-multi-disabled-before-retry',
            'text' => 'мульти',
        ]);

        try {
            ProcessAutoReplyJob::dispatchSync($message->id);
            $this->fail('Expected first attempt to fail on the second matched rule.');
        } catch (\Throwable) {
        }

        $message->contact->update([
            'is_auto_reply_enabled' => false,
        ]);

        ProcessAutoReplyJob::dispatchSync($message->id);

        Http::assertSentCount(2);

        $outboundTexts = Message::query()
            ->where('direction', Message::DIRECTION_OUTBOUND)
            ->where('reply_to_message_id', $message->id)
            ->orderBy('id')
            ->pluck('text')
            ->all();

        $this->assertSame(['Первый ответ'], $outboundTexts);
        $this->assertDatabaseMissing('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'bot.reply_resume_completed',
        ]);
    }

    public function test_retry_after_partial_multi_rule_success_does_not_resume_when_contact_enters_data_collection(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push([
                    'ok' => true,
                    'result' => [
                        'message_id' => 9701,
                    ],
                ])
                ->push([
                    'ok' => false,
                ], 500),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'auto_reply_mode' => Channel::AUTO_REPLY_MODE_RULES_ONLY,
            'credentials' => [
                'token' => 'telegram-token',
            ],
        ]);

        AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
            'keyword' => null,
            'normalized_keyword' => null,
            'reply_text' => 'Первый ответ',
            'priority' => 5,
            'is_active' => true,
        ]);

        AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD,
            'keyword' => 'Мульти',
            'normalized_keyword' => AutoReplyRule::normalizeKeyword('Мульти'),
            'reply_text' => 'Второй ответ',
            'priority' => 20,
            'is_active' => true,
        ]);

        $message = $this->createInboundMessage($channel, [
            'provider_event_key' => 'telegram-partial-multi-collector-before-retry',
            'text' => 'мульти',
        ]);

        try {
            ProcessAutoReplyJob::dispatchSync($message->id);
            $this->fail('Expected first attempt to fail on the second matched rule.');
        } catch (\Throwable) {
        }

        $message->contact->update([
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_ACTIVE,
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_FIRST_NAME,
            'data_collection_started_at' => now(),
        ]);

        ProcessAutoReplyJob::dispatchSync($message->id);

        Http::assertSentCount(2);

        $outboundTexts = Message::query()
            ->where('direction', Message::DIRECTION_OUTBOUND)
            ->where('reply_to_message_id', $message->id)
            ->orderBy('id')
            ->pluck('text')
            ->all();

        $this->assertSame(['Первый ответ'], $outboundTexts);
        $this->assertDatabaseMissing('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'bot.reply_resume_completed',
        ]);
    }

    public function test_retry_after_repeated_partial_failures_resumes_only_latest_remaining_rules_once(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push([
                    'ok' => true,
                    'result' => [
                        'message_id' => 9801,
                    ],
                ])
                ->push([
                    'ok' => false,
                ], 500)
                ->push([
                    'ok' => true,
                    'result' => [
                        'message_id' => 9802,
                    ],
                ])
                ->push([
                    'ok' => false,
                ], 500)
                ->push([
                    'ok' => true,
                    'result' => [
                        'message_id' => 9803,
                    ],
                ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'auto_reply_mode' => Channel::AUTO_REPLY_MODE_RULES_ONLY,
            'credentials' => [
                'token' => 'telegram-token',
            ],
        ]);

        AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
            'keyword' => null,
            'normalized_keyword' => null,
            'reply_text' => 'Первый ответ',
            'priority' => 5,
            'is_active' => true,
        ]);

        $secondRule = AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD,
            'keyword' => 'Мульти',
            'normalized_keyword' => AutoReplyRule::normalizeKeyword('Мульти'),
            'reply_text' => 'Второй ответ',
            'priority' => 20,
            'is_active' => true,
        ]);

        $thirdRule = AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD,
            'keyword' => 'Мульти',
            'normalized_keyword' => AutoReplyRule::normalizeKeyword('Мульти'),
            'reply_text' => 'Третий ответ',
            'priority' => 30,
            'is_active' => true,
        ]);

        $message = $this->createInboundMessage($channel, [
            'provider_event_key' => 'telegram-partial-multi-repeat-failure-chain',
            'text' => 'мульти',
        ]);

        try {
            ProcessAutoReplyJob::dispatchSync($message->id);
            $this->fail('Expected first attempt to fail on the second matched rule.');
        } catch (\Throwable) {
        }

        try {
            ProcessAutoReplyJob::dispatchSync($message->id);
            $this->fail('Expected second attempt to fail on the third matched rule.');
        } catch (\Throwable) {
        }

        ProcessAutoReplyJob::dispatchSync($message->id);
        ProcessAutoReplyJob::dispatchSync($message->id);

        Http::assertSentCount(5);

        $outboundTexts = Message::query()
            ->where('direction', Message::DIRECTION_OUTBOUND)
            ->where('reply_to_message_id', $message->id)
            ->orderBy('id')
            ->pluck('text')
            ->all();

        $this->assertSame(['Первый ответ', 'Второй ответ', 'Третий ответ'], $outboundTexts);

        $failedLogs = $channel->activityLogs()
            ->where('event', 'bot.reply_failed')
            ->orderBy('id')
            ->get()
            ->values();

        $this->assertCount(2, $failedLogs);
        $this->assertSame([$secondRule->id, $thirdRule->id], $failedLogs[0]->context['remaining_rule_ids']);
        $this->assertSame([$thirdRule->id], $failedLogs[1]->context['remaining_rule_ids']);

        $resumeCompletedLog = $channel->activityLogs()
            ->where('event', 'bot.reply_resume_completed')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($failedLogs[1]->id, $resumeCompletedLog->context['resume_failure_log_id']);
        $this->assertSame([$thirdRule->id], $resumeCompletedLog->context['remaining_rule_ids']);
    }

    public function test_job_does_not_change_tags_when_delivery_fails(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => false,
            ], 500),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'auto_reply_mode' => Channel::AUTO_REPLY_MODE_RULES_ONLY,
            'credentials' => [
                'token' => 'telegram-token',
            ],
        ]);
        $assignedTag = Tag::factory()->create([
            'name' => 'Не должен назначиться',
            'color' => Tag::COLOR_DANGER,
        ]);

        $rule = AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'keyword' => null,
            'normalized_keyword' => null,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
            'reply_text' => 'Падение доставки.',
            'is_active' => true,
        ]);
        AutoReplyRuleTagEffect::query()->create([
            'auto_reply_rule_id' => $rule->id,
            'tag_id' => $assignedTag->id,
            'effect' => AutoReplyRuleTagEffect::EFFECT_ASSIGN,
        ]);

        $message = $this->createInboundMessage($channel, [
            'provider_event_key' => 'telegram-tag-fail',
        ]);

        try {
            ProcessAutoReplyJob::dispatchSync($message->id);
            $this->fail('Expected auto reply job to throw on failed delivery.');
        } catch (\Throwable) {
        }

        $message->refresh();

        $this->assertNull($message->auto_reply_sent_at);
        $this->assertDatabaseMissing('contact_tag', [
            'contact_id' => $message->contact_id,
            'tag_id' => $assignedTag->id,
        ]);
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_job_can_use_tags_from_previous_auto_reply_to_match_next_rule(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push([
                    'ok' => true,
                    'result' => [
                        'message_id' => 9301,
                    ],
                ])
                ->push([
                    'ok' => true,
                    'result' => [
                        'message_id' => 9302,
                    ],
                ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'auto_reply_mode' => Channel::AUTO_REPLY_MODE_RULES_ONLY,
            'credentials' => [
                'token' => 'telegram-token',
            ],
        ]);
        $warmTag = Tag::factory()->create([
            'name' => 'Прогретый',
            'color' => Tag::COLOR_SUCCESS,
        ]);

        $firstRule = AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD,
            'keyword' => 'старт',
            'normalized_keyword' => AutoReplyRule::normalizeKeyword('старт'),
            'reply_text' => 'Первый шаг.',
            'is_active' => true,
        ]);
        AutoReplyRuleTagEffect::query()->create([
            'auto_reply_rule_id' => $firstRule->id,
            'tag_id' => $warmTag->id,
            'effect' => AutoReplyRuleTagEffect::EFFECT_ASSIGN,
        ]);

        $secondRule = AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD,
            'keyword' => 'продолжить',
            'normalized_keyword' => AutoReplyRule::normalizeKeyword('продолжить'),
            'reply_text' => 'Второй шаг.',
            'is_active' => true,
        ]);
        AutoReplyRuleTagCondition::query()->create([
            'auto_reply_rule_id' => $secondRule->id,
            'tag_id' => $warmTag->id,
            'condition' => AutoReplyRuleTagCondition::CONDITION_REQUIRED,
        ]);

        $firstMessage = $this->createInboundMessage($channel, [
            'provider_event_key' => 'telegram-tag-chain-start',
            'text' => 'старт',
        ]);

        ProcessAutoReplyJob::dispatchSync($firstMessage->id);

        $contact = Contact::query()->findOrFail($firstMessage->contact_id);
        $identity = ContactIdentity::query()->findOrFail($firstMessage->contact_identity_id);

        $secondMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'reply_to_message_id' => null,
            'provider_event_key' => 'telegram-tag-chain-next',
            'external_chat_id' => $firstMessage->external_chat_id,
            'external_message_id' => '11',
            'text' => 'продолжить',
            'raw_payload' => ['message' => 'payload-2'],
            'received_at' => now()->addSecond(),
            'auto_reply_sent_at' => null,
        ]);

        ProcessAutoReplyJob::dispatchSync($secondMessage->id);

        Http::assertSent(fn ($request): bool => $request['text'] === 'Первый шаг.');
        Http::assertSent(fn ($request): bool => $request['text'] === 'Второй шаг.');
        $this->assertDatabaseHas('contact_tag', [
            'contact_id' => $contact->id,
            'tag_id' => $warmTag->id,
        ]);
        $this->assertDatabaseHas('messages', [
            'direction' => Message::DIRECTION_OUTBOUND,
            'reply_to_message_id' => $secondMessage->id,
            'text' => 'Второй шаг.',
        ]);
    }

    public function test_job_keeps_completion_marker_after_partial_success_failure_to_block_duplicate_rerun(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push([
                    'ok' => true,
                    'result' => [
                        'message_id' => 9205,
                    ],
                ])
                ->push([
                    'ok' => false,
                ], 500),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'auto_reply_mode' => Channel::AUTO_REPLY_MODE_RULES_ONLY,
            'credentials' => [
                'token' => 'telegram-token',
            ],
        ]);

        AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'keyword' => null,
            'normalized_keyword' => null,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
            'reply_text' => 'Первый успешный ответ.',
            'sort_order' => 10,
            'is_active' => true,
        ]);

        AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'keyword' => null,
            'normalized_keyword' => null,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
            'reply_text' => 'Второй ответ падает.',
            'sort_order' => 20,
            'is_active' => true,
        ]);

        $message = $this->createInboundMessage($channel, [
            'provider_event_key' => 'telegram-partial-success',
        ]);

        try {
            ProcessAutoReplyJob::dispatchSync($message->id);
            $this->fail('Expected auto reply job to throw after partial success.');
        } catch (\Throwable) {
        }

        $message->refresh();
        ProcessAutoReplyJob::dispatchSync($message->id);

        Http::assertSentCount(2);
        $this->assertNotNull($message->auto_reply_sent_at);
        $this->assertDatabaseCount('messages', 2);
        $this->assertDatabaseHas('messages', [
            'direction' => Message::DIRECTION_OUTBOUND,
            'reply_to_message_id' => $message->id,
            'text' => 'Первый успешный ответ.',
        ]);
    }

    public function test_job_uses_exact_match_rule_text_when_rule_exists(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 9101,
                ],
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'auto_reply_mode' => Channel::AUTO_REPLY_MODE_RULES_ONLY,
            'credentials' => [
                'token' => 'telegram-token',
            ],
        ]);

        $rule = AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'keyword' => 'Тест1',
            'normalized_keyword' => AutoReplyRule::normalizeKeyword('Тест1'),
            'reply_text' => 'Шаблон 1',
            'is_active' => true,
        ]);

        $message = $this->createInboundMessage($channel, [
            'provider_event_key' => 'telegram-rule',
            'text' => '  тЕст1  ',
        ]);

        ProcessAutoReplyJob::dispatchSync($message->id);

        Http::assertSent(function ($request): bool {
            return $request['text'] === 'Шаблон 1';
        });

        $this->assertDatabaseHas('messages', [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_AUTO_REPLY,
            'reply_to_message_id' => $message->id,
            'text' => 'Шаблон 1',
        ]);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'bot.reply_rule_matched',
            'level' => 'info',
        ]);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'bot.reply_sent',
            'level' => 'info',
        ]);

        $matchedLog = $channel->activityLogs()->where('event', 'bot.reply_rule_matched')->latest('id')->firstOrFail();
        $this->assertSame(Channel::AUTO_REPLY_MODE_RULES_ONLY, $matchedLog->context['auto_reply_mode']);
        $this->assertSame('rule', $matchedLog->context['auto_reply_source']);

        $this->assertTrue($rule->exists);
    }

    public function test_job_prefers_exact_parameter_rule_over_exact_text_rule(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 91015,
                ],
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'auto_reply_mode' => Channel::AUTO_REPLY_MODE_RULES_ONLY,
            'credentials' => [
                'token' => 'telegram-token',
            ],
        ]);

        AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD,
            'keyword' => '/start text_1',
            'normalized_keyword' => AutoReplyRule::normalizeKeyword('/start text_1'),
            'reply_text' => 'Text rule',
            'is_active' => true,
        ]);

        AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_PARAMETER,
            'keyword' => 'TEXT_1',
            'normalized_keyword' => AutoReplyRule::normalizeKeyword('TEXT_1'),
            'reply_text' => 'Parameter rule',
            'is_active' => true,
        ]);

        $message = $this->createInboundMessage($channel, [
            'provider_event_key' => 'telegram-parameter-rule',
            'text' => '/start TEXT_1',
            'message_parameter' => 'TEXT_1',
        ]);

        ProcessAutoReplyJob::dispatchSync($message->id);

        Http::assertSent(fn ($request): bool => $request['text'] === 'Parameter rule');
    }

    public function test_job_matches_contains_text_rule(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 91016,
                ],
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'auto_reply_mode' => Channel::AUTO_REPLY_MODE_RULES_ONLY,
            'credentials' => [
                'token' => 'telegram-token',
            ],
        ]);

        AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_CONTAINS_TEXT,
            'keyword' => 'скидка',
            'normalized_keyword' => AutoReplyRule::normalizeKeyword('скидка'),
            'reply_text' => 'Contains rule',
            'is_active' => true,
        ]);

        $message = $this->createInboundMessage($channel, [
            'provider_event_key' => 'telegram-contains-rule',
            'text' => 'Подскажите скидка действует?',
        ]);

        ProcessAutoReplyJob::dispatchSync($message->id);

        Http::assertSent(fn ($request): bool => $request['text'] === 'Contains rule');
    }

    public function test_job_sends_parameter_based_auto_reply_for_max_bot_started_message(): void
    {
        Http::fake([
            'https://platform-api.max.ru/*' => Http::response([
                'message' => [
                    'message_id' => 'max-out-9104',
                ],
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'auto_reply_mode' => Channel::AUTO_REPLY_MODE_RULES_ONLY,
            'credentials' => [
                'token' => 'max-token',
            ],
        ]);

        AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_PARAMETER,
            'keyword' => 'promo_123',
            'normalized_keyword' => AutoReplyRule::normalizeKeyword('promo_123'),
            'reply_text' => 'MAX parameter reply',
            'is_active' => true,
        ]);

        $message = $this->createInboundMessage($channel, [
            'external_chat_id' => '700',
            'external_message_id' => null,
            'provider_event_key' => 'max-bot-started:700:1',
            'text' => null,
            'message_parameter' => 'promo_123',
            'raw_payload' => [
                'update_type' => 'bot_started',
                'payload' => 'promo_123',
            ],
        ], [
            'external_user_id' => '500',
            'external_username' => 'max_user',
        ]);

        ProcessAutoReplyJob::dispatchSync($message->id);

        Http::assertSent(fn ($request): bool => $request['text'] === 'MAX parameter reply');

        $this->assertDatabaseHas('messages', [
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_AUTO_REPLY,
            'reply_to_message_id' => $message->id,
            'external_message_id' => 'max-out-9104',
        ]);
    }

    public function test_job_sends_parameter_based_auto_reply_for_max_bot_started_message_even_when_contact_is_in_data_collection(): void
    {
        Http::fake([
            'https://platform-api.max.ru/*' => Http::response([
                'message' => [
                    'message_id' => 'max-out-9105',
                ],
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'auto_reply_mode' => Channel::AUTO_REPLY_MODE_RULES_ONLY,
            'credentials' => [
                'token' => 'max-token',
            ],
        ]);

        AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_PARAMETER,
            'keyword' => 'promo_456',
            'normalized_keyword' => AutoReplyRule::normalizeKeyword('promo_456'),
            'reply_text' => 'Collector-safe MAX parameter reply',
            'is_active' => true,
        ]);

        $message = $this->createInboundMessage($channel, [
            'external_chat_id' => '701',
            'external_message_id' => null,
            'provider_event_key' => 'max-bot-started:701:1',
            'text' => null,
            'message_parameter' => 'promo_456',
            'raw_payload' => [
                'update_type' => 'bot_started',
                'payload' => 'promo_456',
            ],
        ], [
            'external_user_id' => '501',
            'external_username' => 'max_user_2',
        ], [
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_ACTIVE,
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_FIRST_NAME,
            'data_collection_started_at' => now(),
        ]);

        ProcessAutoReplyJob::dispatchSync($message->id);

        Http::assertSent(fn ($request): bool => $request['text'] === 'Collector-safe MAX parameter reply');
    }

    public function test_job_applies_has_phone_condition_for_exact_rule(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 9102,
                ],
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'auto_reply_mode' => Channel::AUTO_REPLY_MODE_RULES_ONLY,
            'credentials' => [
                'token' => 'telegram-token',
            ],
        ]);

        AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'keyword' => 'Тест1',
            'normalized_keyword' => AutoReplyRule::normalizeKeyword('Тест1'),
            'contact_phone_condition' => AutoReplyRule::CONTACT_PHONE_CONDITION_HAS_PHONE,
            'reply_text' => 'Привет!',
            'is_active' => true,
        ]);

        $message = $this->createInboundMessage($channel, [
            'provider_event_key' => 'telegram-rule-has-phone',
            'text' => 'Тест1',
        ]);

        ContactPhoneNumber::factory()->create([
            'contact_id' => $message->contact_id,
            'is_primary' => true,
        ]);

        ProcessAutoReplyJob::dispatchSync($message->id);

        Http::assertSent(fn ($request): bool => $request['text'] === 'Привет!');
        $this->assertDatabaseHas('messages', [
            'direction' => Message::DIRECTION_OUTBOUND,
            'reply_to_message_id' => $message->id,
            'text' => 'Привет!',
        ]);
    }

    public function test_job_uses_any_inbound_rule_when_contact_has_no_phone(): void
    {
        Http::fake([
            'https://platform-api.max.ru/*' => Http::response([
                'message' => [
                    'message_id' => 'max-out-any-1',
                ],
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'auto_reply_mode' => Channel::AUTO_REPLY_MODE_RULES_ONLY,
            'credentials' => [
                'token' => 'max-token',
            ],
        ]);

        AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'keyword' => null,
            'normalized_keyword' => null,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
            'contact_phone_condition' => AutoReplyRule::CONTACT_PHONE_CONDITION_MISSING_PHONE,
            'reply_text' => 'Пожалуйста, поделитесь номером телефона.',
            'is_active' => true,
        ]);

        $message = $this->createInboundMessage($channel, [
            'provider_event_key' => 'max-any-inbound-missing-phone',
            'text' => 'Любое входящее сообщение',
        ], [
            'external_user_id' => '777',
            'external_username' => 'max_any_user',
        ]);

        ProcessAutoReplyJob::dispatchSync($message->id);

        Http::assertSent(fn ($request): bool => $request['text'] === 'Пожалуйста, поделитесь номером телефона.');
        $this->assertDatabaseHas('messages', [
            'direction' => Message::DIRECTION_OUTBOUND,
            'reply_to_message_id' => $message->id,
            'text' => 'Пожалуйста, поделитесь номером телефона.',
        ]);
    }

    public function test_job_sends_request_phone_button_for_telegram_rule(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 9105,
                ],
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'auto_reply_mode' => Channel::AUTO_REPLY_MODE_RULES_ONLY,
            'credentials' => [
                'token' => 'telegram-token',
            ],
        ]);

        AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'keyword' => 'Телефон',
            'normalized_keyword' => AutoReplyRule::normalizeKeyword('Телефон'),
            'reply_text' => 'Нажмите кнопку ниже',
            'telegram_button_type' => AutoReplyRule::TELEGRAM_BUTTON_TYPE_REQUEST_PHONE,
            'is_active' => true,
        ]);

        $message = $this->createInboundMessage($channel, [
            'provider_event_key' => 'telegram-request-phone',
            'text' => 'телефон',
        ]);

        ProcessAutoReplyJob::dispatchSync($message->id);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
                && $request['text'] === 'Нажмите кнопку ниже'
                && data_get($request->data(), 'reply_markup.keyboard.0.0.request_contact') === true
                && data_get($request->data(), 'reply_markup.keyboard.0.0.text') === 'Поделиться номером телефона';
        });
    }

    public function test_job_sends_request_phone_button_for_max_rule(): void
    {
        Http::fake([
            'https://platform-api.max.ru/*' => Http::response([
                'message' => [
                    'message_id' => 'max-request-phone-1',
                ],
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'auto_reply_mode' => Channel::AUTO_REPLY_MODE_RULES_ONLY,
            'credentials' => [
                'token' => 'max-token',
            ],
        ]);

        AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'keyword' => 'Телефон',
            'normalized_keyword' => AutoReplyRule::normalizeKeyword('Телефон'),
            'reply_text' => 'Нажмите кнопку ниже',
            'max_button_type' => AutoReplyRule::MAX_BUTTON_TYPE_REQUEST_PHONE,
            'is_active' => true,
        ]);

        $message = $this->createInboundMessage($channel, [
            'provider_event_key' => 'max-request-phone',
            'text' => 'телефон',
            'external_chat_id' => '700',
        ], [
            'external_user_id' => '500',
        ]);

        ProcessAutoReplyJob::dispatchSync($message->id);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://platform-api.max.ru/messages?chat_id=700'
                && $request['text'] === 'Нажмите кнопку ниже'
                && data_get($request->data(), 'attachments.0.type') === 'inline_keyboard'
                && data_get($request->data(), 'attachments.0.payload.buttons.0.0.type') === 'request_contact'
                && data_get($request->data(), 'attachments.0.payload.buttons.0.0.text') === 'Поделиться номером телефона';
        });
    }

    public function test_job_sends_link_button_for_max_rule(): void
    {
        Http::fake([
            'https://platform-api.max.ru/*' => Http::response([
                'message' => [
                    'message_id' => 'max-link-button-1',
                ],
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'auto_reply_mode' => Channel::AUTO_REPLY_MODE_RULES_ONLY,
            'credentials' => [
                'token' => 'max-token',
            ],
        ]);

        $rule = AutoReplyRule::factory()
            ->forChannel($channel)
            ->create([
                'channel_id' => $channel->id,
                'keyword' => 'Ссылка',
                'normalized_keyword' => AutoReplyRule::normalizeKeyword('Ссылка'),
                'reply_text' => 'Перейдите по ссылке',
                'is_active' => true,
            ]);

        $rule->channels()->sync([
            $channel->id => [
                'button_type' => AutoReplyRule::BUTTON_TYPE_INLINE_KEYBOARD,
                'button_text' => 'Открыть MAX форму',
                'button_url' => 'https://example.com/max-form',
            ],
        ]);

        $message = $this->createInboundMessage($channel, [
            'provider_event_key' => 'max-link-button',
            'text' => 'ссылка',
            'external_chat_id' => '700',
        ], [
            'external_user_id' => '500',
        ]);

        ProcessAutoReplyJob::dispatchSync($message->id);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://platform-api.max.ru/messages?chat_id=700'
                && $request['text'] === 'Перейдите по ссылке'
                && data_get($request->data(), 'attachments.0.type') === 'inline_keyboard'
                && data_get($request->data(), 'attachments.0.payload.buttons.0.0.type') === 'link'
                && data_get($request->data(), 'attachments.0.payload.buttons.0.0.text') === 'Открыть MAX форму'
                && data_get($request->data(), 'attachments.0.payload.buttons.0.0.url') === 'https://example.com/max-form';
        });
    }

    public function test_job_skips_reply_when_channel_is_rules_only_and_no_rule_matches(): void
    {
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'auto_reply_mode' => Channel::AUTO_REPLY_MODE_RULES_ONLY,
            'credentials' => [
                'token' => 'telegram-token',
            ],
        ]);

        AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'keyword' => 'Тест1',
            'normalized_keyword' => AutoReplyRule::normalizeKeyword('Тест1'),
            'reply_text' => 'Шаблон 1',
            'is_active' => true,
        ]);

        $message = $this->createInboundMessage($channel, [
            'provider_event_key' => 'telegram-no-match',
            'text' => 'Другой текст',
        ]);

        ProcessAutoReplyJob::dispatchSync($message->id);

        $message->refresh();

        Http::assertNothingSent();
        $this->assertNull($message->auto_reply_sent_at);
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'bot.reply_skipped_no_rule',
            'level' => 'info',
        ]);

        $skipLog = $channel->activityLogs()->where('event', 'bot.reply_skipped_no_rule')->latest('id')->firstOrFail();
        $this->assertSame(Channel::AUTO_REPLY_MODE_RULES_ONLY, $skipLog->context['auto_reply_mode']);
        $this->assertSame('skipped_no_rule', $skipLog->context['auto_reply_source']);
    }

    public function test_job_skips_reply_when_no_rule_matches(): void
    {
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'auto_reply_mode' => Channel::AUTO_REPLY_MODE_RULES_ONLY,
            'credentials' => [
                'token' => 'telegram-token',
            ],
        ]);

        AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'keyword' => 'Тест1',
            'normalized_keyword' => AutoReplyRule::normalizeKeyword('Тест1'),
            'reply_text' => 'Шаблон 1',
            'is_active' => true,
        ]);

        $message = $this->createInboundMessage($channel, [
            'provider_event_key' => 'telegram-legacy-no-match',
            'text' => 'Совсем другой текст',
        ]);

        ProcessAutoReplyJob::dispatchSync($message->id);

        Http::assertNothingSent();
        $this->assertNull($message->fresh()->auto_reply_sent_at);
        $this->assertDatabaseCount('messages', 1);

        $skipLog = $channel->activityLogs()->where('event', 'bot.reply_skipped_no_rule')->latest('id')->firstOrFail();
        $this->assertSame(Channel::AUTO_REPLY_MODE_RULES_ONLY, $skipLog->context['auto_reply_mode']);
        $this->assertSame('skipped_no_rule', $skipLog->context['auto_reply_source']);
    }

    public function test_job_skips_reply_when_contact_auto_reply_is_disabled(): void
    {
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
            ],
        ]);

        AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'keyword' => null,
            'normalized_keyword' => null,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
            'reply_text' => 'Повторяемый ответ',
            'is_active' => true,
        ]);

        $message = $this->createInboundMessage(
            $channel,
            ['provider_event_key' => 'telegram-disabled'],
            [],
            ['is_auto_reply_enabled' => false],
        );

        ProcessAutoReplyJob::dispatchSync($message->id);

        $message->refresh();

        Http::assertNothingSent();
        $this->assertNull($message->auto_reply_sent_at);
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'bot.reply_skipped_contact_disabled',
            'level' => 'info',
        ]);

        $skipLog = $channel->activityLogs()->where('event', 'bot.reply_skipped_contact_disabled')->latest('id')->firstOrFail();
        $this->assertSame(Channel::AUTO_REPLY_MODE_RULES_ONLY, $skipLog->context['auto_reply_mode']);
        $this->assertSame('skipped_contact_disabled', $skipLog->context['auto_reply_source']);
    }

    public function test_job_skips_reply_when_contact_auto_reply_is_disabled_in_rules_only_mode(): void
    {
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'auto_reply_mode' => Channel::AUTO_REPLY_MODE_RULES_ONLY,
            'credentials' => [
                'token' => 'telegram-token',
            ],
        ]);

        AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'keyword' => 'Тест1',
            'normalized_keyword' => AutoReplyRule::normalizeKeyword('Тест1'),
            'reply_text' => 'Шаблон 1',
            'is_active' => true,
        ]);

        $message = $this->createInboundMessage(
            $channel,
            [
                'provider_event_key' => 'telegram-disabled-rules-only',
                'text' => 'Тест1',
            ],
            [],
            ['is_auto_reply_enabled' => false],
        );

        ProcessAutoReplyJob::dispatchSync($message->id);

        Http::assertNothingSent();
        $this->assertNull($message->fresh()->auto_reply_sent_at);

        $skipLog = $channel->activityLogs()->where('event', 'bot.reply_skipped_contact_disabled')->latest('id')->firstOrFail();
        $this->assertSame(Channel::AUTO_REPLY_MODE_RULES_ONLY, $skipLog->context['auto_reply_mode']);
        $this->assertSame('skipped_contact_disabled', $skipLog->context['auto_reply_source']);
    }

    public function test_repeated_job_execution_for_same_inbound_message_does_not_duplicate_outbound_messages_after_successful_pass(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 9011,
                ],
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
            ],
        ]);

        AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'keyword' => null,
            'normalized_keyword' => null,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
            'reply_text' => 'Ответ, который сломается на отправке',
            'is_active' => true,
        ]);

        $message = $this->createInboundMessage($channel, [
            'provider_event_key' => 'telegram-repeat',
        ]);

        ProcessAutoReplyJob::dispatchSync($message->id);
        ProcessAutoReplyJob::dispatchSync($message->id);

        Http::assertSentCount(1);
        $this->assertDatabaseCount('messages', 2);
        $this->assertSame(1, Message::query()->where('direction', Message::DIRECTION_OUTBOUND)->count());
    }

    public function test_job_marks_channel_error_and_keeps_inbound_pending_when_transport_fails(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => false,
            ], 500),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
            ],
        ]);

        AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'keyword' => null,
            'normalized_keyword' => null,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
            'reply_text' => 'Ответ, который сломается на отправке',
            'is_active' => true,
        ]);

        $message = $this->createInboundMessage($channel, [
            'provider_event_key' => 'telegram-failure',
        ]);

        $thrown = null;

        try {
            ProcessAutoReplyJob::dispatchSync($message->id);
        } catch (\Throwable $throwable) {
            $thrown = $throwable;
        }

        $this->assertNotNull($thrown);

        $message->refresh();
        $channel->refresh();

        $this->assertNull($message->auto_reply_sent_at);
        $this->assertNull($channel->last_reply_sent_at);
        $this->assertNotNull($channel->last_error_at);
        $this->assertNotNull($channel->last_error_message);
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'bot.reply_failed',
            'level' => 'error',
        ]);

        $failedLog = $channel->activityLogs()->where('event', 'bot.reply_failed')->latest('id')->firstOrFail();
        $this->assertSame(Channel::AUTO_REPLY_MODE_RULES_ONLY, $failedLog->context['auto_reply_mode']);
        $this->assertSame('rule', $failedLog->context['auto_reply_source']);
        $this->assertNull($failedLog->context['button_type']);
    }

    public function test_job_can_succeed_after_previous_failure(): void
    {
        $attempt = 0;

        Http::fake(function ($request) use (&$attempt) {
            $attempt++;

            return $attempt === 1
                ? Http::response(['ok' => false], 500)
                : Http::response([
                    'ok' => true,
                    'result' => [
                        'message_id' => 9022,
                    ],
                ]);
        });

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
            ],
        ]);

        AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'keyword' => null,
            'normalized_keyword' => null,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
            'reply_text' => 'Ответ после ретрая',
            'is_active' => true,
        ]);

        $message = $this->createInboundMessage($channel, [
            'provider_event_key' => 'telegram-retry',
        ]);

        try {
            ProcessAutoReplyJob::dispatchSync($message->id);
        } catch (\Throwable) {
        }

        ProcessAutoReplyJob::dispatchSync($message->id);

        $message->refresh();
        $channel->refresh();

        $this->assertNotNull($message->auto_reply_sent_at);
        $this->assertNotNull($channel->last_reply_sent_at);
        $this->assertDatabaseCount('messages', 2);
        $this->assertDatabaseHas('messages', [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_AUTO_REPLY,
            'external_message_id' => '9022',
        ]);
    }

    /**
     * @param  array<string, mixed>  $messageOverrides
     * @param  array<string, mixed>  $identityOverrides
     * @param  array<string, mixed>  $contactOverrides
     */
    protected function createInboundMessage(
        Channel $channel,
        array $messageOverrides = [],
        array $identityOverrides = [],
        array $contactOverrides = [],
    ): Message {
        $contact = Contact::factory()->create($contactOverrides);
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

    protected function readyTelegramAccountChannel(): Channel
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
    protected function createInboundDialogMessage(Channel $channel, array $messageOverrides = []): Message
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

    public function test_channel_without_explicit_mode_defaults_to_rules_only_and_skips_without_rule(): void
    {
        Http::fake();

        $channelId = Channel::query()->create([
            'name' => 'Compat Channel',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'credentials' => [
                'token' => 'telegram-token',
            ],
            'is_active' => true,
        ])->id;

        $channel = Channel::query()->findOrFail($channelId);
        $this->assertSame(Channel::AUTO_REPLY_MODE_RULES_ONLY, $channel->auto_reply_mode);

        $message = $this->createInboundMessage($channel, [
            'provider_event_key' => 'telegram-compat-mode',
        ]);

        ProcessAutoReplyJob::dispatchSync($message->id);

        Http::assertNothingSent();

        $skipLog = $channel->fresh()->activityLogs()->where('event', 'bot.reply_skipped_no_rule')->latest('id')->firstOrFail();
        $this->assertSame(Channel::AUTO_REPLY_MODE_RULES_ONLY, $skipLog->context['auto_reply_mode']);
        $this->assertSame('skipped_no_rule', $skipLog->context['auto_reply_source']);
    }

    public function test_job_does_not_send_auto_reply_when_contact_is_in_data_collection(): void
    {
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
            ],
        ]);

        AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'keyword' => null,
            'normalized_keyword' => null,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
            'reply_text' => 'Не должен отправиться.',
            'is_active' => true,
        ]);

        $message = $this->createInboundMessage($channel, [
            'provider_event_key' => 'telegram-data-collection-skip',
            'text' => 'Герман',
        ], [], [
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_ACTIVE,
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_FIRST_NAME,
            'data_collection_started_at' => now(),
        ]);

        ProcessAutoReplyJob::dispatchSync($message->id);

        Http::assertNothingSent();
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_job_skips_auto_reply_when_dialog_is_blocked_by_user(): void
    {
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
            ],
        ]);

        AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'keyword' => null,
            'normalized_keyword' => null,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
            'reply_text' => 'Не должен отправиться.',
            'is_active' => true,
        ]);

        $message = $this->createInboundMessage($channel, [
            'provider_event_key' => 'telegram-blocked-auto-reply',
            'external_chat_id' => 'blocked-auto-reply-chat',
        ], [
            'external_user_id' => 'blocked-auto-reply-user',
        ]);

        $dialog = Dialog::factory()->create([
            'contact_id' => $message->contact_id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $message->contact_identity_id,
            'external_chat_id' => 'blocked-auto-reply-chat',
            'bot_subscription_status' => Dialog::BOT_SUBSCRIPTION_STATUS_BLOCKED_BY_USER,
            'bot_subscription_changed_at' => now(),
        ]);

        $message->forceFill([
            'dialog_id' => $dialog->id,
        ])->save();

        ProcessAutoReplyJob::dispatchSync($message->id);

        Http::assertNothingSent();

        $message->refresh();

        $this->assertNull($message->auto_reply_sent_at);
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'bot.reply_skipped_dialog_not_sendable',
            'level' => 'info',
        ]);
    }

    public function test_job_recovers_route_from_legacy_message_when_attached_dialog_is_stale(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 9301,
                ],
            ]),
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
            ],
        ]);

        AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'keyword' => null,
            'normalized_keyword' => null,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
            'reply_text' => 'Восстановленный маршрут.',
            'is_active' => true,
        ]);

        $contact = Contact::factory()->create();
        $legacyIdentity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'legacy-user',
            'external_username' => 'legacy_username',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => null,
            'external_chat_id' => null,
        ]);
        $message = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $legacyIdentity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'provider_event_key' => 'telegram-legacy-route-recovery',
            'external_chat_id' => '399',
            'external_message_id' => 'legacy-route-source-1',
            'text' => 'hello',
            'raw_payload' => ['message' => 'payload'],
            'received_at' => now(),
            'auto_reply_sent_at' => null,
        ]);

        ProcessAutoReplyJob::dispatchSync($message->id);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === '399'
            && $request['text'] === 'Восстановленный маршрут.');

        $outboundMessage = Message::query()
            ->where('direction', Message::DIRECTION_OUTBOUND)
            ->where('reply_to_message_id', $message->id)
            ->firstOrFail();

        $dialog->refresh();

        $this->assertSame($legacyIdentity->id, $dialog->current_contact_identity_id);
        $this->assertSame('399', $dialog->external_chat_id);
        $this->assertSame($dialog->id, $outboundMessage->dialog_id);
        $this->assertSame($legacyIdentity->id, $outboundMessage->contact_identity_id);
        $this->assertSame('399', $outboundMessage->external_chat_id);
    }
}
