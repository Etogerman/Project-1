<?php

namespace App\Services\Bots;

use App\Jobs\ProcessBotConstructorScheduledArrowJob;
use App\Models\BotConstructorArrow;
use App\Models\BotConstructorArrowRun;
use App\Models\BotConstructorBlock;
use App\Models\BotConstructorConstant;
use App\Models\BotConstructorExecution;
use App\Models\BotConstructorExecutionBlockRun;
use App\Models\Dialog;
use App\Models\Message;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessBotConstructorArrowsAction
{
    private const MAX_AUTO_TRANSITIONS_PER_EXECUTION = 30;

    public function __construct(
        private readonly CreateBotConstructorExecutionBlockRunAction $createExecutionBlockRunAction,
        private readonly ExecuteBotConstructorBlockAction $executeBotConstructorBlockAction,
    ) {}

    public function handle(
        BotConstructorExecution $execution,
        Dialog $dialog,
        Message $rootMessage,
        BotConstructorBlock $sourceBlock,
        ?array $onlyArrowIds = null,
    ): void {
        $sourceBlock->loadMissing('sourceArrows.targetBlock');

        $arrowQuery = $sourceBlock->sourceArrows()
            ->active()
            ->with('targetBlock')
            ->orderBy('priority')
            ->orderBy('id');

        if ($onlyArrowIds !== null) {
            $onlyArrowIds = array_values(array_unique(array_map('intval', $onlyArrowIds)));

            if ($onlyArrowIds === []) {
                return;
            }

            $arrowQuery->whereIn('id', $onlyArrowIds);
        }

        $arrows = $arrowQuery->get();

        foreach ($arrows as $arrow) {
            $execution->refresh();

            if ($execution->status !== BotConstructorExecution::STATUS_RUNNING) {
                return;
            }

            if (! $this->canUseArrowTarget($arrow) || ! $this->conditionMatches($arrow, $execution, $rootMessage)) {
                continue;
            }

            if ($arrow->delayInSeconds() > 0) {
                $this->scheduleDelayedArrow($execution, $dialog, $rootMessage, $arrow);

                if ($execution->fresh()?->status === BotConstructorExecution::STATUS_STOPPED_BY_LIMIT) {
                    return;
                }

                continue;
            }

            $prepared = $this->prepareImmediateArrow($execution, $dialog, $rootMessage, $arrow);

            if ($prepared['stop'] === true) {
                return;
            }

            $arrowRun = $prepared['arrow_run'];
            $blockRun = $prepared['block_run'];

            if (! $arrowRun instanceof BotConstructorArrowRun || ! $blockRun instanceof BotConstructorExecutionBlockRun) {
                continue;
            }

            $targetBlock = $arrow->targetBlock;

            if (! $targetBlock instanceof BotConstructorBlock) {
                $this->markArrowFailed($arrowRun, 'Целевой блок не найден.');

                continue;
            }

            $blockRun = $this->executeBotConstructorBlockAction->handle(
                $rootMessage,
                $dialog,
                $targetBlock,
                $blockRun,
            );

            if ($this->blockRunSucceeded($blockRun)) {
                $this->markArrowPassed($arrowRun);
                $this->handle($execution, $dialog, $rootMessage, $targetBlock);

                continue;
            }

            if ($this->blockRunDeliveryUncertain($blockRun)) {
                $this->markArrowDeliveryUncertain(
                    $arrowRun,
                    $blockRun->error_message ?: 'Доставка целевого блока не подтверждена.',
                );

                continue;
            }

            $this->markArrowFailed(
                $arrowRun,
                $blockRun->error_message ?: 'Целевой блок не был успешно выполнен.',
            );
        }
    }

    /**
     * @return array{stop:bool,arrow_run:?BotConstructorArrowRun,block_run:?BotConstructorExecutionBlockRun}
     */
    private function prepareImmediateArrow(
        BotConstructorExecution $execution,
        Dialog $dialog,
        Message $rootMessage,
        BotConstructorArrow $arrow,
    ): array {
        return DB::transaction(function () use ($execution, $dialog, $rootMessage, $arrow): array {
            $lockedExecution = $this->lockExecution($execution);

            if ($this->emergencyLimitReached($lockedExecution)) {
                $this->createLimitReachedRun($lockedExecution, $dialog, $rootMessage, $arrow, 'Достигнут аварийный лимит выполнения цепочки.');
                $lockedExecution->forceFill([
                    'status' => BotConstructorExecution::STATUS_STOPPED_BY_LIMIT,
                ])->save();
                $execution->setRawAttributes($lockedExecution->getAttributes(), true);

                return ['stop' => true, 'arrow_run' => null, 'block_run' => null];
            }

            $passLimit = $this->resolvePassLimit($arrow);

            if ($passLimit['error'] !== null) {
                $this->createFailedRun($lockedExecution, $dialog, $rootMessage, $arrow, $passLimit['error']);

                return ['stop' => false, 'arrow_run' => null, 'block_run' => null];
            }

            $this->lockArrowDialogLimit($arrow, $dialog);

            if ($this->arrowPassLimitReached($arrow, $dialog, (int) $passLimit['value'])) {
                $this->createLimitReachedRun($lockedExecution, $dialog, $rootMessage, $arrow, 'Достигнут лимит переходов клиента по этой стрелке.');

                return ['stop' => false, 'arrow_run' => null, 'block_run' => null];
            }

            $now = now();
            $arrowRun = $this->createArrowRun(
                $lockedExecution,
                $dialog,
                $rootMessage,
                $arrow,
                BotConstructorArrowRun::STATUS_PROCESSING,
                processingStartedAt: $now,
            );

            $lockedExecution->forceFill([
                'auto_transition_count' => (int) $lockedExecution->auto_transition_count + 1,
            ])->save();
            $execution->setRawAttributes($lockedExecution->getAttributes(), true);

            $blockRun = $this->createExecutionBlockRunAction->handle(
                $lockedExecution,
                $dialog,
                $dialog->channel,
                $arrow->targetBlock,
                BotConstructorExecutionBlockRun::STATUS_PROCESSING,
                $arrowRun,
                processingStartedAt: $now,
            );

            return ['stop' => false, 'arrow_run' => $arrowRun, 'block_run' => $blockRun];
        });
    }

    private function scheduleDelayedArrow(
        BotConstructorExecution $execution,
        Dialog $dialog,
        Message $rootMessage,
        BotConstructorArrow $arrow,
    ): void {
        $arrowRun = DB::transaction(function () use ($execution, $dialog, $rootMessage, $arrow): ?BotConstructorArrowRun {
            $lockedExecution = $this->lockExecution($execution);

            if ($this->emergencyLimitReached($lockedExecution)) {
                $this->createLimitReachedRun($lockedExecution, $dialog, $rootMessage, $arrow, 'Достигнут аварийный лимит выполнения цепочки.');
                $lockedExecution->forceFill([
                    'status' => BotConstructorExecution::STATUS_STOPPED_BY_LIMIT,
                ])->save();
                $execution->setRawAttributes($lockedExecution->getAttributes(), true);

                return null;
            }

            $passLimit = $this->resolvePassLimit($arrow);

            if ($passLimit['error'] !== null) {
                $this->createFailedRun($lockedExecution, $dialog, $rootMessage, $arrow, $passLimit['error']);

                return null;
            }

            $this->lockArrowDialogLimit($arrow, $dialog);

            if ($this->arrowPassLimitReached($arrow, $dialog, (int) $passLimit['value'])) {
                $this->createLimitReachedRun($lockedExecution, $dialog, $rootMessage, $arrow, 'Достигнут лимит переходов клиента по этой стрелке.');

                return null;
            }

            $scheduledFor = now()->addSeconds($arrow->delayInSeconds());
            $arrowRun = $this->createArrowRun(
                $lockedExecution,
                $dialog,
                $rootMessage,
                $arrow,
                BotConstructorArrowRun::STATUS_SCHEDULED,
                scheduledFor: $scheduledFor,
            );

            $lockedExecution->forceFill([
                'auto_transition_count' => (int) $lockedExecution->auto_transition_count + 1,
            ])->save();
            $execution->setRawAttributes($lockedExecution->getAttributes(), true);

            return $arrowRun;
        });

        if (! $arrowRun instanceof BotConstructorArrowRun) {
            return;
        }

        try {
            ProcessBotConstructorScheduledArrowJob::dispatch($arrowRun->id)->delay($arrowRun->scheduled_for);
        } catch (Throwable $throwable) {
            report($throwable);
        }
    }

    private function lockExecution(BotConstructorExecution $execution): BotConstructorExecution
    {
        return BotConstructorExecution::query()
            ->whereKey($execution->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function emergencyLimitReached(BotConstructorExecution $execution): bool
    {
        return (int) $execution->auto_transition_count >= self::MAX_AUTO_TRANSITIONS_PER_EXECUTION;
    }

    /**
     * @return array{value:?int,error:?string}
     */
    private function resolvePassLimit(BotConstructorArrow $arrow): array
    {
        if ($arrow->pass_limit_mode === BotConstructorArrow::PASS_LIMIT_MODE_MANUAL) {
            $value = (int) $arrow->pass_limit_value;

            return $value > 0
                ? ['value' => $value, 'error' => null]
                : ['value' => null, 'error' => 'Ручной лимит переходов должен быть больше 0.'];
        }

        $constant = BotConstructorConstant::query()
            ->where('key', BotConstructorConstant::KEY_ARROW_PASS_LIMIT)
            ->first();

        $value = $constant?->integerValue();

        return $value !== null && $value > 0
            ? ['value' => $value, 'error' => null]
            : ['value' => null, 'error' => 'Константа лимита переходов не найдена или имеет неверное значение.'];
    }

    private function lockArrowDialogLimit(BotConstructorArrow $arrow, Dialog $dialog): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::select('select pg_advisory_xact_lock(?, ?)', [
            $this->postgresLockPart((int) $arrow->id),
            $this->postgresLockPart((int) $dialog->id),
        ]);
    }

    private function postgresLockPart(int $value): int
    {
        return $value % 2147483647;
    }

    private function arrowPassLimitReached(BotConstructorArrow $arrow, Dialog $dialog, int $passLimit): bool
    {
        $used = BotConstructorArrowRun::query()
            ->where('bot_constructor_arrow_id', $arrow->id)
            ->where('dialog_id', $dialog->id)
            ->whereIn('status', BotConstructorArrowRun::limitCountedStatuses())
            ->count();

        return $used >= $passLimit;
    }

    private function canUseArrowTarget(BotConstructorArrow $arrow): bool
    {
        return $arrow->targetBlock instanceof BotConstructorBlock
            && (bool) $arrow->targetBlock->is_active;
    }

    private function conditionMatches(BotConstructorArrow $arrow, BotConstructorExecution $execution, Message $rootMessage): bool
    {
        if ($arrow->condition_match_type === BotConstructorArrow::CONDITION_ALWAYS) {
            return true;
        }

        if ($execution->trigger_type !== BotConstructorExecution::TRIGGER_INBOUND) {
            return false;
        }

        $text = BotConstructorBlock::normalizeComparable($rootMessage->text);
        $condition = BotConstructorBlock::normalizeComparable($arrow->condition_value);

        if (! filled($text) || ! filled($condition)) {
            return false;
        }

        return match ($arrow->condition_match_type) {
            BotConstructorArrow::CONDITION_EXACT_TEXT => $text === $condition,
            BotConstructorArrow::CONDITION_CONTAINS_TEXT => str_contains((string) $text, (string) $condition),
            default => false,
        };
    }

    private function createArrowRun(
        BotConstructorExecution $execution,
        Dialog $dialog,
        Message $rootMessage,
        BotConstructorArrow $arrow,
        string $status,
        ?Carbon $scheduledFor = null,
        ?Carbon $processingStartedAt = null,
        ?string $errorMessage = null,
    ): BotConstructorArrowRun {
        return BotConstructorArrowRun::query()->create([
            'bot_constructor_execution_id' => $execution->id,
            'bot_constructor_arrow_id' => $arrow->id,
            'dialog_id' => $dialog->id,
            'source_block_id' => $arrow->source_block_id,
            'target_block_id' => $arrow->target_block_id,
            'inbound_message_id' => $rootMessage->id,
            'scheduled_for' => $scheduledFor,
            'processing_started_at' => $processingStartedAt,
            'status' => $status,
            'error_message' => $this->safeErrorMessage($errorMessage),
        ]);
    }

    private function createFailedRun(
        BotConstructorExecution $execution,
        Dialog $dialog,
        Message $rootMessage,
        BotConstructorArrow $arrow,
        string $errorMessage,
    ): BotConstructorArrowRun {
        return $this->createArrowRun(
            $execution,
            $dialog,
            $rootMessage,
            $arrow,
            BotConstructorArrowRun::STATUS_FAILED,
            errorMessage: $errorMessage,
        );
    }

    private function createLimitReachedRun(
        BotConstructorExecution $execution,
        Dialog $dialog,
        Message $rootMessage,
        BotConstructorArrow $arrow,
        string $errorMessage,
    ): BotConstructorArrowRun {
        return $this->createArrowRun(
            $execution,
            $dialog,
            $rootMessage,
            $arrow,
            BotConstructorArrowRun::STATUS_LIMIT_REACHED,
            errorMessage: $errorMessage,
        );
    }

    public function markArrowPassed(BotConstructorArrowRun $arrowRun): void
    {
        $arrowRun->forceFill([
            'status' => BotConstructorArrowRun::STATUS_PASSED,
            'processing_started_at' => null,
            'error_message' => null,
        ])->save();
    }

    public function markArrowFailed(BotConstructorArrowRun $arrowRun, string $errorMessage): void
    {
        $arrowRun->forceFill([
            'status' => BotConstructorArrowRun::STATUS_FAILED,
            'processing_started_at' => null,
            'error_message' => $this->safeErrorMessage($errorMessage),
        ])->save();
    }

    public function markArrowDeliveryUncertain(BotConstructorArrowRun $arrowRun, string $errorMessage): void
    {
        $arrowRun->forceFill([
            'status' => BotConstructorArrowRun::STATUS_DELIVERY_UNCERTAIN,
            'processing_started_at' => null,
            'error_message' => $this->safeErrorMessage($errorMessage),
        ])->save();
    }

    public function blockRunSucceeded(BotConstructorExecutionBlockRun $blockRun): bool
    {
        return in_array($blockRun->status, [
            BotConstructorExecutionBlockRun::STATUS_SENT,
            BotConstructorExecutionBlockRun::STATUS_NO_REPLY,
        ], true);
    }

    public function blockRunDeliveryUncertain(BotConstructorExecutionBlockRun $blockRun): bool
    {
        return $blockRun->status === BotConstructorExecutionBlockRun::STATUS_DELIVERY_UNCERTAIN;
    }

    private function safeErrorMessage(?string $message): ?string
    {
        if (! filled($message)) {
            return null;
        }

        return mb_substr(trim((string) $message), 0, 1000);
    }
}
