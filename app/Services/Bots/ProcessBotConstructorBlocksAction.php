<?php

namespace App\Services\Bots;

use App\Models\BotConstructorArrowRun;
use App\Models\BotConstructorBlock;
use App\Models\BotConstructorBlockRun;
use App\Models\BotConstructorDialogState;
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
                $recoveredExecution = $this->processMissingOutgoingArrowsForExistingTrace($message, $dialog, $block);

                if ($recoveredExecution instanceof BotConstructorExecution) {
                    $execution = $recoveredExecution;
                }

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
            }

            if ($this->shouldProcessOutgoingArrows($executionBlockRun)) {
                $arrowIds = $this->outgoingArrowIdsAvailableAt($block, $legacyRun->created_at);

                if ($arrowIds !== []) {
                    $this->processBotConstructorArrowsAction->handle(
                        $execution,
                        $dialog,
                        $message,
                        $block,
                        $arrowIds,
                        $legacyRun->created_at,
                        sourceExecutionBlockRun: $executionBlockRun,
                    );
                }
            }
        }

        $this->recoverMissingOutgoingArrowsForSuccessfulExecutionBlockRuns($message, $dialog);
        $this->completeRunningExecutionsForMessageIfReady($message);

        return true;
    }

    public function recoverAlreadyProcessedInbound(Message $message): bool
    {
        $message->loadMissing(['channel', 'dialog']);

        $channel = $message->channel;
        $dialog = $message->dialog;

        if (! $channel instanceof Channel || ! $dialog instanceof Dialog) {
            return false;
        }

        $legacyRuns = BotConstructorBlockRun::query()
            ->where('inbound_message_id', $message->id)
            ->orderBy('bot_constructor_block_id')
            ->get();

        if ($legacyRuns->isEmpty()) {
            return false;
        }

        $execution = $this->runningInboundExecution($message);
        $handled = false;

        foreach ($legacyRuns as $legacyRun) {
            $block = BotConstructorBlock::query()->find($legacyRun->bot_constructor_block_id);

            if (! $block instanceof BotConstructorBlock) {
                continue;
            }

            if ($this->hasExecutionTrace($message, $block)) {
                $recoveredExecution = $this->processMissingOutgoingArrowsForExistingTrace($message, $dialog, $block);

                if ($recoveredExecution instanceof BotConstructorExecution) {
                    $execution = $recoveredExecution;
                    $handled = true;
                }

                continue;
            }

            $execution = $this->ensureExecution($execution, $message, $channel, $dialog);
            $executionBlockRun = $this->createRecoveryExecutionBlockRun($execution, $dialog, $channel, $block, $legacyRun);
            $handled = true;

            if ($this->shouldProcessOutgoingArrows($executionBlockRun)) {
                $arrowIds = $this->outgoingArrowIdsAvailableAt($block, $legacyRun->created_at);

                if ($arrowIds !== []) {
                    $this->processBotConstructorArrowsAction->handle(
                        $execution,
                        $dialog,
                        $message,
                        $block,
                        $arrowIds,
                        $legacyRun->created_at,
                        true,
                        $executionBlockRun,
                    );
                }
            }
        }

        $handled = $this->recoverMissingOutgoingArrowsForSuccessfulExecutionBlockRuns($message, $dialog) || $handled;
        $this->completeRunningExecutionsForMessageIfReady($message);

        return $handled;
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
    ): ?BotConstructorExecution {
        if (! $block->sourceArrows()->active()->exists()) {
            return null;
        }

        $executionBlockRun = $this->successfulDirectExecutionBlockRun($message, $block);
        $execution = $executionBlockRun?->execution;

        if (! $executionBlockRun instanceof BotConstructorExecutionBlockRun || ! $execution instanceof BotConstructorExecution) {
            return null;
        }

        if ($execution->status !== BotConstructorExecution::STATUS_RUNNING) {
            return null;
        }

        $legacyRun = BotConstructorBlockRun::query()
            ->where('inbound_message_id', $message->id)
            ->where('bot_constructor_block_id', $block->id)
            ->first();
        $cutoff = $legacyRun?->created_at ?? $executionBlockRun->created_at;
        $missingArrowIds = $this->missingOutgoingArrowIds($executionBlockRun, $cutoff);

        if ($missingArrowIds === []) {
            return $execution;
        }

        $this->processBotConstructorArrowsAction->handle($execution, $dialog, $message, $block, $missingArrowIds, $cutoff, true, $executionBlockRun);

        return $execution;
    }

    private function recoverMissingOutgoingArrowsForSuccessfulExecutionBlockRuns(Message $message, Dialog $dialog): bool
    {
        $runs = BotConstructorExecutionBlockRun::query()
            ->with(['arrowRun', 'block', 'execution'])
            ->whereIn('status', [
                BotConstructorExecutionBlockRun::STATUS_SENT,
                BotConstructorExecutionBlockRun::STATUS_NO_REPLY,
            ])
            ->whereHas('execution', function ($query) use ($message): void {
                $query
                    ->where('root_inbound_message_id', $message->id)
                    ->where('status', BotConstructorExecution::STATUS_RUNNING);
            })
            ->orderBy('bot_constructor_execution_id')
            ->orderBy('sequence_number')
            ->orderBy('id')
            ->get();

        $handled = false;

        foreach ($runs as $run) {
            $execution = $run->execution;
            $block = $run->block;

            if (
                ! $execution instanceof BotConstructorExecution
                || ! $block instanceof BotConstructorBlock
                || $block->trashed()
            ) {
                continue;
            }

            $cutoff = $this->recoveryCutoffForExecutionBlockRun($message, $run);
            $missingArrowIds = $this->missingOutgoingArrowIds($run, $cutoff);

            if ($missingArrowIds === []) {
                continue;
            }

            $this->processBotConstructorArrowsAction->handle($execution, $dialog, $message, $block, $missingArrowIds, $cutoff, true, $run);
            $handled = true;
        }

        return $handled;
    }

    private function recoveryCutoffForExecutionBlockRun(Message $message, BotConstructorExecutionBlockRun $run): mixed
    {
        $arrowRun = $run->arrowRun;

        if ($arrowRun instanceof BotConstructorArrowRun && $arrowRun->schema_cutoff_at !== null) {
            return $arrowRun->schema_cutoff_at;
        }

        if ($arrowRun instanceof BotConstructorArrowRun) {
            return $run->created_at;
        }

        $legacyRun = BotConstructorBlockRun::query()
            ->where('inbound_message_id', $message->id)
            ->where('bot_constructor_block_id', $run->bot_constructor_block_id)
            ->first();

        return $legacyRun?->created_at ?? $run->created_at;
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

    /**
     * @return list<int>
     */
    private function missingOutgoingArrowIds(BotConstructorExecutionBlockRun $executionBlockRun, mixed $cutoff): array
    {
        $block = $executionBlockRun->block;

        if (! $block instanceof BotConstructorBlock || $block->trashed()) {
            return [];
        }

        $expectedArrowIds = $this->outgoingArrowIdsAvailableAt($block, $cutoff);

        if ($expectedArrowIds === []) {
            return [];
        }

        $existingArrowIds = BotConstructorArrowRun::query()
            ->where('bot_constructor_execution_id', $executionBlockRun->bot_constructor_execution_id)
            ->where('source_execution_block_run_id', $executionBlockRun->id)
            ->where('source_block_id', $executionBlockRun->bot_constructor_block_id)
            ->whereIn('bot_constructor_arrow_id', $expectedArrowIds)
            ->pluck('bot_constructor_arrow_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return array_values(array_diff($expectedArrowIds, $existingArrowIds));
    }

    /**
     * @return list<int>
     */
    private function outgoingArrowIdsAvailableAt(BotConstructorBlock $block, mixed $cutoff): array
    {
        if ($block->trashed()) {
            return [];
        }

        $query = $block->sourceArrows()->active();

        if ($cutoff !== null) {
            $query
                ->where('created_at', '<=', $cutoff)
                ->where('updated_at', '<=', $cutoff)
                ->whereHas('targetBlock', function ($targetQuery) use ($cutoff): void {
                    $targetQuery
                        ->whereNull('deleted_at')
                        ->where('updated_at', '<=', $cutoff);
                });
        }

        return $query
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
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

    private function completeExecutionIfReady(?BotConstructorExecution $execution): void
    {
        if (
            $execution instanceof BotConstructorExecution
            && $execution->status === BotConstructorExecution::STATUS_RUNNING
            && ! $this->hasProcessingExecutionBlockRuns($execution)
        ) {
            $execution->forceFill([
                'status' => BotConstructorExecution::STATUS_COMPLETED,
            ])->save();
        }
    }

    private function completeRunningExecutionsForMessageIfReady(Message $message): void
    {
        BotConstructorExecution::query()
            ->where('root_inbound_message_id', $message->id)
            ->where('status', BotConstructorExecution::STATUS_RUNNING)
            ->orderBy('id')
            ->get()
            ->each(fn (BotConstructorExecution $execution): mixed => $this->completeExecutionIfReady($execution));
    }

    private function createRecoveryExecutionBlockRun(
        BotConstructorExecution $execution,
        Dialog $dialog,
        Channel $channel,
        BotConstructorBlock $block,
        BotConstructorBlockRun $legacyRun,
    ): BotConstructorExecutionBlockRun {
        $status = match ($legacyRun->status) {
            BotConstructorBlockRun::STATUS_SENT => BotConstructorExecutionBlockRun::STATUS_SENT,
            BotConstructorBlockRun::STATUS_NO_REPLY => BotConstructorExecutionBlockRun::STATUS_NO_REPLY,
            BotConstructorBlockRun::STATUS_FAILED => BotConstructorExecutionBlockRun::STATUS_FAILED,
            default => BotConstructorExecutionBlockRun::STATUS_DELIVERY_UNCERTAIN,
        };

        $run = $this->createExecutionBlockRunAction->handle(
            $execution,
            $dialog,
            $channel,
            $block,
            $status,
            errorMessage: $status === BotConstructorExecutionBlockRun::STATUS_DELIVERY_UNCERTAIN
                ? 'Старый факт обработки уже существовал, trace восстановлен после частичного сбоя.'
                : $legacyRun->error_message,
            outboundMessageId: $legacyRun->outbound_message_id === null ? null : (int) $legacyRun->outbound_message_id,
        );

        if ($this->shouldProcessOutgoingArrows($run)) {
            BotConstructorDialogState::query()->updateOrCreate(
                ['dialog_id' => $dialog->id],
                [
                    'current_block_id' => $block->id,
                    'last_execution_id' => $execution->id,
                ],
            );
        }

        return $run;
    }

    private function wasUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (string) ($exception->errorInfo[1] ?? '');

        return in_array($sqlState, ['23000', '23505'], true)
            || in_array($driverCode, ['1062', '1555', '2067'], true);
    }
}
