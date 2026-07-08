<?php

namespace App\Jobs;

use App\Data\Bots\StoredInboundMessageResult;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Dialog;
use App\Models\Message;
use App\Services\Bots\ChannelActivityLogger;
use App\Services\Bots\SendBotDialogTextAction;
use App\Services\Bots\StorePhoneCaptureConfirmationAction;
use App\Services\DataCollection\ResolveNextDataCollectionFieldAction;
use App\Services\Dialogs\DialogAutomationGate;
use App\Services\Dialogs\ResolveDialogRouteSourceAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessPhoneCaptureFollowUpJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public int $inboundMessageId,
        public string $phoneCaptureStatus = StoredInboundMessageResult::PHONE_CAPTURE_STATUS_CAPTURED_NEW,
    ) {
        $this->onQueue(ProcessAutoReplyJob::queueName());
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("phone-capture-followup:message:{$this->inboundMessageId}"))->expireAfter(180),
        ];
    }

    public function handle(
        SendBotDialogTextAction $sendBotDialogTextAction,
        StorePhoneCaptureConfirmationAction $storePhoneCaptureConfirmationAction,
        ChannelActivityLogger $channelActivityLogger,
        ResolveNextDataCollectionFieldAction $resolveNextDataCollectionFieldAction,
        ResolveDialogRouteSourceAction $resolveDialogRouteSourceAction,
        DialogAutomationGate $dialogAutomationGate,
    ): void {
        $message = Message::query()
            ->with(['channel', 'contact', 'contactIdentity', 'dialog.channel', 'dialog.contact', 'dialog.currentContactIdentity', 'dialog.dialogStage'])
            ->find($this->inboundMessageId);

        if (! $message instanceof Message) {
            return;
        }

        if ($message->direction !== Message::DIRECTION_INBOUND) {
            return;
        }

        if ($message->message_kind !== Message::KIND_INBOUND_CONTACT_SHARE) {
            return;
        }

        if (! $dialogAutomationGate->acceptsMessage($message)) {
            return;
        }

        $sourceChannel = $message->channel;

        if (! $sourceChannel instanceof Channel || ! $sourceChannel->is_active) {
            return;
        }

        $routeDialog = $resolveDialogRouteSourceAction->forMessage($message);
        $fallbackUsed = false;
        $confirmationWasSkippedBecauseDialogNotSendable = false;

        if (! $routeDialog) {
            $routeDialog = $resolveDialogRouteSourceAction->fallbackFromLegacyMessage($message);
            $fallbackUsed = $routeDialog !== null;
        }

        if (! $routeDialog && $message->dialog instanceof Dialog && $message->dialog->isBotBlockedByUser()) {
            $routeDialog = $message->dialog;
        }

        $channel = $routeDialog?->channel ?? $sourceChannel;
        $contact = $routeDialog?->contact ?? $message->contact;

        if ($routeDialog instanceof Dialog && ! $dialogAutomationGate->accepts($routeDialog)) {
            return;
        }

        if ($this->confirmationAlreadyExists($message)) {
            $channelActivityLogger->info(
                $channel,
                'contact.phone_capture_confirmation_skipped',
                'Подтверждение после получения номера уже существует.',
                $this->baseContext($message, $channel, $routeDialog?->id),
            );
        } else {
            if (! $routeDialog) {
                $channelActivityLogger->error(
                    $channel,
                    'contact.phone_capture_confirmation_route_missing',
                    'Не найден route context диалога для подтверждения после получения номера.',
                    $this->baseContext($message, $channel, null),
                );

                return;
            }

            if ($fallbackUsed) {
                $channelActivityLogger->warning(
                    $channel,
                    'contact.dialog_route_fallback_used',
                    'Для подтверждения после получения номера использован fallback route source через сообщение.',
                    $this->baseContext($message, $channel, $routeDialog->id),
                );
            }

            $confirmationText = $this->resolvePhoneCaptureReplyText($contact, $resolveNextDataCollectionFieldAction);

            try {
                $sendResult = $sendBotDialogTextAction->handleDialog(
                    $routeDialog,
                    $confirmationText,
                    ['remove_keyboard' => true],
                );

                if (! $sendResult->wasSent() || $sendResult->deliveryResult === null) {
                    $confirmationWasSkippedBecauseDialogNotSendable = true;

                    $channelActivityLogger->info(
                        $channel,
                        'contact.phone_capture_confirmation_skipped_dialog_not_sendable',
                        'Подтверждение после получения номера не отправлено: диалог сейчас недоступен для отправки.',
                        $this->baseContext($message, $channel, $routeDialog->id) + [
                            'route_status_code' => $sendResult->routeStatus->code,
                            'blocked_reason' => $sendResult->routeStatus->blockedReason,
                            'phone_capture_status' => $this->phoneCaptureStatus,
                        ],
                    );
                } else {
                    $deliveryResult = $sendResult->deliveryResult;

                    $storePhoneCaptureConfirmationAction->handle($routeDialog, $message, $deliveryResult);
                    $channel->markReplySent();

                    if ($this->phoneCaptureStatus === StoredInboundMessageResult::PHONE_CAPTURE_STATUS_MERGED_TO_ROOT) {
                        $channelActivityLogger->info(
                            $channel,
                            'contact.phone_capture_recognition_sent',
                            'После склейки контакта отправлено сообщение распознавания.',
                            $this->baseContext($message, $channel) + [
                                'dialog_id' => $routeDialog->id,
                                'phone_capture_status' => $this->phoneCaptureStatus,
                            ],
                        );
                    } else {
                        $channelActivityLogger->info(
                            $channel,
                            'contact.phone_capture_confirmed',
                            'Подтверждение после получения номера отправлено.',
                            $this->baseContext($message, $channel) + [
                                'dialog_id' => $routeDialog->id,
                                'phone_capture_status' => $this->phoneCaptureStatus,
                            ],
                        );
                    }
                }
            } catch (Throwable $throwable) {
                $channel->markError($throwable);

                $channelActivityLogger->error(
                    $channel,
                    'contact.phone_capture_confirmation_failed',
                    'Не удалось отправить подтверждение после получения номера.',
                    $this->baseContext($message, $channel, $routeDialog->id) + [
                        'error' => $throwable->getMessage(),
                    ],
                );

                Log::error('phone capture confirmation failed', [
                    'channel_id' => $channel->id,
                    'platform' => $channel->platform,
                    'message_id' => $message->id,
                    'dialog_id' => $routeDialog->id,
                    'error' => $throwable->getMessage(),
                ]);

                throw $throwable;
            }
        }

        if ($this->phoneCaptureStatus === StoredInboundMessageResult::PHONE_CAPTURE_STATUS_MERGED_TO_ROOT) {
            $this->maybeContinueDataCollectionAfterMerge($message, $channel, $contact, $channelActivityLogger, $resolveNextDataCollectionFieldAction);

            if ($confirmationWasSkippedBecauseDialogNotSendable) {
                $channelActivityLogger->info(
                    $channel,
                    'contact.phone_capture_follow_up_continued_after_skipped_confirmation',
                    'После пропуска подтверждения из-за blocked dialog сбор данных продолжен через pending collector flow.',
                    $this->baseContext($message, $channel, $routeDialog?->id) + [
                        'phone_capture_status' => $this->phoneCaptureStatus,
                    ],
                );
            }

            return;
        }

        $this->maybeStartDataCollection($message, $channel, $contact, $channelActivityLogger, $resolveNextDataCollectionFieldAction);

        if ($confirmationWasSkippedBecauseDialogNotSendable) {
            $channelActivityLogger->info(
                $channel,
                'contact.phone_capture_follow_up_continued_after_skipped_confirmation',
                'После пропуска подтверждения из-за blocked dialog сбор данных продолжен через pending collector flow.',
                $this->baseContext($message, $channel, $routeDialog?->id) + [
                    'phone_capture_status' => $this->phoneCaptureStatus,
                ],
            );
        }
    }

    protected function confirmationAlreadyExists(Message $message): bool
    {
        return $message->replies()
            ->where('message_kind', Message::KIND_OUTBOUND_PHONE_CAPTURE_CONFIRMATION)
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    protected function baseContext(Message $message, Channel $channel, ?int $dialogId = null): array
    {
        return array_filter([
            'contact_id' => $message->contact_id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialogId,
            'message_id' => $message->id,
            'platform' => $channel->platform,
            'button_type' => 'request_phone',
        ], static fn (mixed $value): bool => $value !== null);
    }

    protected function maybeStartDataCollection(
        Message $message,
        Channel $channel,
        ?Contact $contact,
        ChannelActivityLogger $channelActivityLogger,
        ResolveNextDataCollectionFieldAction $resolveNextDataCollectionFieldAction,
    ): void {
        if (! (bool) config('bots.data_collection.enabled', true)) {
            return;
        }

        if (! $contact instanceof Contact) {
            return;
        }

        if ($contact->isInDataCollection()) {
            return;
        }

        $nextField = $resolveNextDataCollectionFieldAction->handle($contact);

        if ($nextField === null) {
            return;
        }

        $contact->startDataCollection($nextField);

        $channelActivityLogger->info(
            $channel,
            'contact.data_collection_started',
            'После получения номера запущен сбор профиля.',
            [
                'contact_id' => $contact->id,
                'channel_id' => $channel->id,
                'message_id' => $message->id,
                'current_field' => $nextField,
            ],
        );

        ProcessDataCollectionQuestionJob::dispatch($message->id, false, $contact->id, $nextField);
    }

    protected function maybeContinueDataCollectionAfterMerge(
        Message $message,
        Channel $channel,
        ?Contact $contact,
        ChannelActivityLogger $channelActivityLogger,
        ResolveNextDataCollectionFieldAction $resolveNextDataCollectionFieldAction,
    ): void {
        if (! (bool) config('bots.data_collection.enabled', true)) {
            return;
        }

        if (! $contact instanceof Contact) {
            return;
        }

        if ($contact->isInDataCollection()) {
            if ($this->questionAlreadyExists($message)) {
                return;
            }

            $channelActivityLogger->info(
                $channel,
                'contact.data_collection_continued_after_merge',
                'После склейки контакт продолжил сбор профиля в текущем канале.',
                [
                    'contact_id' => $contact->id,
                    'channel_id' => $channel->id,
                    'message_id' => $message->id,
                    'current_field' => $contact->data_collection_current_field,
                ],
            );

            ProcessDataCollectionQuestionJob::dispatch(
                $message->id,
                false,
                $contact->id,
                $contact->data_collection_current_field,
            );

            return;
        }

        $nextField = $resolveNextDataCollectionFieldAction->handle($contact);

        if ($nextField === null) {
            return;
        }

        if ($this->questionAlreadyExists($message)) {
            return;
        }

        $contact->startDataCollection($nextField);

        $channelActivityLogger->info(
            $channel,
            'contact.data_collection_continued_after_merge',
            'После склейки контакт продолжил сбор профиля в текущем канале.',
            [
                'contact_id' => $contact->id,
                'channel_id' => $channel->id,
                'message_id' => $message->id,
                'current_field' => $nextField,
            ],
        );

        ProcessDataCollectionQuestionJob::dispatch($message->id, false, $contact->id, $nextField);
    }

    protected function questionAlreadyExists(Message $message): bool
    {
        return $message->replies()
            ->where('message_kind', Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION)
            ->exists();
    }

    protected function resolvePhoneCaptureReplyText(
        ?Contact $contact,
        ResolveNextDataCollectionFieldAction $resolveNextDataCollectionFieldAction,
    ): string {
        if ($this->phoneCaptureStatus !== StoredInboundMessageResult::PHONE_CAPTURE_STATUS_MERGED_TO_ROOT || ! $contact instanceof Contact) {
            return (string) config('bots.phone_capture_confirmation_text');
        }

        $hasIncompleteProfile = $contact->isInDataCollection()
            || $resolveNextDataCollectionFieldAction->handle($contact) !== null;

        return $this->renderRecognitionText(
            $hasIncompleteProfile
                ? 'bots.phone_capture_recognition_continue_text'
                : 'bots.phone_capture_recognition_full_profile_text',
            $hasIncompleteProfile
                ? 'Спасибо! Мы вас узнали. У нас осталось несколько вопросов.'
                : 'Спасибо! Мы вас узнали.',
            $contact,
        );
    }

    protected function renderRecognitionText(string $configKey, string $fallbackWithoutName, Contact $contact): string
    {
        $firstName = is_string($contact->first_name) ? trim($contact->first_name) : null;

        if (! filled($firstName)) {
            return $fallbackWithoutName;
        }

        $template = (string) config($configKey, $fallbackWithoutName);

        return str_replace('{name}', $firstName, $template);
    }
}
