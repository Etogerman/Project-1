<?php

namespace Tests\Feature;

use App\Models\AutoReplyRule;
use App\Models\AutoReplyRuleTagCondition;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactPhoneNumber;
use App\Models\Tag;
use App\Services\Bots\ResolveAutoReplyRuleAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResolveAutoReplyRuleActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_matching_rule_with_trim_and_case_insensitive_normalization(): void
    {
        $channel = Channel::factory()->create();
        $contact = Contact::factory()->create();

        $rule = AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'keyword' => 'Тест1',
            'normalized_keyword' => AutoReplyRule::normalizeKeyword('Тест1'),
            'reply_text' => 'Шаблон 1',
            'is_active' => true,
        ]);

        $resolved = app(ResolveAutoReplyRuleAction::class)->handle($channel, $contact, '  тест1  ');

        $this->assertInstanceOf(AutoReplyRule::class, $resolved);
        $this->assertTrue($resolved->is($rule));
    }

    public function test_it_returns_null_when_message_text_is_empty_or_rule_is_inactive_or_from_another_channel(): void
    {
        $channel = Channel::factory()->create();
        $otherChannel = Channel::factory()->create();
        $contact = Contact::factory()->create();

        AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'keyword' => 'Тест2',
            'normalized_keyword' => AutoReplyRule::normalizeKeyword('Тест2'),
            'is_active' => false,
        ]);

        AutoReplyRule::factory()->create([
            'channel_id' => $otherChannel->id,
            'keyword' => 'Тест2',
            'normalized_keyword' => AutoReplyRule::normalizeKeyword('Тест2'),
            'is_active' => true,
        ]);

        $resolver = app(ResolveAutoReplyRuleAction::class);

        $this->assertNull($resolver->handle($channel, $contact, null));
        $this->assertNull($resolver->handle($channel, $contact, '   '));
        $this->assertNull($resolver->handle($channel, $contact, 'Тест2'));
        $this->assertNull($resolver->handle($channel, $contact, 'Нет совпадения'));
    }

    public function test_it_respects_phone_condition_for_exact_keyword_rules(): void
    {
        $channel = Channel::factory()->create();
        $contactWithPhone = Contact::factory()->create();
        $contactWithoutPhone = Contact::factory()->create();

        ContactPhoneNumber::factory()->create([
            'contact_id' => $contactWithPhone->id,
            'is_primary' => true,
        ]);

        $rule = AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'keyword' => 'Тест1',
            'normalized_keyword' => AutoReplyRule::normalizeKeyword('Тест1'),
            'contact_phone_condition' => AutoReplyRule::CONTACT_PHONE_CONDITION_HAS_PHONE,
        ]);

        $resolver = app(ResolveAutoReplyRuleAction::class);

        $this->assertTrue($resolver->handle($channel, $contactWithPhone, 'Тест1')?->is($rule) ?? false);
        $this->assertNull($resolver->handle($channel, $contactWithoutPhone, 'Тест1'));
    }

    public function test_it_falls_back_to_any_inbound_rule_when_exact_rule_is_not_applicable_for_contact(): void
    {
        $channel = Channel::factory()->create();
        $contact = Contact::factory()->create();

        AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'keyword' => 'Тест1',
            'normalized_keyword' => AutoReplyRule::normalizeKeyword('Тест1'),
            'contact_phone_condition' => AutoReplyRule::CONTACT_PHONE_CONDITION_HAS_PHONE,
            'reply_text' => 'Для контакта с телефоном',
        ]);

        $fallbackRule = AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'keyword' => null,
            'normalized_keyword' => null,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
            'contact_phone_condition' => AutoReplyRule::CONTACT_PHONE_CONDITION_MISSING_PHONE,
            'reply_text' => 'Запросите номер',
        ]);

        $resolved = app(ResolveAutoReplyRuleAction::class)->handle($channel, $contact, 'Тест1');

        $this->assertInstanceOf(AutoReplyRule::class, $resolved);
        $this->assertTrue($resolved->is($fallbackRule));
    }

    public function test_it_prefers_exact_parameter_rule_over_exact_text_rule(): void
    {
        $channel = Channel::factory()->create();
        $contact = Contact::factory()->create();

        AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD,
            'keyword' => '/start text_1',
            'normalized_keyword' => AutoReplyRule::normalizeKeyword('/start text_1'),
            'reply_text' => 'Text match',
        ]);

        $parameterRule = AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_PARAMETER,
            'keyword' => 'TEXT_1',
            'normalized_keyword' => AutoReplyRule::normalizeKeyword('TEXT_1'),
            'reply_text' => 'Parameter match',
        ]);

        $resolved = app(ResolveAutoReplyRuleAction::class)->handle($channel, $contact, '/start TEXT_1', 'TEXT_1');

        $this->assertInstanceOf(AutoReplyRule::class, $resolved);
        $this->assertTrue($resolved->is($parameterRule));
    }

    public function test_it_matches_contains_text_rule_by_substring(): void
    {
        $channel = Channel::factory()->create();
        $contact = Contact::factory()->create();

        $containsRule = AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_CONTAINS_TEXT,
            'keyword' => 'скидка',
            'normalized_keyword' => AutoReplyRule::normalizeKeyword('скидка'),
            'reply_text' => 'Contains match',
        ]);

        $resolved = app(ResolveAutoReplyRuleAction::class)->handle($channel, $contact, 'У меня есть скидка на заказ');

        $this->assertInstanceOf(AutoReplyRule::class, $resolved);
        $this->assertTrue($resolved->is($containsRule));
    }

    public function test_it_respects_phone_condition_for_exact_parameter_rules(): void
    {
        $channel = Channel::factory()->create();
        $contactWithPhone = Contact::factory()->create();
        $contactWithoutPhone = Contact::factory()->create();

        ContactPhoneNumber::factory()->create([
            'contact_id' => $contactWithPhone->id,
            'is_primary' => true,
        ]);

        $rule = AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_PARAMETER,
            'keyword' => 'promo_123',
            'normalized_keyword' => AutoReplyRule::normalizeKeyword('promo_123'),
            'contact_phone_condition' => AutoReplyRule::CONTACT_PHONE_CONDITION_HAS_PHONE,
        ]);

        $resolver = app(ResolveAutoReplyRuleAction::class);

        $this->assertTrue($resolver->handle($channel, $contactWithPhone, null, 'promo_123')?->is($rule) ?? false);
        $this->assertNull($resolver->handle($channel, $contactWithoutPhone, null, 'promo_123'));
    }

    public function test_it_matches_rule_when_contact_has_all_required_tags(): void
    {
        $channel = Channel::factory()->create();
        $contact = Contact::factory()->create();
        $vipTag = Tag::factory()->create(['name' => 'VIP']);
        $warmTag = Tag::factory()->create(['name' => 'Прогретый']);

        $contact->tags()->attach([$vipTag->id, $warmTag->id], [
            'assigned_at' => now(),
            'assigned_by_user_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rule = AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
            'keyword' => null,
            'normalized_keyword' => null,
            'reply_text' => 'Подходит только VIP.',
        ]);

        AutoReplyRuleTagCondition::query()->insert([
            [
                'auto_reply_rule_id' => $rule->id,
                'tag_id' => $vipTag->id,
                'condition' => AutoReplyRuleTagCondition::CONDITION_REQUIRED,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'auto_reply_rule_id' => $rule->id,
                'tag_id' => $warmTag->id,
                'condition' => AutoReplyRuleTagCondition::CONDITION_REQUIRED,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $resolved = app(ResolveAutoReplyRuleAction::class)->handle($channel, $contact, 'Любое сообщение');

        $this->assertInstanceOf(AutoReplyRule::class, $resolved);
        $this->assertTrue($resolved->is($rule));
    }

    public function test_it_does_not_match_rule_when_required_tag_is_missing(): void
    {
        $channel = Channel::factory()->create();
        $contact = Contact::factory()->create();
        $vipTag = Tag::factory()->create(['name' => 'VIP']);

        $rule = AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
            'keyword' => null,
            'normalized_keyword' => null,
            'reply_text' => 'Только VIP.',
        ]);

        AutoReplyRuleTagCondition::query()->create([
            'auto_reply_rule_id' => $rule->id,
            'tag_id' => $vipTag->id,
            'condition' => AutoReplyRuleTagCondition::CONDITION_REQUIRED,
        ]);

        $resolved = app(ResolveAutoReplyRuleAction::class)->handle($channel, $contact, 'Любое сообщение');

        $this->assertNull($resolved);
    }

    public function test_it_does_not_match_rule_when_contact_has_excluded_tag(): void
    {
        $channel = Channel::factory()->create();
        $contact = Contact::factory()->create();
        $blockedTag = Tag::factory()->create(['name' => 'Стоп']);

        $contact->tags()->attach($blockedTag->id, [
            'assigned_at' => now(),
            'assigned_by_user_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rule = AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
            'keyword' => null,
            'normalized_keyword' => null,
            'reply_text' => 'Не должен сработать.',
        ]);

        AutoReplyRuleTagCondition::query()->create([
            'auto_reply_rule_id' => $rule->id,
            'tag_id' => $blockedTag->id,
            'condition' => AutoReplyRuleTagCondition::CONDITION_EXCLUDED,
        ]);

        $resolved = app(ResolveAutoReplyRuleAction::class)->handle($channel, $contact, 'Любое сообщение');

        $this->assertNull($resolved);
    }

    public function test_it_resolves_tag_conditions_by_root_contact_after_merge(): void
    {
        $channel = Channel::factory()->create();
        $rootContact = Contact::factory()->create();
        $mergedContact = Contact::factory()->create([
            'merged_into_contact_id' => $rootContact->id,
            'merged_at' => now(),
        ]);
        $vipTag = Tag::factory()->create(['name' => 'VIP']);

        $rootContact->tags()->attach($vipTag->id, [
            'assigned_at' => now(),
            'assigned_by_user_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rule = AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
            'keyword' => null,
            'normalized_keyword' => null,
            'reply_text' => 'Сработал по корневому тегу.',
        ]);

        AutoReplyRuleTagCondition::query()->create([
            'auto_reply_rule_id' => $rule->id,
            'tag_id' => $vipTag->id,
            'condition' => AutoReplyRuleTagCondition::CONDITION_REQUIRED,
        ]);

        $resolved = app(ResolveAutoReplyRuleAction::class)->handle($channel, $mergedContact, 'Любое сообщение');

        $this->assertInstanceOf(AutoReplyRule::class, $resolved);
        $this->assertTrue($resolved->is($rule));
    }
}
