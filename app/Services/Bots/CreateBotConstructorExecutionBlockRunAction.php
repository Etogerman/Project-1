<?php

namespace App\Services\Bots;

use App\Models\BotConstructorArrowRun;
use App\Models\BotConstructorBlock;
use App\Models\BotConstructorExecution;
use App\Models\BotConstructorExecutionBlockRun;
use App\Models\Channel;
use App\Models\Dialog;

class CreateBotConstructorExecutionBlockRunAction
{
    public function handle(
        BotConstructorExecution $execution,
        Dialog $dialog,
        Channel $channel,
        BotConstructorBlock $block,
        string $status,
        ?BotConstructorArrowRun $arrowRun = null,
        ?string $errorMessage = null,
        mixed $processingStartedAt = null,
        ?int $outboundMessageId = null,
    ): BotConstructorExecutionBlockRun {
        $lockedExecution = BotConstructorExecution::query()
            ->whereKey($execution->id)
            ->lockForUpdate()
            ->firstOrFail();
        $sequenceNumber = (int) $lockedExecution->next_sequence_number;

        $lockedExecution->forceFill([
            'next_sequence_number' => $sequenceNumber + 1,
        ])->save();

        $execution->setRawAttributes($lockedExecution->getAttributes(), true);

        return BotConstructorExecutionBlockRun::query()->create([
            'bot_constructor_execution_id' => $lockedExecution->id,
            'bot_constructor_block_id' => $block->id,
            'bot_constructor_arrow_run_id' => $arrowRun?->id,
            'dialog_id' => $dialog->id,
            'channel_id' => $channel->id,
            'sequence_number' => $sequenceNumber,
            'status' => $status,
            'outbound_message_id' => $outboundMessageId,
            'processing_started_at' => $processingStartedAt,
            'error_message' => $errorMessage,
        ]);
    }
}
