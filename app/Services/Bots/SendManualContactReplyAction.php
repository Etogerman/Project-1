<?php

namespace App\Services\Bots;

use App\Data\Bots\AutoReplyDeliveryResult;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\User;
use App\Services\Contacts\ClaimContactAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class SendManualContactReplyAction
{
    public function __construct(
        protected ChannelActivityLogger $channelActivityLogger,
        protected ClaimContactAction $claimContactAction,
        protected StoreManualOutboundMessageAction $storeManualOutboundMessageAction,
        protected TelegramBotApiService $telegramBotApiService,
        protected MaxBotApiService $maxBotApiService,
    ) {}

    public function handle(Contact $contact, User $employee, string $text): Message
    {
        if (! $employee->is_active || ! $employee->is_admin) {
            throw new AuthorizationException();
        }

        $contact = Contact::query()->findOrFail($contact->id);

        $text = trim($text);

        if ($text === '') {
            throw new InvalidArgumentException('Введите текст ответа.');
        }

        if (! $contact->isAssigned()) {
            $contact = $this->claimContactAction->handle($contact, $employee);
        }

        if (! $contact->isAssignedTo($employee)) {
            throw new InvalidArgumentException('Контакт уже взят в работу другим сотрудником.');
        }

        $routeSource = $this->resolveRouteSource($contact);

        if ($routeSource === null) {
            throw new InvalidArgumentException('Не найден активный канал для отправки ответа этому контакту.');
        }

        $channel = $routeSource->channel;

        if ($channel === null) {
            throw new InvalidArgumentException('Не найден активный канал для отправки ответа этому контакту.');
        }

        $replyToMessage = $this->resolveReplyToMessage($contact, $routeSource);

        try {
            $deliveryResult = $this->sendTextMessage($channel, $routeSource, $text);
        } catch (Throwable $throwable) {
            $channel->markError($throwable);

            $this->channelActivityLogger->error(
                $channel,
                'contact.reply_failed',
                'Ручной ответ не отправлен.',
                [
                    'contact_id' => $contact->id,
                    'contact_identity_id' => $routeSource->contact_identity_id,
                    'employee_id' => $employee->id,
                    'platform' => $channel->platform,
                    'external_chat_id' => $routeSource->external_chat_id,
                    'reply_to_message_id' => $replyToMessage?->id,
                ],
            );

            throw $throwable;
        }

        return DB::transaction(function () use ($channel, $contact, $deliveryResult, $employee, $replyToMessage, $routeSource): Message {
            $outboundMessage = $this->storeManualOutboundMessageAction->handle($routeSource, $deliveryResult, $replyToMessage);

            $channel->markReplySent();

            $this->channelActivityLogger->info(
                $channel,
                'contact.reply_sent',
                'Ручной ответ отправлен.',
                [
                    'contact_id' => $contact->id,
                    'contact_identity_id' => $routeSource->contact_identity_id,
                    'employee_id' => $employee->id,
                    'platform' => $channel->platform,
                    'external_chat_id' => $routeSource->external_chat_id,
                    'outbound_external_message_id' => $deliveryResult->externalMessageId,
                    'reply_to_message_id' => $replyToMessage?->id,
                ],
            );

            return $outboundMessage;
        });
    }

    protected function resolveRouteSource(Contact $contact): ?Message
    {
        return $contact->messages()
            ->with(['channel', 'contactIdentity'])
            ->where('direction', Message::DIRECTION_INBOUND)
            ->whereNotNull('contact_identity_id')
            ->whereHas('contactIdentity')
            ->whereHas('channel', fn (Builder $query): Builder => $query
                ->where('is_active', true)
                ->where('connection_type', Channel::CONNECTION_TYPE_BOT))
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->get()
            ->first(fn (Message $message): bool => $this->messageCanBeUsedAsRouteSource($message));
    }

    protected function resolveReplyToMessage(Contact $contact, Message $routeSource): ?Message
    {
        return $contact->messages()
            ->where('direction', Message::DIRECTION_INBOUND)
            ->where('channel_id', $routeSource->channel_id)
            ->where('contact_identity_id', $routeSource->contact_identity_id)
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->first();
    }

    protected function messageCanBeUsedAsRouteSource(Message $message): bool
    {
        $channel = $message->channel;

        if ($channel === null || ! $channel->is_active || $channel->connection_type !== Channel::CONNECTION_TYPE_BOT || ! filled($channel->getToken())) {
            return false;
        }

        return match ($channel->platform) {
            Channel::PLATFORM_TELEGRAM => filled($message->external_chat_id),
            Channel::PLATFORM_MAX => filled($message->external_chat_id) || filled($message->contactIdentity?->external_user_id),
            default => false,
        };
    }

    protected function sendTextMessage(Channel $channel, Message $routeSource, string $text): AutoReplyDeliveryResult
    {
        $externalUserId = $routeSource->contactIdentity?->external_user_id;

        return match ($channel->platform) {
            Channel::PLATFORM_TELEGRAM => $this->telegramBotApiService->sendTextMessage($channel, $routeSource->external_chat_id, $externalUserId, $text),
            Channel::PLATFORM_MAX => $this->maxBotApiService->sendTextMessage($channel, $routeSource->external_chat_id, $externalUserId, $text),
            default => throw new InvalidArgumentException("Unsupported bot platform [{$channel->platform}]."),
        };
    }
}
