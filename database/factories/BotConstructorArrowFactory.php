<?php

namespace Database\Factories;

use App\Models\BotConstructorArrow;
use App\Models\BotConstructorBlock;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BotConstructorArrow>
 */
class BotConstructorArrowFactory extends Factory
{
    protected $model = BotConstructorArrow::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source_block_id' => BotConstructorBlock::factory(),
            'target_block_id' => BotConstructorBlock::factory(),
            'is_active' => true,
            'delay_value' => 0,
            'delay_unit' => BotConstructorArrow::DELAY_UNIT_SECONDS,
            'cancel_if_left_source_block' => false,
            'condition_match_type' => BotConstructorArrow::CONDITION_ALWAYS,
            'condition_value' => null,
            'priority' => 100,
            'pass_limit_mode' => BotConstructorArrow::PASS_LIMIT_MODE_CONSTANT,
            'pass_limit_value' => null,
        ];
    }

    public function manualLimit(int $limit = 10): static
    {
        return $this->state(fn (): array => [
            'pass_limit_mode' => BotConstructorArrow::PASS_LIMIT_MODE_MANUAL,
            'pass_limit_value' => $limit,
        ]);
    }

    public function delayed(int $value = 5, string $unit = BotConstructorArrow::DELAY_UNIT_MINUTES): static
    {
        return $this->state(fn (): array => [
            'delay_value' => $value,
            'delay_unit' => $unit,
        ]);
    }
}
