<?php

namespace Database\Factories;

use App\Models\BotConstructorExecution;
use App\Models\Dialog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BotConstructorExecution>
 */
class BotConstructorExecutionFactory extends Factory
{
    protected $model = BotConstructorExecution::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'root_inbound_message_id' => null,
            'parent_execution_id' => null,
            'started_by_arrow_run_id' => null,
            'dialog_id' => Dialog::factory(),
            'channel_id' => function (array $attributes): int {
                return Dialog::query()->findOrFail($attributes['dialog_id'])->channel_id;
            },
            'trigger_type' => BotConstructorExecution::TRIGGER_INBOUND,
            'auto_transition_count' => 0,
            'next_sequence_number' => 1,
            'status' => BotConstructorExecution::STATUS_RUNNING,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => BotConstructorExecution::STATUS_COMPLETED,
        ]);
    }
}
