<?php

namespace App\Services\TelegramAccount;

use App\Models\Channel;
use App\Models\Dialog;
use App\Models\Message;
use App\Models\TelegramAccountOutgoingMessage;
use App\Services\Dialogs\ResolveDialogRouteStatusAction;
use App\Services\Dialogs\SyncMessageDialogMetadataAction;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class QueueTelegramAccountSystemReplyAction
{
    private const GENERIC_BLOCKED_REASON = 'У этого диалога сейчас нет рабочего маршрута для отправки ответа.';

    public function __construct(
        private readonly ResolveDialogRouteStatusAction $resolveDialogRouteStatusAction,
        private readonly SyncMessageDialogMetadataAction $syncMessageDialogMetadataAction,
    ) {}

    public function handle(
        Dialog $dialog,
        string $text,
        Message $replyToMessage,
        string $sentBySystemCode,
        string $messageKind = Message::KIND_OUTBOUND_AUTO_REPLY,
        string $sentByType = Message::SENT_BY_TYPE_AUTO_REPLY,
        string $textFormat = Message::TEXT_FORMAT_PLAIN_TEXT,
    ): Message {
        $dialog->loadMissing(['contact', 'channel', 'currentContactIdentity']);

        $channel = $dialog->channel;

        if (
            ! $channel instanceof Channel
            || ! $channel->isAccountConnection()
            || $channel->platform !== Channel::PLATFORM_TELEGRAM
        ) {
            throw new InvalidArgumentException(self::GENERIC_BLOCKED_REASON);
        }

        $routeStatus = $this->resolveDialogRouteStatusAction->handle($dialog);

        if (! $routeStatus->isSendable) {
            throw new InvalidArgumentException($routeStatus->blockedReason ?? self::GENERIC_BLOCKED_REASON);
        }

        if ($textFormat !== Message::TEXT_FORMAT_PLAIN_TEXT) {
            throw new InvalidArgumentException('Для Telegram account пока доступен только простой текст.');
        }

        if ($dialog->current_contact_identity_id === null || $dialog->contact === null) {
            throw new InvalidArgumentException(self::GENERIC_BLOCKED_REASON);
        }

        $externalChatId = trim((string) $dialog->external_chat_id);

        if ($externalChatId === '') {
            throw new InvalidArgumentException(self::GENERIC_BLOCKED_REASON);
        }

        return DB::transaction(function () use ($dialog, $channel, $replyToMessage, $text, $textFormat, $messageKind, $sentByType, $sentBySystemCode, $externalChatId): Message {
            $replyToMessage->forceFill([
                'auto_reply_sent_at' => now(),
            ])->save();

            $outboundMessage = Message::query()->create([
                'contact_id' => $dialog->contact_id,
                'contact_identity_id' => $dialog->current_contact_identity_id,
                'channel_id' => $dialog->channel_id,
                'direction' => Message::DIRECTION_OUTBOUND,
                'message_kind' => $messageKind,
                'reply_to_message_id' => $replyToMessage->id,
                'provider_event_key' => null,
                'external_chat_id' => $externalChatId,
                'external_message_id' => null,
                'text' => $text,
                'text_format' => $textFormat,
                'source_text' => null,
                'raw_payload' => [
                    'provider' => 'telegram_account_gateway',
                    'delivery_status' => TelegramAccountOutgoingMessage::STATUS_PENDING,
                ],
                'received_at' => now(),
            ]);

            $outboundMessage = $this->syncMessageDialogMetadataAction->handle(
                $outboundMessage,
                $dialog->contact,
                $channel,
                $dialog->currentContactIdentity,
                $externalChatId,
                $sentByType,
                null,
                $sentBySystemCode,
            );

            $dedupeKey = sprintf(
                'telegram_account_outgoing:%d:%d',
                $channel->id,
                $outboundMessage->id,
            );

            $outgoing = TelegramAccountOutgoingMessage::query()->create([
                'channel_id' => $channel->id,
                'dialog_id' => $dialog->id,
                'message_id' => $outboundMessage->id,
                'external_chat_id' => $externalChatId,
                'text' => $text,
                'text_format' => $textFormat,
                'dedupe_key' => $dedupeKey,
                'status' => TelegramAccountOutgoingMessage::STATUS_PENDING,
            ]);

            $outboundMessage->forceFill([
                'raw_payload' => [
                    'provider' => 'telegram_account_gateway',
                    'delivery_status' => TelegramAccountOutgoingMessage::STATUS_PENDING,
                    'outgoing_message_id' => $outgoing->id,
                    'dedupe_key' => $dedupeKey,
                ],
            ])->save();

            return $outboundMessage->fresh(['channel', 'dialog.channel']) ?? $outboundMessage;
        });
    }
}
