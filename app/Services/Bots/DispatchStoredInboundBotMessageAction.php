<?php

namespace App\Services\Bots;

use App\Data\Bots\StoredInboundMessageResult;
use App\Jobs\ProcessAutoReplyJob;
use App\Jobs\ProcessDataCollectionQuestionJob;
use App\Jobs\ProcessDataCollectionResponseJob;
use App\Jobs\ProcessPhoneCaptureFollowUpJob;
use App\Models\Channel;
use App\Models\ChannelActivityLog;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Scenario;
use App\Services\DataCollection\DataCollectionPromptHelper;
use App\Services\Questionnaires\HandleContactQuestionnaireAnswerAction;
use App\Services\Scenarios\DispatchStoredInboundScenarioAction;

class DispatchStoredInboundBotMessageAction
{
    private const VIP_IBIZA_BUSY_STATE_REPLY = 'У тебя уже есть активная анкета. Сначала заверши её.';

    public function __construct(
        protected ChannelActivityLogger $channelActivityLogger,
        protected DispatchStoredInboundScenarioAction $dispatchStoredInboundScenarioAction,
        protected SendBotDialogTextAction $sendBotDialogTextAction,
        protected StoreOutboundAutoReplyMessageAction $storeOutboundAutoReplyMessageAction,
        protected DataCollectionPromptHelper $dataCollectionPromptHelper,
        protected HandleContactQuestionnaireAnswerAction $handleContactQuestionnaireAnswerAction,
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

            if (
                $storedResult->shouldQueuePhoneCaptureFollowUp()
                && $this->dispatchStoredInboundScenarioAction->hasActiveV3Run($storedMessage)
            ) {
                $this->logPhoneCaptureConfirmationSuppressedForV3($channel, $storedMessage, $storedResult, 'active_v3_run');

                return;
            }

            if (
                $storedResult->shouldQueuePhoneCaptureFollowUp()
                && $this->hasRecentV3RequestContactPrompt($storedMessage)
            ) {
                $this->logPhoneCaptureConfirmationSuppressedForV3($channel, $storedMessage, $storedResult, 'recent_v3_request_contact_prompt');

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

        if ($this->dispatchStoredInboundScenarioAction->startPriorityScenario($channel, $storedMessage)) {
            return;
        }

        if ($this->handleContactQuestionnaireAnswerAction->handle($storedMessage)) {
            return;
        }

        if ($this->dispatchStoredInboundScenarioAction->continueActiveRun($storedMessage)) {
            return;
        }

        $storedMessage->loadMissing('contact');

        if (! $storedMessage->wasRecentlyCreated && $this->usesLegacyCollector() && $storedMessage->contact?->isInDataCollection()) {
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

        if ($this->usesLegacyCollector() && $storedMessage->contact?->isInDataCollection()) {
            if ($this->currentDataCollectionFieldIsPendingPrompt($channel, $storedMessage)) {
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

    protected function currentDataCollectionFieldIsPendingPrompt(Channel $channel, Message $storedMessage): bool
    {
        $storedMessage->loadMissing('contact');
        $contact = $storedMessage->contact;

        if (! $contact instanceof Contact) {
            return false;
        }

        $currentField = $contact->data_collection_current_field;

        if (! filled($currentField)) {
            return false;
        }

        if ($this->contactAlreadyHasPromptForCurrentField($channel, $contact, $currentField)) {
            return false;
        }

        return $contact->data_collection_last_prompted_field !== $currentField;
    }

    protected function usesLegacyCollector(): bool
    {
        return config('bots.data_collection.profile_collection_engine') !== 'questionnaires';
    }

    protected function contactAlreadyHasPromptForCurrentField(Channel $channel, Contact $contact, string $currentField): bool
    {
        $query = $contact->messages()
            ->where('direction', Message::DIRECTION_OUTBOUND)
            ->where('message_kind', Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION)
            ->where('message_parameter', $currentField);

        $this->applyReceivedAtBoundary(
            $query,
            $contact->data_collection_current_field_started_at ?? $contact->data_collection_started_at,
        );

        if ($query->exists()) {
            return true;
        }

        if ($this->legacyQuestionWasLoggedForCurrentField($contact, $currentField)) {
            return true;
        }

        if ($currentField === Contact::DATA_COLLECTION_FIELD_CITY) {
            return false;
        }

        $questionText = $this->resolveCurrentFieldQuestionText($channel, $contact, $currentField);

        if (! filled($questionText)) {
            return false;
        }

        $legacyQuery = $contact->messages()
            ->where('direction', Message::DIRECTION_OUTBOUND)
            ->where('message_kind', Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION)
            ->whereNull('message_parameter')
            ->where('text', $questionText);

        $this->applyReceivedAtBoundary(
            $legacyQuery,
            $contact->data_collection_current_field_started_at ?? $contact->data_collection_started_at,
        );

        return $legacyQuery->exists();
    }

    protected function legacyQuestionWasLoggedForCurrentField(Contact $contact, string $currentField): bool
    {
        $query = ChannelActivityLog::query()
            ->where('event', 'contact.data_collection_question_sent')
            ->where('context->contact_id', $contact->id)
            ->where('context->current_field', $currentField);

        $this->applyCreatedAtBoundary(
            $query,
            $contact->data_collection_current_field_started_at ?? $contact->data_collection_started_at,
        );

        return $query->exists();
    }

    protected function resolveCurrentFieldQuestionText(Channel $channel, Contact $contact, string $currentField): ?string
    {
        return match ($currentField) {
            Contact::DATA_COLLECTION_FIELD_RUSSIAN_REGION_CONFIRM => $this->dataCollectionPromptHelper->russianRegionConfirmQuestionText($contact),
            Contact::DATA_COLLECTION_FIELD_FIRST_NAME,
            Contact::DATA_COLLECTION_FIELD_RESIDENCE_CITY,
            Contact::DATA_COLLECTION_FIELD_COUNTRY,
            Contact::DATA_COLLECTION_FIELD_CITY,
            Contact::DATA_COLLECTION_FIELD_AGE_RANGE => $this->dataCollectionPromptHelper->questionText($currentField, $channel->platform),
            default => null,
        };
    }

    protected function applyReceivedAtBoundary($query, $boundary): void
    {
        if ($boundary === null) {
            return;
        }

        $query->where('received_at', '>=', $boundary);
    }

    protected function applyCreatedAtBoundary($query, $boundary): void
    {
        if ($boundary === null) {
            return;
        }

        $query->where('created_at', '>=', $boundary);
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

    protected function logPhoneCaptureConfirmationSuppressedForV3(
        Channel $channel,
        Message $storedMessage,
        StoredInboundMessageResult $storedResult,
        string $reason,
    ): void {
        $this->channelActivityLogger->info(
            $channel,
            'contact.phone_capture_confirmation_suppressed_for_v3',
            'Глобальное подтверждение телефона не отправлено, потому что телефон обрабатывается V3-конструктором.',
            [
                'platform' => $channel->platform,
                'contact_id' => $storedMessage->contact_id,
                'message_id' => $storedMessage->id,
                'phone_capture_status' => $storedResult->phoneCaptureStatus,
                'reason' => $reason,
            ],
        );
    }

    protected function hasRecentV3RequestContactPrompt(Message $storedMessage): bool
    {
        if ($storedMessage->dialog_id === null) {
            return false;
        }

        $createdAt = $storedMessage->created_at ?? now();

        return Message::query()
            ->where('dialog_id', $storedMessage->dialog_id)
            ->where('direction', Message::DIRECTION_OUTBOUND)
            ->where('message_kind', Message::KIND_OUTBOUND_SCENARIO_MESSAGE)
            ->where('sent_by_system_code', 'scenario_'.Scenario::CONSTRUCTOR_WORKSPACE_CODE)
            ->where('id', '<', $storedMessage->id)
            ->where('created_at', '>=', $createdAt->copy()->subMinutes(30))
            ->latest('id')
            ->limit(10)
            ->get()
            ->contains(fn (Message $message): bool => $this->outboundMessageHasRequestContactButton($message));
    }

    protected function outboundMessageHasRequestContactButton(Message $message): bool
    {
        return $this->payloadHasRequestContactButton($message->raw_payload);
    }

    protected function payloadHasRequestContactButton(mixed $payload): bool
    {
        if (! is_array($payload)) {
            return false;
        }

        if (($payload['type'] ?? null) === 'request_contact' || ($payload['request_contact'] ?? false) === true) {
            return true;
        }

        foreach ($payload as $value) {
            if ($this->payloadHasRequestContactButton($value)) {
                return true;
            }
        }

        return false;
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
