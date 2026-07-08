<?php

namespace App\Services\Dialogs;

use App\Data\Dialogs\DialogInboxStatusData;
use App\Models\Dialog;
use App\Models\Message;

class ResolveDialogInboxStatusAction
{
    public function __construct(
        private readonly MessageChronology $messageChronology,
        private readonly DialogStageCatalog $dialogStageCatalog,
    ) {}

    public function handle(Dialog $dialog): DialogInboxStatusData
    {
        if ($this->dialogStageCatalog->isBlacklistDialog($dialog)) {
            return $this->make(DialogInboxStatusData::CODE_NOT_REQUIRED, 'Не требует ответа', 'gray');
        }

        $statusFromProjectedAttributes = $this->resolveFromProjectedAttributes($dialog);

        if ($statusFromProjectedAttributes instanceof DialogInboxStatusData) {
            return $statusFromProjectedAttributes;
        }

        $latestInboundUserMessage = $this->resolveLatestDialogMessage($dialog, Message::KIND_INBOUND_USER);
        $latestAnswerMessage = $this->resolveLatestDialogAnswerMessage($dialog);

        if (! $latestInboundUserMessage instanceof Message) {
            return $this->make(DialogInboxStatusData::CODE_NO_NEW, 'Нет новых', 'success');
        }

        if (
            $latestAnswerMessage instanceof Message
            && ! $this->messageChronology->isAfter(
                $this->messageChronology->resolveSortAt($latestInboundUserMessage),
                $latestInboundUserMessage->id,
                $this->messageChronology->resolveSortAt($latestAnswerMessage),
                $latestAnswerMessage->id,
            )
        ) {
            return $this->make(DialogInboxStatusData::CODE_NO_NEW, 'Нет новых', 'success');
        }

        if ((int) ($dialog->manual_reply_dismissed_source_message_id ?? 0) === $latestInboundUserMessage->id) {
            return $this->make(DialogInboxStatusData::CODE_NOT_REQUIRED, 'Не требует ответа', 'gray');
        }

        return $this->make(DialogInboxStatusData::CODE_REQUIRES_REPLY, 'Требует ответа', 'warning');
    }

    public function resolveLatestInboundUserMessage(Dialog $dialog): ?Message
    {
        return $this->resolveLatestDialogMessage($dialog, Message::KIND_INBOUND_USER);
    }

    private function resolveLatestDialogMessage(Dialog $dialog, string $messageKind): ?Message
    {
        return $this->messageChronology
            ->applyLatestOrder(
                $dialog->messages()
                    ->getQuery()
                    ->where('message_kind', $messageKind)
            )
            ->first();
    }

    private function resolveLatestDialogAnswerMessage(Dialog $dialog): ?Message
    {
        return $this->messageChronology
            ->applyLatestOrder(
                $dialog->messages()
                    ->getQuery()
                    ->whereIn('message_kind', Message::dialogAnswerMessageKinds())
            )
            ->first();
    }

    private function resolveFromProjectedAttributes(Dialog $dialog): ?DialogInboxStatusData
    {
        $attributes = $dialog->getAttributes();
        $requiredAttributes = [
            'latest_inbound_user_message_id',
            'latest_inbound_user_message_sort_at',
            'latest_outbound_manual_reply_message_id',
            'latest_outbound_manual_reply_message_sort_at',
        ];

        foreach ($requiredAttributes as $attribute) {
            if (! array_key_exists($attribute, $attributes)) {
                return null;
            }
        }

        $latestInboundUserMessageId = $attributes['latest_inbound_user_message_id'];
        $latestInboundUserMessageSortAt = $attributes['latest_inbound_user_message_sort_at'];
        $latestOutboundManualReplyMessageId = $attributes['latest_outbound_manual_reply_message_id'];
        $latestOutboundManualReplyMessageSortAt = $attributes['latest_outbound_manual_reply_message_sort_at'];

        if (! filled($latestInboundUserMessageId)) {
            return $this->make(DialogInboxStatusData::CODE_NO_NEW, 'Нет новых', 'success');
        }

        if (
            filled($latestOutboundManualReplyMessageId)
            && ! $this->messageChronology->isAfter(
                $latestInboundUserMessageSortAt,
                $latestInboundUserMessageId,
                $latestOutboundManualReplyMessageSortAt,
                $latestOutboundManualReplyMessageId,
            )
        ) {
            return $this->make(DialogInboxStatusData::CODE_NO_NEW, 'Нет новых', 'success');
        }

        if ((int) ($dialog->manual_reply_dismissed_source_message_id ?? 0) === (int) $latestInboundUserMessageId) {
            return $this->make(DialogInboxStatusData::CODE_NOT_REQUIRED, 'Не требует ответа', 'gray');
        }

        return $this->make(DialogInboxStatusData::CODE_REQUIRES_REPLY, 'Требует ответа', 'warning');
    }

    private function make(string $code, string $label, string $tone): DialogInboxStatusData
    {
        return new DialogInboxStatusData(
            code: $code,
            label: $label,
            tone: $tone,
        );
    }
}
