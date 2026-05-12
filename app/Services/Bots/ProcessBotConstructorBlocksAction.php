<?php

namespace App\Services\Bots;

use App\Models\BotConstructorArrowRun;
use App\Models\BotConstructorBlock;
use App\Models\BotConstructorBlockRun;
use App\Models\BotConstructorExecution;
use App\Models\BotConstructorExecutionBlockRun;
use App\Models\Channel;
use App\Models\Dialog;
use App\Models\Message;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProcessBotConstructorBlocksAction
{
    private const STARTED_BUT_NOT_FINISHED = 'Срабатывание начато, но не завершено.';

    public function __construct(
        private readonly CreateBotConstructorExecutionBlockRunAction $createExecutionBlockRunAction,
        private readonly ExecuteBotConstructorBlockAction $executeBotConstructorBlockAction,
        private readonly ProcessBotConstructorArrowsAction $processBotConstructorArrowsAction,
    ) {}

    public function handle(Message $message): bool
    {
        $message->loadMissing(['channel', 'contactIdentity', 'contact', 'dialog']);

        $channel = $message->channel;
        $dialog = $message->dialog;

        if (! $channel instanceof Channel || ! $dialog instanceof Dialog) {
            return false;
        }

        $matchedBlocks = $this->matchedBlocks($message, $channel);

        if ($matchedBlocks->isEmpty()) {
            return false;
        }

        $execution = $this->runningInboundExecution($message);

        foreach ($matchedBlocks as $block) {
            $runs = $this->prepareInboundBlockRuns($message, $channel, $dialog, $block, $execution);

            if ($runs === null) {
                $this->processMissingOutgoingArrowsForExistingTrace($message, $dialog, $block);

                continue;
            }

            [$legacyRun, $executionBlockRun] = $runs;

            if ($executionBlockRun->status === BotConstructorExecutionBlockRun::STATUS_PROCESSING) {
                $executionBlockRun = $this->executeBotConstructorBlockAction->handle(
                    $message,
                    $dialog,
                    $block,
                    $executionBlockRun,
                    $legacyRun,
                );

                if ($this->shouldProcessOutgoingArrows($executionBlockRun)) {
                    $this->processBotConstructorArrowsAction->handle($execution, $dialog, $message, $block);
                }
            }
        }

        if (
            $execution instanceof BotConstructorExecution
            && $execution->status === BotConstructorExecution::STATUS_RUNNING
            && ! $this->hasProcessingExecutionBlockRuns($execution)
        ) {
            $execution->forceFill([
                'status' => BotConstructorExecution::STATUS_COMPLETED,
            ])->save();
        }

        return true;
    }

    /**
     * @return Collection<int, BotConstructorBlock>
     */
    private function matchedBlocks(Message $message, Channel $channel): Collection
    {
        return BotConstructorBlock::query()
            ->active()
            ->forChannel($channel)
            ->with('channels')
            ->orderBy('id')
            ->get()
            ->filter(fn (BotConstructorBlock $block): bool => $block->matchesMessage($message))
            ->values();
    }

    /**
     * @return array{0:BotConstructorBlockRun,1:BotConstructorExecutionBlockRun}|null
     */
    private function prepareInboundBlockRuns(
        Message $message,
        Channel $channel,
        Dialog $dialog,
        BotConstructorBlock $block,
        ?BotConstructorExecution &$execution,
    ): ?array {
        try {
            return DB::transaction(function () use ($message, $channel, $dialog, $block, &$execution): ?array {
                $legacyRun = BotConstructorBlockRun::query()
                    ->where('inbound_message_id', $message->id)
                    ->where('bot_constructor_block_id', $block->id)
                    ->lockForUpdate()
                    ->first();

                if ($legacyRun instanceof BotConstructorBlockRun) {
                    if ($this->hasExecutionTrace($message, $block)) {
                        return null;
                    }

                    $execution = $this->ensureExecution($execution, $message, $channel, $dialog);

                    return [
                        $legacyRun,
                        $this->createRecoveryExecutionBlockRun($execution, $dialog, $channel, $block, $legacyRun),
                    ];
                }

                $execution = $this->ensureExecution($execution, $message, $channel, $dialog);
                $legacyRun = BotConstructorBlockRun::query()->create([
                    'inbound_message_id' => $message->id,
                    'bot_constructor_block_id' => $block->id,
                    'status' => BotConstructorBlockRun::STATUS_FAILED,
                    'error_message' => self::STARTED_BUT_NOT_FINISHED,
                ]);
                $executionBlockRun = $this->createExecutionBlockRunAction->handle(
                    $execution,
                    $dialog,
                    $channel,
                    $block,
                    BotConstructorExecutionBlockRun::STATUS_PROCESSING,
                    null,
                    processingStartedAt: now(),
                );

                return [$legacyRun, $executionBlockRun];
            });
        } catch (QueryException $exception) {
            if ($this->wasUniqueConstraintViolation($exception)) {
                return null;
            }

            throw $exception;
        }
    }

    private function hasExecutionTrace(Message $message, BotConstructorBlock $block): bool
    {
        return BotConstructorExecutionBlockRun::query()
            ->where('bot_constructor_block_id', $block->id)
            ->whereHas('execution', function ($query) use ($message): void {
                $query->where('root_inbound_message_id', $message->id);
            })
            ->exists();
    }

    private function processMissingOutgoingArrowsForExistingTrace(
        Message $message,
        Dialog $dialog,
        BotConstructorBlock $block,
    ): void {
        if (! $block->sourceArrows()->active()->exists()) {
            return;
        }

        $executionBlockRun = $this->successfulDirectExecutionBlockRun($message, $block);
        $execution = $executionBlockRun?->execution;

        if (! $executionBlockRun instanceof BotConstructorExecutionBlockRun || ! $execution instanceof BotConstructorExecution) {
            return;
        }

        if ($execution->status !== BotConstructorExecution::STATUS_RUNNING) {
            return;
        }

        if ($this->hasOutgoingArrowRuns($executionBlockRun)) {
            return;
        }

        $this->processBotConstructorArrowsAction->handle($execution, $dialog, $message, $block);
    }

    private function successfulDirectExecutionBlockRun(
        Message $message,
        BotConstructorBlock $block,
    ): ?BotConstructorExecutionBlockRun {
        return BotConstructorExecutionBlockRun::query()
            ->with('execution')
            ->where('bot_constructor_block_id', $block->id)
            ->whereNull('bot_constructor_arrow_run_id')
            ->whereIn('status', [
                BotConstructorExecutionBlockRun::STATUS_SENT,
                BotConstructorExecutionBlockRun::STATUS_NO_REPLY,
            ])
            ->whereHas('execution', function ($query) use ($message): void {
                $query
                    ->where('root_inbound_message_id', $message->id)
                    ->where('trigger_type', BotConstructorExecution::TRIGGER_INBOUND);
            })
            ->orderBy('id')
            ->first();
    }

    private function hasOutgoingArrowRuns(BotConstructorExecutionBlockRun $executionBlockRun): bool
    {
        return BotConstructorArrowRun::query()
            ->where('bot_constructor_execution_id', $executionBlockRun->bot_constructor_execution_id)
            ->where('source_block_id', $executionBlockRun->bot_constructor_block_id)
            ->exists();
    }

    private function shouldProcessOutgoingArrows(BotConstructorExecutionBlockRun $run): bool
    {
        return in_array($run->status, [
            BotConstructorExecutionBlockRun::STATUS_SENT,
            BotConstructorExecutionBlockRun::STATUS_NO_REPLY,
        ], true);
    }

    private function ensureExecution(
        ?BotConstructorExecution $execution,
        Message $message,
        Channel $channel,
        Dialog $dialog,
    ): BotConstructorExecution {
        if ($execution instanceof BotConstructorExecution) {
            return $execution;
        }

        $existingExecution = $this->runningInboundExecution($message, true);

        if ($existingExecution instanceof BotConstructorExecution) {
            return $existingExecution;
        }

        return BotConstructorExecution::query()->create([
            'root_inbound_message_id' => $message->id,
            'parent_execution_id' => null,
            'started_by_arrow_run_id' => null,
            'dialog_id' => $dialog->id,
            'channel_id' => $channel->id,
            'trigger_type' => BotConstructorExecution::TRIGGER_INBOUND,
            'auto_transition_count' => 0,
            'next_sequence_number' => 1,
            'status' => BotConstructorExecution::STATUS_RUNNING,
        ]);
    }

    private function runningInboundExecution(Message $message, bool $lock = false): ?BotConstructorExecution
    {
        $query = BotConstructorExecution::query()
            ->where('root_inbound_message_id', $message->id)
            ->where('trigger_type', BotConstructorExecution::TRIGGER_INBOUND)
            ->where('status', BotConstructorExecution::STATUS_RUNNING);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function hasProcessingExecutionBlockRuns(BotConstructorExecution $execution): bool
    {
        return BotConstructorExecutionBlockRun::query()
            ->where('bot_constructor_execution_id', $execution->id)
            ->where('status', BotConstructorExecutionBlockRun::STATUS_PROCESSING)
            ->exists();
    }

    private function createRecoveryExecutionBlockRun(
        BotConstructorExecution $execution,
        Dialog $dialog,
        Channel $channel,
        BotConstructorBlock $block,
        BotConstructorBlockRun $legacyRun,
    ): BotConstructorExecutionBlockRun {
        return $this->createExecutionBlockRunAction->handle(
            $execution,
            $dialog,
            $channel,
            $block,
            BotConstructorExecutionBlockRun::STATUS_DELIVERY_UNCERTAIN,
            errorMessage: 'Старый факт обработки уже существовал, trace восстановлен после частичного сбоя.',
            outboundMessageId: $legacyRun->outbound_message_id === null ? null : (int) $legacyRun->outbound_message_id,
        );
    }

    private function wasUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (string) ($exception->errorInfo[1] ?? '');

        return in_array($sqlState, ['23000', '23505'], true)
            || in_array($driverCode, ['1062', '1555', '2067'], true);
    }
}
