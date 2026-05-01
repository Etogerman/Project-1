<?php

namespace Tests\Feature;

use App\Models\AutoReplyRule;
use App\Models\Channel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutoReplyRuleUniquenessTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_normalized_keyword_is_allowed_when_match_scope_differs(): void
    {
        $channel = Channel::factory()->create([
            'is_active' => true,
        ]);

        AutoReplyRule::query()->create([
            'channel_id' => $channel->id,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD,
            'keyword' => 'TEXT_1',
            'normalized_keyword' => 'text_1',
            'reply_text' => 'Text match',
            'is_active' => true,
        ]);

        AutoReplyRule::query()->create([
            'channel_id' => $channel->id,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_PARAMETER,
            'keyword' => 'TEXT_1',
            'normalized_keyword' => 'text_1',
            'reply_text' => 'Parameter match',
            'is_active' => true,
        ]);

        $this->assertDatabaseCount('auto_reply_rules', 2);
    }

    public function test_same_normalized_keyword_is_allowed_within_same_match_scope(): void
    {
        $channel = Channel::factory()->create([
            'is_active' => true,
        ]);

        AutoReplyRule::query()->create([
            'channel_id' => $channel->id,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_PARAMETER,
            'keyword' => 'TEXT_1',
            'normalized_keyword' => 'text_1',
            'reply_text' => 'First parameter match',
            'is_active' => true,
        ]);

        AutoReplyRule::query()->create([
            'channel_id' => $channel->id,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_PARAMETER,
            'keyword' => 'TEXT_1',
            'normalized_keyword' => 'text_1',
            'reply_text' => 'Duplicate parameter match',
            'is_active' => true,
        ]);

        $this->assertDatabaseCount('auto_reply_rules', 2);
    }
}
