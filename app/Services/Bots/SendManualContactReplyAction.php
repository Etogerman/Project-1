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
use App\Services\Dialogs\ResolveDialogRouteSourceAction;
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
        protected ResolveRootContactAction $resolveRootContactAction,
        protected ResolveDialogRouteSourceAction $resolveDialogRouteSourceAction,
        protected StoreManualOutboundMessageAction $storeManualOutboundMessageAction,
        protected TelegramBotApiService $telegramBotApiService,
        protected MaxBotApiService $maxBotApiService,
    ) {}

    public function handle(Contact $contact, User $employee, string $text): Message
    {
        if (! $employee->is_active || ! $employee->is_admin) {
            throw new AuthorizationException();
        }

        $contact = $this->resolveRootContactAction->handle($contact);

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

        $routeDialog = $this->resolveDialogRouteSourceAction->forContact($contact);
        $legacyRouteSource = null;
        $fallbackUsed = false;

        if (! $routeDialog instanceof Dialog) {
            $legacyRouteSource = $this->resolveLegacyRouteSource($contact);

            if (! $legacyRouteSource instanceof Message) {
                throw new InvalidArgumentException('Не найден активный канал для отправки ответа этому контакту.');
            }

            $routeDialog = $this->resolveDialogRouteSourceAction->fallbackFromLegacyMessage($legacyRouteSource);

            if (! $routeDialog instanceof Dialog) {
                throw new InvalidArgumentException('Не найден активный канал для отправки ответа этому контакту.');
            }

            $fallbackUsed = true;
        }

        $channel = $routeDialog->channel;

        if ($channel === null) {
            throw new InvalidArgumentException('Не найден активный канал для отправки ответа этому контакту.');
        }

        $replyToMessage = $this->resolveReplyToMessage($routeDialog) ?? $legacyRouteSource;

        if ($fallbackUsed) {
            $this->channelActivityLogger->warning(
                $channel,
                'contact.dialog_route_fallback_used',
                'Для ручного ответа использован fallback route source через сообщение.',
                [
                    'contact_id' => $contact->id,
                    'dialog_id' => $routeDialog->id,
                    'legacy_route_message_id' => $legacyRouteSource?->id,
                    'reply_to_message_id' => $replyToMessage?->id,
                ],
            );
        }

        try {
            $deliveryResult = $this->sendTextMessage($routeDialog, $text);
        } catch (Throwable $throwable) {
            $channel->markError($throwable);

            $this->channelActivityLogger->error(
                $channel,
                'contact.reply_failed',
                'Ручной ответ не отправлен.',
                [
                    'contact_id' => $contact->id,
                    'dialog_id' => $routeDialog->id,
                    'contact_identity_id' => $routeDialog->current_contact_identity_id,
                    'employee_id' => $employee->id,
                    'platform' => $channel->platform,
                    'external_chat_id' => $routeDialog->external_chat_id,
                    'reply_to_message_id' => $replyToMessage?->id,
                    'fallback_used' => $fallbackUsed,
                ],
            );

            throw $throwable;
        }

        return DB::transaction(function () use ($channel, $contact, $deliveryResult, $employee, $replyToMessage, $routeDialog, $fallbackUsed): Message {
            $outboundMessage = $this->storeManualOutboundMessageAction->handle(
                $routeDialog,
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
                    'contact_id' => $contact->id,
                    'dialog_id' => $routeDialog->id,
                    'contact_identity_id' => $routeDialog->current_contact_identity_id,
                    'employee_id' => $employee->id,
                    'platform' => $channel->platform,
                    'external_chat_id' => $routeDialog->external_chat_id,
                    'outbound_external_message_id' => $deliveryResult->externalMessageId,
                    'reply_to_message_id' => $replyToMessage?->id,
                    'fallback_used' => $fallbackUsed,
                ],
            );

            return $outboundMessage;
        });
    }

    protected function resolveLegacyRouteSource(Contact $contact): ?Message
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
            ->first(fn (Message $message): bool => $this->resolveDialogRouteSourceAction->legacyMessageCanBeUsedAsRouteSource($message));
    }

    protected function resolveReplyToMessage(Dialog $routeDialog): ?Message
    {
        return Message::query()
            ->where('dialog_id', $routeDialog->id)
            ->where('direction', Message::DIRECTION_INBOUND)
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->first();
    }

    protected function sendTextMessage(Dialog $routeDialog, string $text): AutoReplyDeliveryResult
    {
        $routeDialog->loadMissing(['channel', 'currentContactIdentity']);

        $channel = $routeDialog->channel;

        if (! $channel instanceof Channel) {
            throw new InvalidArgumentException('Не найден активный канал для отправки ответа этому контакту.');
        }

        $externalUserId = $routeDialog->currentContactIdentity?->external_user_id;

        return match ($channel->platform) {
            Channel::PLATFORM_TELEGRAM => $this->telegramBotApiService->sendTextMessage($channel, $routeDialog->external_chat_id, $externalUserId, $text),
            Channel::PLATFORM_MAX => $this->maxBotApiService->sendTextMessage($channel, $routeDialog->external_chat_id, $externalUserId, $text),
            default => throw new InvalidArgumentException("Unsupported bot platform [{$channel->platform}]."),
        };
    }
}
