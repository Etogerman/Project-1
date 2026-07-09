<?php

namespace App\Services\Bots;

use App\Models\BotConstructorArrow;
use App\Models\BotConstructorArrowRun;
use App\Models\BotConstructorBlock;
use App\Models\BotConstructorDialogState;
use App\Models\BotConstructorExecution;
use App\Models\BotConstructorExecutionBlockRun;
use App\Models\Channel;
use App\Models\Dialog;
use App\Models\Message;
use App\Services\Dialogs\DialogAutomationGate;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessBotConstructorScheduledArrowAction
{
    public function __construct(
        private readonly CreateBotConstructorExecutionBlockRunAction $createExecutionBlockRunAction,
        private readonly ExecuteBotConstructorBlockAction $executeBotConstructorBlockAction,
        private readonly ProcessBotConstructorArrowsAction $processBotConstructorArrowsAction,
        private readonly DialogAutomationGate $dialogAutomationGate,
    ) {}

    public function handle(int|BotConstructorArrowRun $arrowRun): void
    {
        $arrowRun = $arrowRun instanceof BotConstructorArrowRun
            ? $arrowRun
            : BotConstructorArrowRun::query()->find((int) $arrowRun);

        if (! $arrowRun instanceof BotConstructorArrowRun) {
            return;
        }

        $arrowRun = $this->claimScheduledRun($arrowRun);

        if (! $arrowRun instanceof BotConstructorArrowRun) {
            return;
        }

        $childExecution = null;

        try {
            $parentExecution = BotConstructorExecution::query()->find($arrowRun->bot_constructor_execution_id);
            $dialog = Dialog::query()->with('channel')->find($arrowRun->dialog_id);

            if (! $parentExecution instanceof BotConstructorExecution || ! $dialog instanceof Dialog || ! $dialog->channel instanceof Channel) {
                $this->failRun($arrowRun, 'Родительское выполнение, диалог или канал не найдены.');

                return;
            }

            $rootMessage = $parentExecution->rootInboundMessage;

            if (! $rootMessage instanceof Message) {
                $this->failRun($arrowRun, 'Корневое входящее сообщение не найдено.');

                return;
            }

            if (! $this->canContinueAutomation($rootMessage, $dialog)) {
                $this->cancelRun($arrowRun, 'Автоматизация остановлена: контакт недоступен для автоматизации, собирает данные или диалог находится в ЧС.');

                return;
            }

            $prepared = $this->prepareTargetBlockRun($arrowRun, $parentExecution, $dialog);

            if ($prepared === null) {
                return;
            }

            [$arrowRun, $childExecution, $targetBlock, $blockRun] = $prepared;

            $blockRun = $this->executeBotConstructorBlockAction->handle(
                $rootMessage,
                $dialog,
                $targetBlock,
                $blockRun,
            );

            if ($this->processBotConstructorArrowsAction->blockRunSucceeded($blockRun)) {
                $this->processBotConstructorArrowsAction->markArrowPassed($arrowRun);
                $this->processBotConstructorArrowsAction->handle(
                    $childExecution,
                    $dialog,
                    $rootMessage,
                    $targetBlock,
                    null,
                    $arrowRun->schema_cutoff_at,
                    $arrowRun->schema_cutoff_at !== null,
                    $blockRun,
                );
            } elseif ($this->processBotConstructorArrowsAction->blockRunDeliveryUncertain($blockRun)) {
                $this->processBotConstructorArrowsAction->markArrowDeliveryUncertain(
                    $arrowRun,
                    $blockRun->error_message ?: 'Доставка целевого блока не подтверждена.',
                );
            } else {
                $arrowRun->refresh();

                if ($arrowRun->status !== BotConstructorArrowRun::STATUS_CANCELLED) {
                    $this->processBotConstructorArrowsAction->markArrowFailed(
                        $arrowRun,
                        $blockRun->error_message ?: 'Целевой блок не был успешно выполнен.',
                    );
                }
            }

            $this->completeExecutionIfRunning($childExecution);
        } catch (Throwable $throwable) {
            report($throwable);
            $arrowRun->refresh();

            if (! in_array($arrowRun->status, [
                BotConstructorArrowRun::STATUS_PASSED,
                BotConstructorArrowRun::STATUS_DELIVERY_UNCERTAIN,
            ], true)) {
                $this->failRun($arrowRun, $throwable->getMessage());
            }

            if ($childExecution instanceof BotConstructorExecution) {
                $childExecution->forceFill([
                    'status' => BotConstructorExecution::STATUS_FAILED,
                ])->save();
            }
        }
    }

    /**
     * @return array{0:BotConstructorArrowRun,1:BotConstructorExecution,2:BotConstructorBlock,3:BotConstructorExecutionBlockRun}|null
     */
    private function prepareTargetBlockRun(
        BotConstructorArrowRun $arrowRun,
        BotConstructorExecution $parentExecution,
        Dialog $dialog,
    ): ?array {
        return DB::transaction(function () use ($arrowRun, $parentExecution, $dialog): ?array {
            $lockedRun = BotConstructorArrowRun::query()
                ->whereKey($arrowRun->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedRun instanceof BotConstructorArrowRun || $lockedRun->status !== BotConstructorArrowRun::STATUS_PROCESSING) {
                return null;
            }

            $arrow = BotConstructorArrow::withTrashed()
                ->whereKey($lockedRun->bot_constructor_arrow_id)
                ->lockForUpdate()
                ->first();
            $sourceBlock = BotConstructorBlock::withTrashed()
                ->whereKey($lockedRun->source_block_id)
                ->lockForUpdate()
                ->first();
            $targetBlock = BotConstructorBlock::withTrashed()
                ->whereKey($lockedRun->target_block_id)
                ->lockForUpdate()
                ->first();

            if (
                ! ($arrow instanceof BotConstructorArrow)
                || $arrow->trashed()
                || ! $arrow->is_active
                || ! ($sourceBlock instanceof BotConstructorBlock)
                || $sourceBlock->trashed()
                || ! ($targetBlock instanceof BotConstructorBlock)
                || $targetBlock->trashed()
                || ! $targetBlock->is_active
            ) {
                $this->cancelRun($lockedRun, 'Стрелка или блок удалены, либо выключены.');

                return null;
            }

            if ($arrow->cancel_if_left_source_block && $this->dialogLeftSourceBlock($lockedRun)) {
                $this->cancelRun($lockedRun, 'Диалог ушёл из исходного блока.');

                return null;
            }

            $childExecution = BotConstructorExecution::query()->create([
                'root_inbound_message_id' => $parentExecution->root_inbound_message_id,
                'parent_execution_id' => $parentExecution->id,
                'started_by_arrow_run_id' => $lockedRun->id,
                'dialog_id' => $dialog->id,
                'channel_id' => $dialog->channel->id,
                'trigger_type' => BotConstructorExecution::TRIGGER_SCHEDULED_ARROW,
                'auto_transition_count' => 0,
                'next_sequence_number' => 1,
                'status' => BotConstructorExecution::STATUS_RUNNING,
            ]);

            $blockRun = $this->createExecutionBlockRunAction->handle(
                $childExecution,
                $dialog,
                $dialog->channel,
                $targetBlock,
                BotConstructorExecutionBlockRun::STATUS_PROCESSING,
                $lockedRun,
                processingStartedAt: now(),
            );

            return [$lockedRun, $childExecution, $targetBlock, $blockRun];
        });
    }

    private function claimScheduledRun(BotConstructorArrowRun $arrowRun): ?BotConstructorArrowRun
    {
        return DB::transaction(function () use ($arrowRun): ?BotConstructorArrowRun {
            $lockedRun = BotConstructorArrowRun::query()
                ->whereKey($arrowRun->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedRun instanceof BotConstructorArrowRun || $lockedRun->status !== BotConstructorArrowRun::STATUS_SCHEDULED) {
                return null;
            }

            $lockedRun->forceFill([
                'status' => BotConstructorArrowRun::STATUS_PROCESSING,
                'processing_started_at' => now(),
                'error_message' => null,
            ])->save();

            return $lockedRun;
        });
    }

    private function dialogLeftSourceBlock(BotConstructorArrowRun $arrowRun): bool
    {
        $state = BotConstructorDialogState::query()
            ->where('dialog_id', $arrowRun->dialog_id)
            ->first();

        return ! $state instanceof BotConstructorDialogState
            || (int) $state->current_block_id !== (int) $arrowRun->source_block_id;
    }

    private function canContinueAutomation(Message $message, Dialog $dialog): bool
    {
        $message->loadMissing(['channel', 'contact']);
        $dialog->loadMissing('dialogStage');

        if (! $this->dialogAutomationGate->accepts($dialog)) {
            return false;
        }

        $contact = $message->contact;

        if ($contact === null || ! $contact->isAutoReplyEnabled()) {
            return false;
        }

        if ($contact->isInDataCollection() && ! $this->isAutoReplyOnlyMaxBotStartedEvent($message)) {
            return false;
        }

        return true;
    }

    private function isAutoReplyOnlyMaxBotStartedEvent(Message $message): bool
    {
        return $message->channel?->platform === Channel::PLATFORM_MAX
            && data_get($message->raw_payload, 'update_type') === 'bot_started'
            && filled($message->message_parameter);
    }

    private function cancelRun(BotConstructorArrowRun $arrowRun, string $message): void
    {
        $arrowRun->forceFill([
            'status' => BotConstructorArrowRun::STATUS_CANCELLED,
            'processing_started_at' => null,
            'error_message' => $this->safeErrorMessage($message),
        ])->save();
    }

    private function failRun(BotConstructorArrowRun $arrowRun, string $message): void
    {
        $arrowRun->forceFill([
            'status' => BotConstructorArrowRun::STATUS_FAILED,
            'processing_started_at' => null,
            'error_message' => $this->safeErrorMessage($message),
        ])->save();
    }

    private function completeExecutionIfRunning(BotConstructorExecution $execution): void
    {
        $execution->refresh();

        if ($execution->status === BotConstructorExecution::STATUS_RUNNING) {
            $execution->forceFill([
                'status' => BotConstructorExecution::STATUS_COMPLETED,
            ])->save();
        }
    }

    private function safeErrorMessage(string $message): string
    {
        return mb_substr(trim($message), 0, 1000);
    }
}
