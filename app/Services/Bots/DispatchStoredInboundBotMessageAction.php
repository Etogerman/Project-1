<?php

namespace App\Services\Bots;

use App\Data\Bots\StoredInboundMessageResult;
use App\Jobs\ProcessAutoReplyJob;
use App\Jobs\ProcessDataCollectionQuestionJob;
use App\Jobs\ProcessDataCollectionResponseJob;
use App\Jobs\ProcessPhoneCaptureFollowUpJob;
use App\Models\Channel;
use App\Models\Message;
use App\Services\Scenarios\DispatchStoredInboundScenarioAction;

class DispatchStoredInboundBotMessageAction
{
    private const VIP_IBIZA_BUSY_STATE_REPLY = 'У тебя уже есть активная анкета. Сначала заверши её.';

    public function __construct(
        protected ChannelActivityLogger $channelActivityLogger,
        protected DispatchStoredInboundScenarioAction $dispatchStoredInboundScenarioAction,
        protected SendBotDialogTextAction $sendBotDialogTextAction,
        protected StoreOutboundAutoReplyMessageAction $storeOutboundAutoReplyMessageAction,
    ) {}

    public function handle(
        Channel $channel,
        StoredInboundMessageResult $storedResult,
        ?int $deliveryLagSeconds = null,
    ): void {
        $storedMessage = $storedResult->message;

        $duplicateContext = [
            'platform' => $channel->platform,
            'provider_event_key' => $storedMessage->provider_event_key,
            'message_id' => $storedMessage->id,
            'external_message_id' => $storedMessage->external_message_id,
        ];

        if ($storedMessage->wasRecentlyCreated) {
            $this->logOutOfOrderInboundIfNeeded($channel, $storedMessage);
        }

        if ($storedMessage->hasSuccessfulAutoReply()) {
            if (! $storedMessage->wasRecentlyCreated) {
                $this->channelActivityLogger->info(
                    $channel,
                    'webhook.duplicate_ignored',
                    'Повторный webhook обработан без повторной отправки ответа.',
                    $duplicateContext,
                );
            }

            return;
        }

        if ($storedMessage->message_kind === Message::KIND_INBOUND_CONTACT_SHARE) {
            if (
                $storedResult->shouldQueuePhoneCaptureFollowUp()
                && $this->dispatchStoredInboundScenarioAction->continueActiveRun($storedMessage)
            ) {
                return;
            }

            $this->dispatchContactShareFollowUp($channel, $storedMessage, $storedResult, $deliveryLagSeconds);

            return;
        }

        if ($this->isStoredMaxBotStartedEventWithoutParameter($channel, $storedMessage)) {
            return;
        }

        if ($this->dispatchStoredInboundScenarioAction->shouldBlockVipIbizaParameterStartBecauseBusyState($storedMessage)) {
            $this->sendVipIbizaBusyStateReply($channel, $storedMessage, $duplicateContext);

            return;
        }

        if ($this->isAutoReplyOnlyMaxBotStartedEvent($channel, $storedMessage)) {
            if ($this->dispatchStoredInboundScenarioAction->handle($channel, $storedMessage)) {
                return;
            }

            $this->queueAutoReply($channel, $storedMessage, $duplicateContext);

            return;
        }

        if ($storedMessage->message_kind !== Message::KIND_INBOUND_USER) {
            return;
        }

        if ($this->dispatchStoredInboundScenarioAction->continueActiveRun($storedMessage)) {
            return;
        }

        $storedMessage->loadMissing('contact');

        if (! $storedMessage->wasRecentlyCreated && $storedMessage->contact?->isInDataCollection()) {
            $this->channelActivityLogger->info(
                $channel,
                'webhook.duplicate_ignored',
                'Повторный webhook с ответом анкеты проигнорирован, чтобы не переиграть уже сохранённый ответ.',
                $duplicateContext + [
                    'contact_id' => $storedMessage->contact_id,
                    'current_field' => $storedMessage->contact?->data_collection_current_field,
                ],
            );

            return;
        }

        if ($storedMessage->contact?->isInDataCollection()) {
            if ($this->currentDataCollectionFieldIsPendingPrompt($storedMessage)) {
                ProcessDataCollectionQuestionJob::dispatch(
                    $storedMessage->id,
                    false,
                    $storedMessage->contact_id,
                    $storedMessage->contact?->data_collection_current_field,
                )->afterCommit();

                $this->channelActivityLogger->info(
                    $channel,
                    'contact.data_collection_pending_question_queued',
                    'Текущий шаг анкеты ещё не был задан пользователю: вместо обработки ответа поставлена в очередь повторная отправка вопроса.',
                    [
                        'platform' => $channel->platform,
                        'message_id' => $storedMessage->id,
                        'contact_id' => $storedMessage->contact_id,
                        'current_field' => $storedMessage->contact?->data_collection_current_field,
                    ],
                );

                return;
            }

            ProcessDataCollectionResponseJob::dispatch(
                $storedMessage->id,
                $storedMessage->contact_id,
                $storedMessage->contact?->data_collection_current_field,
            )->afterCommit();

            $this->channelActivityLogger->info(
                $channel,
                'contact.data_collection_response_queued',
                'Ответ пользователя поставлен в очередь на обработку сборщиком профиля.',
                [
                    'platform' => $channel->platform,
                    'message_id' => $storedMessage->id,
                    'contact_id' => $storedMessage->contact_id,
                    'current_field' => $storedMessage->contact?->data_collection_current_field,
                ],
            );

            return;
        }

        if ($this->dispatchStoredInboundScenarioAction->startMatchingScenario($channel, $storedMessage)) {
            return;
        }

        $this->queueAutoReply($channel, $storedMessage, $duplicateContext);
    }

    protected function currentDataCollectionFieldIsPendingPrompt(Message $storedMessage): bool
    {
        $storedMessage->loadMissing('contact');

        $currentField = $storedMessage->contact?->data_collection_current_field;

        if (! filled($currentField)) {
            return false;
        }

        return $storedMessage->contact?->data_collection_last_prompted_field !== $currentField;
    }

    protected function dispatchContactShareFollowUp(
        Channel $channel,
        Message $storedMessage,
        StoredInboundMessageResult $storedResult,
        ?int $deliveryLagSeconds,
    ): void {
        if (
            $deliveryLagSeconds !== null
            && $storedMessage->wasRecentlyCreated
            && $storedResult->shouldQueuePhoneCaptureFollowUp()
        ) {
            $this->channelActivityLogger->info(
                $channel,
                'contact.phone_capture_arrived_late',
                'Поздний phone share из MAX успешно дошёл до обработки.',
                [
                    'platform' => $channel->platform,
                    'contact_id' => $storedMessage->contact_id,
                    'message_id' => $storedMessage->id,
                    'provider_event_key' => $storedMessage->provider_event_key,
                    'external_message_id' => $storedMessage->external_message_id,
                    'phone_capture_status' => $storedResult->phoneCaptureStatus,
                    'delivery_lag_seconds' => $deliveryLagSeconds,
                ],
            );
        }

        if (! $storedResult->shouldQueuePhoneCaptureFollowUp()) {
            return;
        }

        ProcessPhoneCaptureFollowUpJob::dispatch($storedMessage->id, $storedResult->phoneCaptureStatus)->afterCommit();

        $this->channelActivityLogger->info(
            $channel,
            'contact.phone_capture_confirmation_queued',
            'Подтверждение после получения номера поставлено в очередь.',
            [
                'platform' => $channel->platform,
                'contact_id' => $storedMessage->contact_id,
                'message_id' => $storedMessage->id,
                'button_type' => 'request_phone',
                'phone_capture_status' => $storedResult->phoneCaptureStatus,
            ],
        );
    }

    protected function isStoredMaxBotStartedEvent(Channel $channel, Message $storedMessage): bool
    {
        return $channel->platform === Channel::PLATFORM_MAX
            && $storedMessage->direction === Message::DIRECTION_INBOUND
            && data_get($storedMessage->raw_payload, 'update_type') === 'bot_started';
    }

    protected function isStoredMaxBotStartedEventWithoutParameter(Channel $channel, Message $storedMessage): bool
    {
        return $this->isStoredMaxBotStartedEvent($channel, $storedMessage)
            && ! filled($storedMessage->message_parameter);
    }

    protected function isAutoReplyOnlyMaxBotStartedEvent(Channel $channel, Message $storedMessage): bool
    {
        return $this->isStoredMaxBotStartedEvent($channel, $storedMessage)
            && filled($storedMessage->message_parameter);
    }

    /**
     * @param  array<string, mixed>  $duplicateContext
     */
    protected function queueAutoReply(Channel $channel, Message $storedMessage, array $duplicateContext): void
    {
        if (! $storedMessage->wasRecentlyCreated) {
            $this->channelActivityLogger->info(
                $channel,
                'webhook.duplicate_retry_reply',
                'Повторный webhook поставил автоответ в очередь повторно.',
                $duplicateContext,
            );
        }

        ProcessAutoReplyJob::dispatch($storedMessage->id)->afterCommit();

        $this->channelActivityLogger->info(
            $channel,
            'bot.reply_queued',
            'Автоответ поставлен в очередь.',
            [
                'platform' => $channel->platform,
                'message_id' => $storedMessage->id,
                'provider_event_key' => $storedMessage->provider_event_key,
                'external_message_id' => $storedMessage->external_message_id,
                'auto_reply_mode' => $channel->auto_reply_mode ?? Channel::AUTO_REPLY_MODE_RULES_ONLY,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $duplicateContext
     */
    protected function sendVipIbizaBusyStateReply(Channel $channel, Message $storedMessage, array $duplicateContext): void
    {
        $sendResult = $this->sendBotDialogTextAction->handleMessage(
            $storedMessage,
            self::VIP_IBIZA_BUSY_STATE_REPLY,
        );

        if (! $sendResult->wasSent() || $sendResult->deliveryResult === null) {
            $this->channelActivityLogger->info(
                $channel,
                'scenario.vip_ibiza_start_blocked_dialog_not_sendable',
                'Deep link VIP Ibiza не отправил busy-state reply: диалог сейчас недоступен для отправки.',
                $duplicateContext + [
                    'dialog_id' => $sendResult->dialog?->id ?? $storedMessage->dialog_id,
                    'route_status_code' => $sendResult->routeStatus->code,
                    'blocked_reason' => $sendResult->routeStatus->blockedReason,
                ],
            );

            return;
        }

        $deliveryResult = $sendResult->deliveryResult;

        $outboundMessage = $this->storeOutboundAutoReplyMessageAction->handle(
            $channel,
            $storedMessage,
            $deliveryResult,
            null,
            $sendResult->dialog,
        );

        $this->channelActivityLogger->info(
            $channel,
            'scenario.vip_ibiza_start_blocked_busy_state',
            'Deep link VIP Ibiza отклонён: в диалоге уже есть активный процесс или активная анкета.',
            $duplicateContext + [
                'outbound_message_id' => $outboundMessage->id,
                'dialog_id' => $storedMessage->dialog_id,
            ],
        );
    }

    protected function logOutOfOrderInboundIfNeeded(Channel $channel, Message $storedMessage): void
    {
        if (
            $channel->platform !== Channel::PLATFORM_MAX
            || $storedMessage->direction !== Message::DIRECTION_INBOUND
            || $storedMessage->received_at === null
            || $storedMessage->contact_id === null
        ) {
            return;
        }

        $newerInbound = Message::query()
            ->where('channel_id', $storedMessage->channel_id)
            ->where('contact_id', $storedMessage->contact_id)
            ->where('direction', Message::DIRECTION_INBOUND)
            ->whereKeyNot($storedMessage->id)
            ->whereNotNull('received_at')
            ->where('received_at', '>', $storedMessage->received_at)
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->first();

        if (! $newerInbound instanceof Message || $newerInbound->received_at === null) {
            return;
        }

        $this->channelActivityLogger->info(
            $channel,
            'webhook.out_of_order_received',
            'Webhook из MAX получен не по порядку относительно уже сохранённых входящих сообщений.',
            [
                'platform' => $channel->platform,
                'contact_id' => $storedMessage->contact_id,
                'message_id' => $storedMessage->id,
                'provider_event_key' => $storedMessage->provider_event_key,
                'external_message_id' => $storedMessage->external_message_id,
                'received_at' => $storedMessage->received_at->toIso8601String(),
                'newer_inbound_message_id' => $newerInbound->id,
                'newer_inbound_received_at' => $newerInbound->received_at->toIso8601String(),
                'seconds_behind_latest_inbound' => max(
                    0,
                    $newerInbound->received_at->getTimestamp() - $storedMessage->received_at->getTimestamp(),
                ),
            ],
        );
    }
}
