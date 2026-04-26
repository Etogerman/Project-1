<?php

namespace App\Services\TelegramAccount;

use App\Data\Messages\PreparedMessageContentData;
use App\Models\Channel;
use App\Models\Dialog;
use App\Models\Message;
use App\Models\TelegramAccountOutgoingMessage;
use App\Models\User;
use App\Services\Dialogs\ResolveDialogRouteStatusAction;
use App\Services\Dialogs\SyncMessageDialogMetadataAction;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class QueueTelegramAccountManualReplyAction
{
    private const GENERIC_BLOCKED_REASON = 'У этого диалога сейчас нет рабочего маршрута для отправки ответа.';

    public function __construct(
        private readonly ResolveDialogRouteStatusAction $resolveDialogRouteStatusAction,
        private readonly SyncMessageDialogMetadataAction $syncMessageDialogMetadataAction,
    ) {}

    public function handle(
        Dialog $dialog,
        User $employee,
        PreparedMessageContentData $content,
        ?Message $replyToMessage,
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

        if ($content->textFormat !== Message::TEXT_FORMAT_PLAIN_TEXT) {
            throw new InvalidArgumentException('Для Telegram account пока доступен только простой текст.');
        }

        if ($dialog->current_contact_identity_id === null || $dialog->contact === null) {
            throw new InvalidArgumentException(self::GENERIC_BLOCKED_REASON);
        }

        $externalChatId = trim((string) $dialog->external_chat_id);

        if ($externalChatId === '') {
            throw new InvalidArgumentException(self::GENERIC_BLOCKED_REASON);
        }

        return DB::transaction(function () use ($dialog, $channel, $employee, $content, $replyToMessage, $externalChatId): Message {
            $outboundMessage = Message::query()->create([
                'contact_id' => $dialog->contact_id,
                'contact_identity_id' => $dialog->current_contact_identity_id,
                'channel_id' => $dialog->channel_id,
                'direction' => Message::DIRECTION_OUTBOUND,
                'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
                'reply_to_message_id' => $replyToMessage?->id,
                'provider_event_key' => null,
                'external_chat_id' => $externalChatId,
                'external_message_id' => null,
                'text' => $content->plainText,
                'text_format' => $content->textFormat,
                'source_text' => $content->sourceText,
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
                Message::SENT_BY_TYPE_OPERATOR,
                $employee->id,
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
                'text' => $content->transportText,
                'text_format' => $content->textFormat,
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

            return $outboundMessage->fresh(['channel', 'dialog.channel', 'sentByUser']) ?? $outboundMessage;
        });
    }
}
