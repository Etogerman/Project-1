<?php

namespace Database\Factories;

use App\Models\BotConstructorArrow;
use App\Models\BotConstructorArrowRun;
use App\Models\BotConstructorExecution;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BotConstructorArrowRun>
 */
class BotConstructorArrowRunFactory extends Factory
{
    protected $model = BotConstructorArrowRun::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bot_constructor_execution_id' => BotConstructorExecution::factory(),
            'bot_constructor_arrow_id' => BotConstructorArrow::factory(),
            'dialog_id' => function (array $attributes): int {
                return BotConstructorExecution::query()->findOrFail($attributes['bot_constructor_execution_id'])->dialog_id;
            },
            'source_block_id' => function (array $attributes): int {
                return BotConstructorArrow::query()->findOrFail($attributes['bot_constructor_arrow_id'])->source_block_id;
            },
            'target_block_id' => function (array $attributes): int {
                return BotConstructorArrow::query()->findOrFail($attributes['bot_constructor_arrow_id'])->target_block_id;
            },
            'inbound_message_id' => null,
            'source_execution_block_run_id' => null,
            'scheduled_for' => null,
            'processing_started_at' => now(),
            'schema_cutoff_at' => null,
            'status' => BotConstructorArrowRun::STATUS_PROCESSING,
            'error_message' => null,
        ];
    }

    public function scheduled(): static
    {
        return $this->state(fn (): array => [
            'scheduled_for' => now()->addMinutes(5),
            'processing_started_at' => null,
            'status' => BotConstructorArrowRun::STATUS_SCHEDULED,
        ]);
    }

    public function passed(): static
    {
        return $this->state(fn (): array => [
            'status' => BotConstructorArrowRun::STATUS_PASSED,
        ]);
    }
}
