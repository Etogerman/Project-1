<?php

namespace App\Services\Dialogs;

use App\Data\Dialogs\DialogInboxStatusData;
use App\Models\Dialog;
use App\Models\Message;

class ResolveDialogInboxStatusAction
{
    public function __construct(
        private readonly MessageChronology $messageChronology,
    ) {}

    public function handle(Dialog $dialog): DialogInboxStatusData
    {
        $latestInboundUserMessage = $this->resolveLatestDialogMessage($dialog, Message::KIND_INBOUND_USER);
        $latestOutboundManualReplyMessage = $this->resolveLatestDialogMessage($dialog, Message::KIND_OUTBOUND_MANUAL_REPLY);

        if (! $latestInboundUserMessage instanceof Message) {
            return $this->make(DialogInboxStatusData::CODE_NO_NEW, 'Нет новых', 'success');
        }

        if (
            $latestOutboundManualReplyMessage instanceof Message
            && ! $this->messageChronology->isAfter(
                $this->messageChronology->resolveSortAt($latestInboundUserMessage),
                $latestInboundUserMessage->id,
                $this->messageChronology->resolveSortAt($latestOutboundManualReplyMessage),
                $latestOutboundManualReplyMessage->id,
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

    private function make(string $code, string $label, string $tone): DialogInboxStatusData
    {
        return new DialogInboxStatusData(
            code: $code,
            label: $label,
            tone: $tone,
        );
    }
}
