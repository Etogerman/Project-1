<?php

namespace App\Services\Dialogs;

use App\Models\Message;

class InferHistoricalMessageSenderAction
{
    /**
     * @return array{sent_by_type: ?string, sent_by_user_id: ?int, sent_by_system_code: ?string, is_unknown_kind: bool}|null
     */
    public function handle(Message $message): ?array
    {
        if (filled($message->sent_by_type)) {
            return null;
        }

        if ($message->direction === Message::DIRECTION_INBOUND) {
            return [
                'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
                'sent_by_user_id' => null,
                'sent_by_system_code' => null,
                'is_unknown_kind' => false,
            ];
        }

        return match ($message->message_kind) {
            Message::KIND_OUTBOUND_MANUAL_REPLY => [
                'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
                'sent_by_user_id' => null,
                'sent_by_system_code' => null,
                'is_unknown_kind' => false,
            ],
            Message::KIND_OUTBOUND_AUTO_REPLY => [
                'sent_by_type' => Message::SENT_BY_TYPE_AUTO_REPLY,
                'sent_by_user_id' => null,
                'sent_by_system_code' => 'auto_reply_legacy',
                'is_unknown_kind' => false,
            ],
            Message::KIND_OUTBOUND_PHONE_CAPTURE_CONFIRMATION => [
                'sent_by_type' => Message::SENT_BY_TYPE_COLLECTOR,
                'sent_by_user_id' => null,
                'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_PHONE_CAPTURE_CONFIRMATION,
                'is_unknown_kind' => false,
            ],
            Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION => [
                'sent_by_type' => Message::SENT_BY_TYPE_COLLECTOR,
                'sent_by_user_id' => null,
                'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_DATA_COLLECTION_QUESTION,
                'is_unknown_kind' => false,
            ],
            Message::KIND_OUTBOUND_DATA_COLLECTION_COMPLETION => [
                'sent_by_type' => Message::SENT_BY_TYPE_COLLECTOR,
                'sent_by_user_id' => null,
                'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_DATA_COLLECTION_COMPLETION,
                'is_unknown_kind' => false,
            ],
            default => [
                'sent_by_type' => Message::SENT_BY_TYPE_SYSTEM,
                'sent_by_user_id' => null,
                'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_LEGACY_UNKNOWN_KIND,
                'is_unknown_kind' => true,
            ],
        };
    }
}
