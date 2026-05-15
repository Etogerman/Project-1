<?php

namespace Database\Factories;

use App\Models\BotConstructorBlock;
use App\Models\BotConstructorExecution;
use App\Models\BotConstructorExecutionBlockRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BotConstructorExecutionBlockRun>
 */
class BotConstructorExecutionBlockRunFactory extends Factory
{
    protected $model = BotConstructorExecutionBlockRun::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bot_constructor_execution_id' => BotConstructorExecution::factory(),
            'bot_constructor_block_id' => BotConstructorBlock::factory(),
            'bot_constructor_arrow_run_id' => null,
            'dialog_id' => function (array $attributes): int {
                return BotConstructorExecution::query()->findOrFail($attributes['bot_constructor_execution_id'])->dialog_id;
            },
            'channel_id' => function (array $attributes): int {
                return BotConstructorExecution::query()->findOrFail($attributes['bot_constructor_execution_id'])->channel_id;
            },
            'sequence_number' => 1,
            'status' => BotConstructorExecutionBlockRun::STATUS_PROCESSING,
            'outbound_message_id' => null,
            'processing_started_at' => now(),
            'error_message' => null,
        ];
    }

    public function sent(): static
    {
        return $this->state(fn (): array => [
            'status' => BotConstructorExecutionBlockRun::STATUS_SENT,
            'processing_started_at' => null,
        ]);
    }

    public function noReply(): static
    {
        return $this->state(fn (): array => [
            'status' => BotConstructorExecutionBlockRun::STATUS_NO_REPLY,
            'processing_started_at' => null,
        ]);
    }
}
