<?php

namespace Tests\Feature;

use App\Models\AutoReplyRule;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactPhoneNumber;
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
}
