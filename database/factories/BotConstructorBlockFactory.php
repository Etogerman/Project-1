<?php

namespace Database\Factories;

use App\Models\BotConstructorBlock;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BotConstructorBlock>
 */
class BotConstructorBlockFactory extends Factory
{
    protected $model = BotConstructorBlock::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => 'Стартовое условие',
            'is_active' => false,
            'match_type' => BotConstructorBlock::MATCH_TYPE_EXACT_KEYWORD,
            'match_values' => ['привет'],
            'response_text' => 'Здравствуйте',
            'x' => 64,
            'y' => 64,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (): array => [
            'is_active' => true,
        ]);
    }
}
