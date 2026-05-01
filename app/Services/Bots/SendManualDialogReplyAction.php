<?php

namespace App\Services\Bots;

use App\Data\Bots\AutoReplyDeliveryResult;
use App\Data\Dialogs\DialogRouteStatusData;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Dialog;
use App\Models\Message;
use App\Models\User;
use App\Services\Contacts\ResolveRootContactAction;
use App\Services\Dialogs\MessageChronology;
use App\Services\Dialogs\ResolveDialogRouteStatusAction;
use App\Services\Messages\PrepareMessageContentAction;
use App\Services\TelegramAccount\QueueTelegramAccountManualReplyAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
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
        protected SendBotDialogTextAction $sendBotDialogTextAction,
        protected StoreManualOutboundMessageAction $storeManualOutboundMessageAction,
        protected QueueTelegramAccountManualReplyAction $queueTelegramAccountManualReplyAction,
        protected PrepareMessageContentAction $prepareMessageContentAction,
    ) {}

    public function handle(
        Dialog $dialog,
        User $employee,
        string $text,
        string $textFormat = Message::TEXT_FORMAT_PLAIN_TEXT,
    ): Message {
        if (! $employee->canReplyInDialogs()) {
            throw new AuthorizationException;
        }

        $dialog->loadMissing(['contact.assignedUser', 'channel', 'currentContactIdentity']);

        $contact = $dialog->contact;

        if (! $contact instanceof Contact) {
            throw new InvalidArgumentException('Не удалось определить контакт этого диалога.');
        }

        $effectiveContact = $this->resolveRootContactAction->handle($contact);

        $content = $this->prepareMessageContentAction->handle($text, $textFormat);

        $channel = $dialog->channel;

        if (! $channel instanceof Channel) {
            throw new InvalidArgumentException('У этого диалога сейчас нет рабочего маршрута для отправки ответа.');
        }

        $routeStatus = $this->resolveDialogRouteStatusAction->handle($dialog);

        if (! $routeStatus->isSendable) {
            throw new InvalidArgumentException($routeStatus->blockedReason ?? 'У этого диалога сейчас нет рабочего маршрута для отправки ответа.');
        }

        $replyToMessage = $this->resolveReplyToMessage($dialog);

        if ($channel->isAccountConnection()) {
            try {
                $outboundMessage = $this->queueTelegramAccountManualReplyAction->handle(
                    $dialog,
                    $employee,
                    $content,
                    $replyToMessage,
                );
            } catch (Throwable $throwable) {
                $channel->markError($throwable);

                $this->channelActivityLogger->error(
                    $channel,
                    'contact.reply_queue_failed',
                    'Ручной ответ не поставлен в очередь gateway.',
                    $this->buildFailureLogContext(
                        $dialog,
                        $effectiveContact,
                        $employee,
                        $replyToMessage,
                        $content->textFormat,
                    ),
                );

                throw $throwable;
            }

            $this->channelActivityLogger->info(
                $channel,
                'contact.reply_queued',
                'Ручной ответ поставлен в очередь gateway.',
                [
                    'contact_id' => $effectiveContact->id,
                    'dialog_id' => $dialog->id,
                    'contact_identity_id' => $dialog->current_contact_identity_id,
                    'employee_id' => $employee->id,
                    'platform' => $channel->platform,
                    'external_chat_id' => $dialog->external_chat_id,
                    'outgoing_message_id' => data_get($outboundMessage->raw_payload, 'outgoing_message_id'),
                    'reply_to_message_id' => $replyToMessage?->id,
                    'text_format' => $content->textFormat,
                ],
            );

            return $outboundMessage;
        }

        try {
            $deliveryResult = $this->sendTextMessage($dialog, $content->transportText, $content->textFormat);
        } catch (Throwable $throwable) {
            $routeStatus = $this->resolveDialogRouteStatusAction->handle($dialog);

            if ($routeStatus->code === DialogRouteStatusData::CODE_BLOCKED_BY_USER) {
                $this->channelActivityLogger->info(
                    $channel,
                    'contact.reply_skipped_dialog_not_sendable',
                    'Ручной ответ не отправлен: диалог сейчас недоступен для отправки.',
                    $this->buildFailureLogContext(
                        $dialog,
                        $effectiveContact,
                        $employee,
                        $replyToMessage,
                        $content->textFormat,
                    ) + [
                        'route_status_code' => $routeStatus->code,
                        'blocked_reason' => $routeStatus->blockedReason,
                    ],
                );
            } else {
                $channel->markError($throwable);

                $this->channelActivityLogger->error(
                    $channel,
                    'contact.reply_failed',
                    'Ручной ответ не отправлен.',
                    $this->buildFailureLogContext(
                        $dialog,
                        $effectiveContact,
                        $employee,
                        $replyToMessage,
                        $content->textFormat,
                    ),
                );
            }

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
            ->tap(fn (Builder $query): Builder => $this->messageChronology->applyLatestOrder($query))
            ->first();
    }

    protected function sendTextMessage(
        Dialog $dialog,
        string $text,
        string $textFormat = Message::TEXT_FORMAT_PLAIN_TEXT,
    ): AutoReplyDeliveryResult {
        $sendResult = $this->sendBotDialogTextAction->handleDialog(
            $dialog,
            $text,
            textFormat: $textFormat,
        );

        if (! $sendResult->wasSent() || ! $sendResult->deliveryResult instanceof AutoReplyDeliveryResult) {
            throw new InvalidArgumentException($sendResult->routeStatus->blockedReason ?? 'У этого диалога сейчас нет рабочего маршрута для отправки ответа.');
        }

        return $sendResult->deliveryResult;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFailureLogContext(
        Dialog $dialog,
        Contact $effectiveContact,
        User $employee,
        ?Message $replyToMessage,
        string $textFormat,
    ): array {
        return [
            'contact_id' => $effectiveContact->id,
            'dialog_id' => $dialog->id,
            'contact_identity_id' => $dialog->current_contact_identity_id,
            'employee_id' => $employee->id,
            'platform' => $dialog->channel?->platform,
            'external_chat_id' => $dialog->external_chat_id,
            'reply_to_message_id' => $replyToMessage?->id,
            'text_format' => $textFormat,
        ];
    }
}
