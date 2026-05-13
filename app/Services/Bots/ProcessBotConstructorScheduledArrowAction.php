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
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessBotConstructorScheduledArrowAction
{
    public function __construct(
        private readonly CreateBotConstructorExecutionBlockRunAction $createExecutionBlockRunAction,
        private readonly ExecuteBotConstructorBlockAction $executeBotConstructorBlockAction,
        private readonly ProcessBotConstructorArrowsAction $processBotConstructorArrowsAction,
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
            $arrow = BotConstructorArrow::withTrashed()->find($arrowRun->bot_constructor_arrow_id);

            if (! $arrow instanceof BotConstructorArrow || $arrow->trashed() || ! $arrow->is_active) {
                $this->cancelRun($arrowRun, 'Стрелка удалена или выключена.');

                return;
            }

            $targetBlock = BotConstructorBlock::query()->find($arrowRun->target_block_id);

            if (! $targetBlock instanceof BotConstructorBlock || ! $targetBlock->is_active) {
                $this->cancelRun($arrowRun, 'Целевой блок удалён или выключен.');

                return;
            }

            if ($arrow->cancel_if_left_source_block && $this->dialogLeftSourceBlock($arrowRun)) {
                $this->cancelRun($arrowRun, 'Диалог ушёл из исходного блока.');

                return;
            }

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

            if (! $this->canContinueAutomation($rootMessage)) {
                $this->cancelRun($arrowRun, 'Автоматизация контакта выключена или контакт сейчас собирает данные.');

                return;
            }

            $childExecution = BotConstructorExecution::query()->create([
                'root_inbound_message_id' => $parentExecution->root_inbound_message_id,
                'parent_execution_id' => $parentExecution->id,
                'started_by_arrow_run_id' => $arrowRun->id,
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
                $arrowRun,
                processingStartedAt: now(),
            );

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
                $this->processBotConstructorArrowsAction->markArrowFailed(
                    $arrowRun,
                    $blockRun->error_message ?: 'Целевой блок не был успешно выполнен.',
                );
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

    private function canContinueAutomation(Message $message): bool
    {
        $message->loadMissing(['channel', 'contact']);

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
