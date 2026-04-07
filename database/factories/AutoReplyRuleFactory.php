<?php

namespace Database\Factories;

use App\Models\AutoReplyRule;
use App\Models\Channel;
use Illuminate\Database\Eloquent\Factories\Factory;
use InvalidArgumentException;

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
            'priority' => 10,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (AutoReplyRule $rule): void {
            if ($rule->channel_id === null) {
                return;
            }

            $rule->channels()->syncWithoutDetaching([(int) $rule->channel_id]);
        });
    }

    public function forChannel(Channel|int $channel): static
    {
        $channelId = $channel instanceof Channel ? $channel->getKey() : $channel;

        return $this->state([
            'channel_id' => $channelId,
        ])->afterCreating(function (AutoReplyRule $rule) use ($channelId): void {
            $rule->channels()->syncWithoutDetaching([(int) $channelId]);
        });
    }

    /**
     * @param iterable<Channel|int> $channels
     */
    public function forChannels(iterable $channels): static
    {
        $channelIds = collect($channels)
            ->map(fn (Channel|int $channel): int => $channel instanceof Channel ? (int) $channel->getKey() : (int) $channel)
            ->unique()
            ->values()
            ->all();

        if ($channelIds === []) {
            throw new InvalidArgumentException('At least one channel is required.');
        }

        return $this->state([
            'channel_id' => $channelIds[0],
        ])->afterCreating(function (AutoReplyRule $rule) use ($channelIds): void {
            $rule->channels()->syncWithoutDetaching($channelIds);
        });
    }
}
