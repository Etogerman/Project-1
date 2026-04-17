<?php

namespace App\Services\Bots;

use App\Data\Bots\BotDialogTextSendResult;
use App\Data\Dialogs\DialogRouteStatusData;
use App\Models\Channel;
use App\Models\Dialog;
use App\Models\Message;
use App\Services\Dialogs\ResolveDialogRouteSourceAction;
use App\Services\Dialogs\ResolveDialogRouteStatusAction;
use InvalidArgumentException;

class SendBotDialogTextAction
{
    private const GENERIC_BLOCKED_REASON = 'У этого диалога сейчас нет рабочего маршрута для отправки ответа.';

    public function __construct(
        private readonly ResolveDialogRouteStatusAction $resolveDialogRouteStatusAction,
        private readonly ResolveDialogRouteSourceAction $resolveDialogRouteSourceAction,
        private readonly TelegramBotApiService $telegramBotApiService,
        private readonly MaxBotApiService $maxBotApiService,
    ) {}

    /**
     * @param  array<string, mixed>|null  $telegramReplyMarkup
     * @param  array<int, array<string, mixed>>|null  $maxAttachments
     */
    public function handleDialog(
        Dialog $dialog,
        string $text,
        ?array $telegramReplyMarkup = null,
        ?array $maxAttachments = null,
        string $textFormat = Message::TEXT_FORMAT_PLAIN_TEXT,
    ): BotDialogTextSendResult {
        $dialog->loadMissing(['channel', 'currentContactIdentity']);

        $routeStatus = $this->resolveDialogRouteStatusAction->handle($dialog);

        if (! $routeStatus->isSendable) {
            return new BotDialogTextSendResult(
                routeStatus: $routeStatus,
                dialog: $dialog,
            );
        }

        $channel = $dialog->channel;

        if (! $channel instanceof Channel) {
            throw new InvalidArgumentException(self::GENERIC_BLOCKED_REASON);
        }

        try {
            $deliveryResult = match ($channel->platform) {
                Channel::PLATFORM_TELEGRAM => $this->telegramBotApiService->sendTextMessage(
                    $channel,
                    $dialog->external_chat_id,
                    $dialog->currentContactIdentity?->external_user_id,
                    $text,
                    $telegramReplyMarkup,
                    $textFormat,
                ),
                Channel::PLATFORM_MAX => $this->maxBotApiService->sendTextMessage(
                    $channel,
                    $dialog->external_chat_id,
                    $dialog->currentContactIdentity?->external_user_id,
                    $text,
                    $maxAttachments,
                    $textFormat,
                ),
                default => throw new InvalidArgumentException(self::GENERIC_BLOCKED_REASON),
            };
        } catch (MaxDialogSuspendedException) {
            $this->markMaxDialogSuspended($dialog);
            $dialog->refresh()->loadMissing(['channel', 'currentContactIdentity']);

            return new BotDialogTextSendResult(
                routeStatus: $this->resolveDialogRouteStatusAction->handle($dialog),
                dialog: $dialog,
            );
        }

        return new BotDialogTextSendResult(
            routeStatus: $routeStatus,
            dialog: $dialog,
            deliveryResult: $deliveryResult,
        );
    }

    /**
     * @param  array<string, mixed>|null  $telegramReplyMarkup
     * @param  array<int, array<string, mixed>>|null  $maxAttachments
     */
    public function handleMessage(
        Message $message,
        string $text,
        ?Dialog $preferredDialog = null,
        ?array $telegramReplyMarkup = null,
        ?array $maxAttachments = null,
        string $textFormat = Message::TEXT_FORMAT_PLAIN_TEXT,
    ): BotDialogTextSendResult {
        if ($preferredDialog instanceof Dialog) {
            return $this->handleDialog(
                $preferredDialog,
                $text,
                $telegramReplyMarkup,
                $maxAttachments,
                $textFormat,
            );
        }

        $sendableDialog = $this->resolveDialogRouteSourceAction->forMessage($message);

        if ($sendableDialog instanceof Dialog) {
            return $this->handleDialog(
                $sendableDialog,
                $text,
                $telegramReplyMarkup,
                $maxAttachments,
                $textFormat,
            );
        }

        $fallbackDialog = $this->resolveDialogRouteSourceAction->fallbackFromLegacyMessage($message);

        if ($fallbackDialog instanceof Dialog) {
            return $this->handleDialog(
                $fallbackDialog,
                $text,
                $telegramReplyMarkup,
                $maxAttachments,
                $textFormat,
            );
        }

        $message->loadMissing(['dialog.channel', 'dialog.currentContactIdentity']);

        if ($message->dialog instanceof Dialog) {
            return $this->handleDialog(
                $message->dialog,
                $text,
                $telegramReplyMarkup,
                $maxAttachments,
                $textFormat,
            );
        }

        return new BotDialogTextSendResult(
            routeStatus: new DialogRouteStatusData(
                code: DialogRouteStatusData::CODE_MISSING_ROUTE_SOURCE,
                label: 'Маршрут недоступен',
                tone: 'warning',
                isSendable: false,
                blockedReason: self::GENERIC_BLOCKED_REASON,
            ),
        );
    }

    private function markMaxDialogSuspended(Dialog $dialog): void
    {
        $dialog->forceFill([
            'bot_subscription_status' => Dialog::BOT_SUBSCRIPTION_STATUS_BLOCKED_BY_USER,
            'bot_subscription_changed_at' => now(),
            'bot_subscription_source_message_id' => null,
        ])->save();
    }
}
