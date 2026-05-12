<?php

namespace Database\Factories;

use App\Models\BotConstructorConstant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BotConstructorConstant>
 */
class BotConstructorConstantFactory extends Factory
{
    protected $model = BotConstructorConstant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(3),
            'name' => fake()->words(3, true),
            'value_type' => BotConstructorConstant::VALUE_TYPE_INTEGER,
            'value' => '10',
            'description' => null,
        ];
    }

    public function arrowPassLimit(): static
    {
        return $this->state(fn (): array => [
            'key' => BotConstructorConstant::KEY_ARROW_PASS_LIMIT,
            'name' => 'Лимит переходов клиента по стрелке',
            'value_type' => BotConstructorConstant::VALUE_TYPE_INTEGER,
            'value' => (string) BotConstructorConstant::DEFAULT_ARROW_PASS_LIMIT,
        ]);
    }
}
