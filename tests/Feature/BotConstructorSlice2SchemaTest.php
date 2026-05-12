<?php

namespace Tests\Feature;

use App\Models\BotConstructorArrow;
use App\Models\BotConstructorArrowRun;
use App\Models\BotConstructorBlock;
use App\Models\BotConstructorConstant;
use App\Models\BotConstructorDialogState;
use App\Models\BotConstructorExecution;
use App\Models\BotConstructorExecutionBlockRun;
use App\Models\Dialog;
use App\Models\Message;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BotConstructorSlice2SchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_arrow_pass_limit_constant_is_created_by_migration(): void
    {
        $constant = BotConstructorConstant::query()
            ->where('key', BotConstructorConstant::KEY_ARROW_PASS_LIMIT)
            ->firstOrFail();

        $this->assertSame('Лимит переходов клиента по стрелке', $constant->name);
        $this->assertSame(BotConstructorConstant::VALUE_TYPE_INTEGER, $constant->value_type);
        $this->assertSame(BotConstructorConstant::DEFAULT_ARROW_PASS_LIMIT, $constant->integerValue());
    }

    public function test_arrow_schema_allows_self_cycles_delay_and_manual_limit(): void
    {
        $block = BotConstructorBlock::factory()->create();

        $arrow = BotConstructorArrow::factory()
            ->manualLimit(3)
            ->create([
                'source_block_id' => $block->id,
                'target_block_id' => $block->id,
                'delay_value' => 720,
                'delay_unit' => BotConstructorArrow::DELAY_UNIT_HOURS,
                'condition_match_type' => BotConstructorArrow::CONDITION_EXACT_TEXT,
                'condition_value' => '  Да  ',
                'priority' => 10,
            ]);

        $this->assertSame($block->id, $arrow->source_block_id);
        $this->assertSame($block->id, $arrow->target_block_id);
        $this->assertSame(30 * 24 * 60 * 60, $arrow->delayInSeconds());
        $this->assertSame('Да', $arrow->condition_value);
        $this->assertSame(BotConstructorArrow::PASS_LIMIT_MODE_MANUAL, $arrow->pass_limit_mode);
        $this->assertSame(3, $arrow->pass_limit_value);
    }

    public function test_arrow_and_constant_validation_reject_invalid_values(): void
    {
        $block = BotConstructorBlock::factory()->create();

        $this->assertValidationFails(
            fn () => BotConstructorConstant::factory()->arrowPassLimit()->create(['value' => '0']),
            'value',
        );

        $this->assertValidationFails(
            fn () => BotConstructorArrow::factory()->manualLimit(0)->create([
                'source_block_id' => $block->id,
                'target_block_id' => $block->id,
            ]),
            'pass_limit_value',
        );

        $this->assertValidationFails(
            fn () => BotConstructorArrow::factory()->create([
                'source_block_id' => $block->id,
                'target_block_id' => $block->id,
                'condition_match_type' => BotConstructorArrow::CONDITION_CONTAINS_TEXT,
                'condition_value' => '  ',
            ]),
            'condition_value',
        );

        $this->assertValidationFails(
            fn () => BotConstructorArrow::factory()->create([
                'source_block_id' => $block->id,
                'target_block_id' => $block->id,
                'delay_value' => 31,
                'delay_unit' => BotConstructorArrow::DELAY_UNIT_DAYS,
            ]),
            'delay_value',
        );
    }

    public function test_runtime_trace_models_can_be_persisted_and_related(): void
    {
        $dialog = Dialog::factory()->create();
        $dialog->load(['channel', 'currentContactIdentity']);
        $channel = $dialog->channel;
        $identity = $dialog->currentContactIdentity;
        $message = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $dialog->contact_id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'text' => 'Старт',
        ]);
        $sourceBlock = BotConstructorBlock::factory()->create(['title' => 'Меню']);
        $targetBlock = BotConstructorBlock::factory()->create(['title' => 'Раздел']);
        $arrow = BotConstructorArrow::factory()->create([
            'source_block_id' => $sourceBlock->id,
            'target_block_id' => $targetBlock->id,
        ]);
        $execution = BotConstructorExecution::factory()->create([
            'root_inbound_message_id' => $message->id,
            'dialog_id' => $dialog->id,
            'channel_id' => $channel->id,
            'trigger_type' => BotConstructorExecution::TRIGGER_INBOUND,
            'status' => BotConstructorExecution::STATUS_RUNNING,
        ]);
        $arrowRun = BotConstructorArrowRun::factory()->create([
            'bot_constructor_execution_id' => $execution->id,
            'bot_constructor_arrow_id' => $arrow->id,
            'dialog_id' => $dialog->id,
            'source_block_id' => $sourceBlock->id,
            'target_block_id' => $targetBlock->id,
            'inbound_message_id' => $message->id,
            'status' => BotConstructorArrowRun::STATUS_PROCESSING,
        ]);
        $blockRun = BotConstructorExecutionBlockRun::factory()->create([
            'bot_constructor_execution_id' => $execution->id,
            'bot_constructor_block_id' => $targetBlock->id,
            'bot_constructor_arrow_run_id' => $arrowRun->id,
            'dialog_id' => $dialog->id,
            'channel_id' => $channel->id,
            'sequence_number' => 1,
            'status' => BotConstructorExecutionBlockRun::STATUS_PROCESSING,
        ]);
        $dialogState = BotConstructorDialogState::factory()->create([
            'dialog_id' => $dialog->id,
            'current_block_id' => $targetBlock->id,
            'last_execution_id' => $execution->id,
        ]);

        $this->assertTrue($sourceBlock->sourceArrows()->whereKey($arrow->id)->exists());
        $this->assertTrue($targetBlock->targetArrows()->whereKey($arrow->id)->exists());
        $this->assertTrue($execution->arrowRuns()->whereKey($arrowRun->id)->exists());
        $this->assertTrue($execution->blockRuns()->whereKey($blockRun->id)->exists());
        $this->assertSame($targetBlock->id, $dialogState->currentBlock->id);
        $this->assertSame($execution->id, $dialogState->lastExecution->id);
    }

    public function test_delayed_child_execution_can_reference_parent_arrow_run(): void
    {
        $dialog = Dialog::factory()->create();
        $sourceBlock = BotConstructorBlock::factory()->create();
        $targetBlock = BotConstructorBlock::factory()->create();
        $parentExecution = BotConstructorExecution::factory()->completed()->create([
            'dialog_id' => $dialog->id,
            'channel_id' => $dialog->channel_id,
        ]);
        $arrow = BotConstructorArrow::factory()->delayed()->create([
            'source_block_id' => $sourceBlock->id,
            'target_block_id' => $targetBlock->id,
        ]);
        $arrowRun = BotConstructorArrowRun::factory()->scheduled()->create([
            'bot_constructor_execution_id' => $parentExecution->id,
            'bot_constructor_arrow_id' => $arrow->id,
            'dialog_id' => $dialog->id,
            'source_block_id' => $sourceBlock->id,
            'target_block_id' => $targetBlock->id,
        ]);

        $childExecution = BotConstructorExecution::factory()->create([
            'parent_execution_id' => $parentExecution->id,
            'started_by_arrow_run_id' => $arrowRun->id,
            'dialog_id' => $dialog->id,
            'channel_id' => $dialog->channel_id,
            'trigger_type' => BotConstructorExecution::TRIGGER_SCHEDULED_ARROW,
        ]);

        $this->assertSame($parentExecution->id, $childExecution->parentExecution->id);
        $this->assertSame($arrowRun->id, $childExecution->startedByArrowRun->id);
    }

    public function test_execution_block_run_sequence_number_is_unique_inside_execution(): void
    {
        $dialog = Dialog::factory()->create();
        $channel = $dialog->channel;
        $execution = BotConstructorExecution::factory()->create([
            'dialog_id' => $dialog->id,
            'channel_id' => $channel->id,
        ]);
        $block = BotConstructorBlock::factory()->create();

        BotConstructorExecutionBlockRun::factory()->create([
            'bot_constructor_execution_id' => $execution->id,
            'bot_constructor_block_id' => $block->id,
            'dialog_id' => $dialog->id,
            'channel_id' => $channel->id,
            'sequence_number' => 1,
        ]);

        $this->expectException(QueryException::class);

        BotConstructorExecutionBlockRun::factory()->create([
            'bot_constructor_execution_id' => $execution->id,
            'bot_constructor_block_id' => $block->id,
            'dialog_id' => $dialog->id,
            'channel_id' => $channel->id,
            'sequence_number' => 1,
        ]);
    }

    public function test_soft_deleted_arrow_keeps_existing_runs(): void
    {
        $dialog = Dialog::factory()->create();
        $sourceBlock = BotConstructorBlock::factory()->create();
        $targetBlock = BotConstructorBlock::factory()->create();
        $execution = BotConstructorExecution::factory()->create([
            'dialog_id' => $dialog->id,
            'channel_id' => $dialog->channel_id,
        ]);
        $arrow = BotConstructorArrow::factory()->create([
            'source_block_id' => $sourceBlock->id,
            'target_block_id' => $targetBlock->id,
        ]);
        $run = BotConstructorArrowRun::factory()->create([
            'bot_constructor_execution_id' => $execution->id,
            'bot_constructor_arrow_id' => $arrow->id,
            'dialog_id' => $dialog->id,
            'source_block_id' => $sourceBlock->id,
            'target_block_id' => $targetBlock->id,
        ]);

        $arrow->delete();

        $this->assertSoftDeleted('bot_constructor_arrows', ['id' => $arrow->id]);
        $this->assertDatabaseHas('bot_constructor_arrow_runs', ['id' => $run->id]);
    }

    private function assertValidationFails(callable $callback, string $field): void
    {
        try {
            $callback();
            $this->fail('Expected validation to fail for '.$field.'.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }
}
