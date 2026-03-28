<?php

namespace Tests\Feature;

use App\Models\AutoReplyRule;
use App\Models\Channel;
use App\Services\Bots\ResolveAutoReplyRuleAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResolveAutoReplyRuleActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_matching_rule_with_trim_and_case_insensitive_normalization(): void
    {
        $channel = Channel::factory()->create();

        $rule = AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'keyword' => 'Тест1',
            'normalized_keyword' => AutoReplyRule::normalizeKeyword('Тест1'),
            'reply_text' => 'Шаблон 1',
            'is_active' => true,
        ]);

        $resolved = app(ResolveAutoReplyRuleAction::class)->handle($channel, '  тест1  ');

        $this->assertInstanceOf(AutoReplyRule::class, $resolved);
        $this->assertTrue($resolved->is($rule));
    }

    public function test_it_returns_null_when_message_text_is_empty_or_rule_is_inactive_or_from_another_channel(): void
    {
        $channel = Channel::factory()->create();
        $otherChannel = Channel::factory()->create();

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

        $this->assertNull($resolver->handle($channel, null));
        $this->assertNull($resolver->handle($channel, '   '));
        $this->assertNull($resolver->handle($channel, 'Тест2'));
        $this->assertNull($resolver->handle($channel, 'Нет совпадения'));
    }
}
