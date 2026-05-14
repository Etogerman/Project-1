<?php

namespace App\Console\Commands;

use App\Models\BotConstructorArrow;
use App\Models\BotConstructorArrowRun;
use App\Models\BotConstructorBlock;
use App\Models\BotConstructorExecution;
use App\Models\BotConstructorExecutionBlockRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupBotConstructorProcessingRunsCommand extends Command
{
    protected $signature = 'bot-constructor:cleanup-processing-runs
        {--timeout=15 : Сколько минут processing-запись считается рабочей}';

    protected $description = 'Cleanup stale bot constructor processing runs.';

    public function handle(): int
    {
        $timeoutMinutes = max((int) $this->option('timeout'), 1);
        $threshold = now()->subMinutes($timeoutMinutes);

        $arrowCount = $this->cleanupArrowRuns($threshold);
        $blockCount = $this->cleanupBlockRuns($threshold);

        $this->info("Очищено зависших стрелок: {$arrowCount}.");
        $this->info("Очищено зависших блоков: {$blockCount}.");

        return self::SUCCESS;
    }

    private function cleanupArrowRuns(mixed $threshold): int
    {
        $ids = BotConstructorArrowRun::query()
            ->where('status', BotConstructorArrowRun::STATUS_PROCESSING)
            ->whereNotNull('processing_started_at')
            ->where('processing_started_at', '<=', $threshold)
            ->orderBy('id')
            ->pluck('id');

        $count = 0;

        foreach ($ids as $id) {
            DB::transaction(function () use ($id, &$count): void {
                $run = BotConstructorArrowRun::query()
                    ->whereKey($id)
                    ->lockForUpdate()
                    ->first();

                if (! $run instanceof BotConstructorArrowRun || $run->status !== BotConstructorArrowRun::STATUS_PROCESSING) {
                    return;
                }

                $shouldCancelBeforeBlockRun = $this->shouldCancelBeforeBlockRun($run);
                $relatedBlockRunStatus = $this->relatedBlockRunStatus($run);
                $status = match (true) {
                    $shouldCancelBeforeBlockRun => BotConstructorArrowRun::STATUS_CANCELLED,
                    in_array($relatedBlockRunStatus, [
                        BotConstructorExecutionBlockRun::STATUS_SENT,
                        BotConstructorExecutionBlockRun::STATUS_NO_REPLY,
                    ], true) => BotConstructorArrowRun::STATUS_PASSED,
                    in_array($relatedBlockRunStatus, [
                        BotConstructorExecutionBlockRun::STATUS_PROCESSING,
                        BotConstructorExecutionBlockRun::STATUS_DELIVERY_UNCERTAIN,
                    ], true) => BotConstructorArrowRun::STATUS_DELIVERY_UNCERTAIN,
                    default => BotConstructorArrowRun::STATUS_FAILED,
                };
                $errorMessage = match ($status) {
                    BotConstructorArrowRun::STATUS_PASSED => null,
                    BotConstructorArrowRun::STATUS_CANCELLED => 'Стрелка или блок удалены, либо выключены во время выполнения.',
                    BotConstructorArrowRun::STATUS_DELIVERY_UNCERTAIN => 'Доставка целевого блока не подтверждена: выполнение стрелки зависло и было остановлено по таймауту.',
                    default => 'Выполнение стрелки зависло и было остановлено по таймауту.',
                };

                $run->forceFill([
                    'status' => $status,
                    'processing_started_at' => null,
                    'error_message' => $errorMessage,
                ])->save();

                if ($status !== BotConstructorArrowRun::STATUS_PASSED) {
                    $this->failRelatedExecution($run);
                }
                $count++;
            });
        }

        return $count;
    }

    private function shouldCancelBeforeBlockRun(BotConstructorArrowRun $run): bool
    {
        if ($this->relatedBlockRunStatus($run) !== null) {
            return false;
        }

        $arrow = BotConstructorArrow::withTrashed()->find($run->bot_constructor_arrow_id);
        $sourceBlock = $run->sourceBlock;
        $targetBlock = $run->targetBlock;

        return ! ($arrow instanceof BotConstructorArrow)
            || $arrow->trashed()
            || ! $arrow->is_active
            || ! ($sourceBlock instanceof BotConstructorBlock)
            || $sourceBlock->trashed()
            || ! ($targetBlock instanceof BotConstructorBlock)
            || $targetBlock->trashed()
            || ! $targetBlock->is_active;
    }

    private function relatedBlockRunStatus(BotConstructorArrowRun $run): ?string
    {
        return BotConstructorExecutionBlockRun::query()
            ->where('bot_constructor_arrow_run_id', $run->id)
            ->orderByDesc('id')
            ->value('status');
    }

    private function cleanupBlockRuns(mixed $threshold): int
    {
        $ids = BotConstructorExecutionBlockRun::query()
            ->where('status', BotConstructorExecutionBlockRun::STATUS_PROCESSING)
            ->whereNotNull('processing_started_at')
            ->where('processing_started_at', '<=', $threshold)
            ->orderBy('id')
            ->pluck('id');

        $count = 0;

        foreach ($ids as $id) {
            DB::transaction(function () use ($id, &$count): void {
                $run = BotConstructorExecutionBlockRun::query()
                    ->whereKey($id)
                    ->lockForUpdate()
                    ->first();

                if (! $run instanceof BotConstructorExecutionBlockRun || $run->status !== BotConstructorExecutionBlockRun::STATUS_PROCESSING) {
                    return;
                }

                $run->forceFill([
                    'status' => BotConstructorExecutionBlockRun::STATUS_DELIVERY_UNCERTAIN,
                    'processing_started_at' => null,
                    'error_message' => 'Доставка не подтверждена: выполнение блока зависло и было остановлено по таймауту.',
                ])->save();

                $execution = BotConstructorExecution::query()
                    ->whereKey($run->bot_constructor_execution_id)
                    ->lockForUpdate()
                    ->first();

                if ($execution instanceof BotConstructorExecution && $execution->status === BotConstructorExecution::STATUS_RUNNING) {
                    $execution->forceFill([
                        'status' => BotConstructorExecution::STATUS_FAILED,
                    ])->save();
                }

                $count++;
            });
        }

        return $count;
    }

    private function failRelatedExecution(BotConstructorArrowRun $run): void
    {
        $execution = BotConstructorExecution::query()
            ->where('started_by_arrow_run_id', $run->id)
            ->where('status', BotConstructorExecution::STATUS_RUNNING)
            ->lockForUpdate()
            ->first();

        if (! $execution instanceof BotConstructorExecution) {
            $execution = BotConstructorExecution::query()
                ->whereKey($run->bot_constructor_execution_id)
                ->where('status', BotConstructorExecution::STATUS_RUNNING)
                ->lockForUpdate()
                ->first();
        }

        if ($execution instanceof BotConstructorExecution) {
            $execution->forceFill([
                'status' => BotConstructorExecution::STATUS_FAILED,
            ])->save();
        }
    }
}
