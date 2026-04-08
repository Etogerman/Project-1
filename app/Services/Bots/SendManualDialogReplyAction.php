<?php

namespace App\Services\Bots;

use App\Data\Bots\AutoReplyDeliveryResult;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Dialog;
use App\Models\Message;
use App\Models\User;
use App\Services\Contacts\ResolveRootContactAction;
use App\Services\Dialogs\MessageChronology;
use App\Services\Dialogs\ResolveDialogRouteStatusAction;
use App\Services\Messages\PrepareMessageContentAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class SendManualDialogReplyAction
{
    public function __construct(
        protected ChannelActivityLogger $channelActivityLogger,
        protected ResolveRootContactAction $resolveRootContactAction,
        protected MessageChronology $messageChronology,
        protected ResolveDialogRouteStatusAction $resolveDialogRouteStatusAction,
        protected StoreManualOutboundMessageAction $storeManualOutboundMessageAction,
        protected TelegramBotApiService $telegramBotApiService,
        protected MaxBotApiService $maxBotApiService,
        protected PrepareMessageContentAction $prepareMessageContentAction,
    ) {}

    public function handle(
        Dialog $dialog,
        User $employee,
        string $text,
        string $textFormat = Message::TEXT_FORMAT_PLAIN_TEXT,
    ): Message
    {
        if (! $employee->canReplyInDialogs()) {
            throw new AuthorizationException();
        }

        $dialog->loadMissing(['contact.assignedUser', 'channel', 'currentContactIdentity']);

        $contact = $dialog->contact;

        if (! $contact instanceof Contact) {
            throw new InvalidArgumentException('Не удалось определить контакт этого диалога.');
        }

        $effectiveContact = $this->resolveRootContactAction->handle($contact);

        $content = $this->prepareMessageContentAction->handle($text, $textFormat);

        $blockedReason = $this->getBlockedReason($dialog);

        if ($blockedReason !== null) {
            throw new InvalidArgumentException($blockedReason);
        }

        $channel = $dialog->channel;

        if (! $channel instanceof Channel) {
            throw new InvalidArgumentException('У этого диалога сейчас нет рабочего маршрута для отправки ответа.');
        }

        $replyToMessage = $this->resolveReplyToMessage($dialog);

        try {
            $deliveryResult = $this->sendTextMessage($dialog, $content->transportText, $content->textFormat);
        } catch (Throwable $throwable) {
            $channel->markError($throwable);

            $this->channelActivityLogger->error(
                $channel,
                'contact.reply_failed',
                'Ручной ответ не отправлен.',
                [
                    'contact_id' => $effectiveContact->id,
                    'dialog_id' => $dialog->id,
                    'contact_identity_id' => $dialog->current_contact_identity_id,
                    'employee_id' => $employee->id,
                    'platform' => $channel->platform,
                    'external_chat_id' => $dialog->external_chat_id,
                    'reply_to_message_id' => $replyToMessage?->id,
                    'text_format' => $content->textFormat,
                ],
            );

            throw $throwable;
        }

        return DB::transaction(function () use ($channel, $dialog, $deliveryResult, $employee, $replyToMessage, $effectiveContact, $content): Message {
            $outboundMessage = $this->storeManualOutboundMessageAction->handle(
                $dialog,
                $employee,
                $deliveryResult,
                $content,
                $replyToMessage,
            );

            $channel->markReplySent();

            $this->channelActivityLogger->info(
                $channel,
                'contact.reply_sent',
                'Ручной ответ отправлен.',
                [
                    'contact_id' => $effectiveContact->id,
                    'dialog_id' => $dialog->id,
                    'contact_identity_id' => $dialog->current_contact_identity_id,
                    'employee_id' => $employee->id,
                    'platform' => $channel->platform,
                    'external_chat_id' => $dialog->external_chat_id,
                    'outbound_external_message_id' => $deliveryResult->externalMessageId,
                    'reply_to_message_id' => $replyToMessage?->id,
                    'text_format' => $content->textFormat,
                ],
            );

            return $outboundMessage;
        });
    }

    public function getBlockedReason(Dialog $dialog): ?string
    {
        return $this->resolveDialogRouteStatusAction->handle($dialog)->blockedReason;
    }

    protected function resolveReplyToMessage(Dialog $dialog): ?Message
    {
        return Message::query()
            ->where('dialog_id', $dialog->id)
            ->where('direction', Message::DIRECTION_INBOUND)
            ->tap(fn (\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder => $this->messageChronology->applyLatestOrder($query))
            ->first();
    }

    protected function sendTextMessage(
        Dialog $dialog,
        string $text,
        string $textFormat = Message::TEXT_FORMAT_PLAIN_TEXT,
    ): AutoReplyDeliveryResult
    {
        $dialog->loadMissing(['channel', 'currentContactIdentity']);

        $channel = $dialog->channel;

        if (! $channel instanceof Channel) {
            throw new InvalidArgumentException('У этого диалога сейчас нет рабочего маршрута для отправки ответа.');
        }

        $externalUserId = $dialog->currentContactIdentity?->external_user_id;

        return match ($channel->platform) {
            Channel::PLATFORM_TELEGRAM => $this->telegramBotApiService->sendTextMessage($channel, $dialog->external_chat_id, $externalUserId, $text, null, $textFormat),
            Channel::PLATFORM_MAX => $this->maxBotApiService->sendTextMessage($channel, $dialog->external_chat_id, $externalUserId, $text, null, $textFormat),
            default => throw new InvalidArgumentException('У этого диалога сейчас нет рабочего маршрута для отправки ответа.'),
        };
    }
}
