<?php

namespace App\Services\Bitrix24;

use App\Models\Bitrix24MessageExport;
use App\Models\Message;

class IsMessageReadyForBitrix24LiveExportAction
{
    /**
     * @var list<string>
     */
    private const EXPORTABLE_MESSAGE_KINDS = [
        Message::KIND_INBOUND_USER,
        Message::KIND_INBOUND_CONTACT_SHARE,
        Message::KIND_OUTBOUND_AUTO_REPLY,
        Message::KIND_OUTBOUND_MANUAL_REPLY,
        Message::KIND_OUTBOUND_PHONE_CAPTURE_CONFIRMATION,
        Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION,
        Message::KIND_OUTBOUND_DATA_COLLECTION_COMPLETION,
    ];

    public function __construct(
        private readonly IsDialogReadyForBitrix24LiveBridgeAction $isDialogReadyForBitrix24LiveBridgeAction,
    ) {}

    public function handle(Message|int $message): bool
    {
        $message = $message instanceof Message
            ? $message
            : Message::query()->with(['dialog.channel', 'contact'])->findOrFail($message);

        if ($message->sent_by_system_code === Message::SENT_BY_SYSTEM_CODE_BITRIX24_OPENLINES) {
            return false;
        }

        if (! in_array($message->message_kind, self::EXPORTABLE_MESSAGE_KINDS, true)) {
            return false;
        }

        if ($message->message_kind !== Message::KIND_INBOUND_CONTACT_SHARE && trim((string) $message->text) === '') {
            return false;
        }

        if (! $message->dialog()->exists()) {
            return false;
        }

        if (! $this->isDialogReadyForBitrix24LiveBridgeAction->handle($message->dialog()->firstOrFail())) {
            return false;
        }

        return ! Bitrix24MessageExport::query()
            ->where('message_id', $message->id)
            ->where('export_mode', Bitrix24MessageExport::MODE_LIVE)
            ->where('export_status', Bitrix24MessageExport::STATUS_EXPORTED)
            ->exists();
    }
}
