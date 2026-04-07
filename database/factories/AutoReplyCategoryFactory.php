<?php

namespace Database\Factories;

use App\Models\AutoReplyCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AutoReplyCategory>
 */
class AutoReplyCategoryFactory extends Factory
{
    protected $model = AutoReplyCategory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'sort_order' => fake()->numberBetween(0, 100),
        ];
    }
}
