<?php

namespace App\Services\Bots;

use App\Data\Bots\AutoReplyDeliveryResult;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Dialog;
use App\Models\Message;
use App\Models\User;
use App\Services\Contacts\ClaimContactAction;
use App\Services\Contacts\ResolveRootContactAction;
use App\Services\Dialogs\ResolveDialogRouteStatusAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class SendManualDialogReplyAction
{
    public function __construct(
        protected ChannelActivityLogger $channelActivityLogger,
        protected ClaimContactAction $claimContactAction,
        protected ResolveRootContactAction $resolveRootContactAction,
        protected ResolveDialogRouteStatusAction $resolveDialogRouteStatusAction,
        protected StoreManualOutboundMessageAction $storeManualOutboundMessageAction,
        protected TelegramBotApiService $telegramBotApiService,
        protected MaxBotApiService $maxBotApiService,
    ) {}

    public function handle(Dialog $dialog, User $employee, string $text): Message
    {
        if (! $employee->is_active || ! $employee->is_admin) {
            throw new AuthorizationException();
        }

        $dialog->loadMissing(['contact.assignedUser', 'channel', 'currentContactIdentity']);

        $contact = $dialog->contact;

        if (! $contact instanceof Contact) {
            throw new InvalidArgumentException('Не удалось определить контакт этого диалога.');
        }

        $effectiveContact = $this->resolveRootContactAction->handle($contact);

        $text = trim($text);

        if ($text === '') {
            throw new InvalidArgumentException('Введите текст ответа.');
        }

        $blockedReason = $this->getBlockedReason($dialog);

        if ($blockedReason !== null) {
            throw new InvalidArgumentException($blockedReason);
        }

        if (! $effectiveContact->isAssigned()) {
            $effectiveContact = $this->claimContactAction->handle($effectiveContact, $employee);
        }

        if (! $effectiveContact->isAssignedTo($employee)) {
            throw new InvalidArgumentException(
                filled($effectiveContact->assignedUser?->name)
                    ? 'Контакт уже назначен сотруднику '.$effectiveContact->assignedUser->name.'.'
                    : 'Контакт уже назначен другому сотруднику.',
            );
        }

        $channel = $dialog->channel;

        if (! $channel instanceof Channel) {
            throw new InvalidArgumentException('У этого диалога сейчас нет рабочего маршрута для отправки ответа.');
        }

        $replyToMessage = $this->resolveReplyToMessage($dialog);

        try {
            $deliveryResult = $this->sendTextMessage($dialog, $text);
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
                ],
            );

            throw $throwable;
        }

        return DB::transaction(function () use ($channel, $dialog, $deliveryResult, $employee, $replyToMessage, $effectiveContact): Message {
            $outboundMessage = $this->storeManualOutboundMessageAction->handle(
                $dialog,
                $employee,
                $deliveryResult,
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
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->first();
    }

    protected function sendTextMessage(Dialog $dialog, string $text): AutoReplyDeliveryResult
    {
        $dialog->loadMissing(['channel', 'currentContactIdentity']);

        $channel = $dialog->channel;

        if (! $channel instanceof Channel) {
            throw new InvalidArgumentException('У этого диалога сейчас нет рабочего маршрута для отправки ответа.');
        }

        $externalUserId = $dialog->currentContactIdentity?->external_user_id;

        return match ($channel->platform) {
            Channel::PLATFORM_TELEGRAM => $this->telegramBotApiService->sendTextMessage($channel, $dialog->external_chat_id, $externalUserId, $text),
            Channel::PLATFORM_MAX => $this->maxBotApiService->sendTextMessage($channel, $dialog->external_chat_id, $externalUserId, $text),
            default => throw new InvalidArgumentException('У этого диалога сейчас нет рабочего маршрута для отправки ответа.'),
        };
    }
}
