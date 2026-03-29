<?php

namespace Database\Factories;

use App\Models\AutoReplyRule;
use App\Models\Channel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AutoReplyRule>
 */
class AutoReplyRuleFactory extends Factory
{
    protected $model = AutoReplyRule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $keyword = fake()->unique()->word();

        return [
            'channel_id' => Channel::factory(),
            'keyword' => $keyword,
            'normalized_keyword' => AutoReplyRule::normalizeKeyword($keyword),
            'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD,
            'contact_phone_condition' => null,
            'reply_text' => fake()->sentence(),
            'telegram_button_type' => null,
            'max_button_type' => null,
            'is_active' => true,
        ];
    }
}
