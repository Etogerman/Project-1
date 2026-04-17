<?php

namespace App\Services\Bitrix24;

use App\Models\Bitrix24MessageExport;
use App\Models\Channel;
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

    /**
     * @var list<string>
     */
    private const EXPORTABLE_SYSTEM_EVENT_CODES = [
        Message::SYSTEM_EVENT_CODE_BOT_BLOCKED_BY_USER,
        Message::SYSTEM_EVENT_CODE_BOT_UNBLOCKED_BY_USER,
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

        if (! $this->isExportableMessageKind($message)) {
            return false;
        }

        if (! $this->hasExportableMessageText($message)) {
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

    private function isExportableMessageKind(Message $message): bool
    {
        if (in_array($message->message_kind, self::EXPORTABLE_MESSAGE_KINDS, true)) {
            return true;
        }

        return $message->message_kind === Message::KIND_INBOUND_SYSTEM_EVENT
            && in_array($message->system_event_code, self::EXPORTABLE_SYSTEM_EVENT_CODES, true);
    }

    private function hasExportableMessageText(Message $message): bool
    {
        if ($message->message_kind === Message::KIND_INBOUND_CONTACT_SHARE) {
            return true;
        }

        if ($message->message_kind === Message::KIND_INBOUND_SYSTEM_EVENT) {
            return in_array($message->system_event_code, self::EXPORTABLE_SYSTEM_EVENT_CODES, true);
        }

        if ($this->isMaxBotStartedMessage($message)) {
            return true;
        }

        return trim((string) $message->text) !== '';
    }

    private function isMaxBotStartedMessage(Message $message): bool
    {
        if (
            $message->direction !== Message::DIRECTION_INBOUND
            || $message->message_kind !== Message::KIND_INBOUND_USER
            || data_get($message->raw_payload, 'update_type') !== 'bot_started'
        ) {
            return false;
        }

        $message->loadMissing(['dialog.channel', 'channel']);
        $channel = $message->dialog?->channel ?? $message->channel;

        return $channel instanceof Channel
            && $channel->platform === Channel::PLATFORM_MAX;
    }
}
