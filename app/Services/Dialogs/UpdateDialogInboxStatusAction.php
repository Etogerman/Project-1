<?php

namespace App\Services\Dialogs;

use App\Data\Dialogs\DialogInboxStatusData;
use App\Data\Dialogs\DialogInboxStatusUpdateResultData;
use App\Models\Dialog;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateDialogInboxStatusAction
{
    public function __construct(
        private readonly ResolveDialogInboxStatusAction $resolveDialogInboxStatusAction,
    ) {}

    public function handle(Dialog $dialog, User $employee, string $targetStatusCode): DialogInboxStatusUpdateResultData
    {
        return DB::transaction(function () use ($dialog, $employee, $targetStatusCode): DialogInboxStatusUpdateResultData {
            $dialog = Dialog::query()
                ->with(['channel', 'currentContactIdentity'])
                ->whereKey($dialog->id)
                ->lockForUpdate()
                ->firstOrFail();

            $currentStatus = $this->resolveDialogInboxStatusAction->handle($dialog);

            if (
                ! in_array(
                    $targetStatusCode,
                    [
                        DialogInboxStatusData::CODE_REQUIRES_REPLY,
                        DialogInboxStatusData::CODE_NOT_REQUIRED,
                    ],
                    true,
                )
            ) {
                throw ValidationException::withMessages([
                    'dialogInboxStatusSelection' => 'Недопустимое значение статуса.',
                ]);
            }

            if ($currentStatus->code === DialogInboxStatusData::CODE_NO_NEW) {
                throw ValidationException::withMessages([
                    'dialogInboxStatusSelection' => 'Сейчас в диалоге нет нового входящего сообщения для ручной смены статуса.',
                ]);
            }

            if ($currentStatus->code === $targetStatusCode) {
                return new DialogInboxStatusUpdateResultData(
                    status: $currentStatus,
                    historyMessage: null,
                );
            }

            $latestInboundUserMessage = $this->resolveDialogInboxStatusAction->resolveLatestInboundUserMessage($dialog);

            if (! $latestInboundUserMessage instanceof Message) {
                throw ValidationException::withMessages([
                    'dialogInboxStatusSelection' => 'Не удалось определить текущее входящее сообщение для смены статуса.',
                ]);
            }

            if ($targetStatusCode === DialogInboxStatusData::CODE_NOT_REQUIRED) {
                $dialog->forceFill([
                    'manual_reply_dismissed_source_message_id' => $latestInboundUserMessage->id,
                ])->save();
            }

            if ($targetStatusCode === DialogInboxStatusData::CODE_REQUIRES_REPLY) {
                $dialog->forceFill([
                    'manual_reply_dismissed_source_message_id' => null,
                ])->save();
            }

            $historyMessage = $dialog->messages()->create([
                'contact_id' => $dialog->contact_id,
                'contact_identity_id' => $dialog->current_contact_identity_id,
                'channel_id' => $dialog->channel_id,
                'direction' => Message::DIRECTION_OUTBOUND,
                'message_kind' => Message::KIND_OUTBOUND_DIALOG_STATUS_CHANGE,
                'sent_by_type' => Message::SENT_BY_TYPE_SYSTEM,
                'sent_by_user_id' => $employee->id,
                'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_DIALOG_INBOX_STATUS_CHANGE,
                'reply_to_message_id' => $latestInboundUserMessage->id,
                'external_chat_id' => $dialog->external_chat_id,
                'text' => sprintf(
                    'Оператор %s изменил статус диалога: %s -> %s',
                    $employee->name ?: 'без имени',
                    $currentStatus->label,
                    $targetStatusCode === DialogInboxStatusData::CODE_NOT_REQUIRED ? 'Не требует ответа' : 'Требует ответа',
                ),
                'text_format' => Message::TEXT_FORMAT_PLAIN_TEXT,
                'received_at' => null,
            ]);

            return new DialogInboxStatusUpdateResultData(
                status: $this->resolveDialogInboxStatusAction->handle($dialog->fresh()),
                historyMessage: $historyMessage,
            );
        });
    }
}
