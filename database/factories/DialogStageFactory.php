<?php

namespace Database\Factories;

use App\Models\DialogStage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DialogStage>
 */
class DialogStageFactory extends Factory
{
    protected $model = DialogStage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'key' => Str::slug($name, '_'),
            'name' => mb_convert_case($name, MB_CASE_TITLE, 'UTF-8'),
            'color' => fake()->randomElement(['gray', 'info', 'success', 'warning', 'primary']),
            'sort_order' => fake()->numberBetween(60, 500),
            'system_role' => null,
            'is_seeded' => false,
        ];
    }
}
