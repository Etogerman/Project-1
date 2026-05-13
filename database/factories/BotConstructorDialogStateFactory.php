<?php

namespace Database\Factories;

use App\Models\BotConstructorBlock;
use App\Models\BotConstructorDialogState;
use App\Models\BotConstructorExecution;
use App\Models\Dialog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BotConstructorDialogState>
 */
class BotConstructorDialogStateFactory extends Factory
{
    protected $model = BotConstructorDialogState::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'dialog_id' => Dialog::factory(),
            'current_block_id' => BotConstructorBlock::factory(),
            'last_execution_id' => function (array $attributes): int {
                return BotConstructorExecution::factory()->create([
                    'dialog_id' => $attributes['dialog_id'],
                ])->id;
            },
        ];
    }
}
