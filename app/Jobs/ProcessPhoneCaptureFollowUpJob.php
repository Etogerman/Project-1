<?php

namespace App\Jobs;

use App\Data\Bots\StoredInboundMessageResult;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Services\Bots\ChannelActivityLogger;
use App\Services\DataCollection\ResolveNextDataCollectionFieldAction;
use App\Services\Bots\MaxBotApiService;
use App\Services\Bots\StorePhoneCaptureConfirmationAction;
use App\Services\Bots\TelegramBotApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
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
    ) {}

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
        TelegramBotApiService $telegramBotApiService,
        MaxBotApiService $maxBotApiService,
        StorePhoneCaptureConfirmationAction $storePhoneCaptureConfirmationAction,
        ChannelActivityLogger $channelActivityLogger,
        ResolveNextDataCollectionFieldAction $resolveNextDataCollectionFieldAction,
    ): void {
        $message = Message::query()
            ->with(['channel', 'contact', 'contactIdentity'])
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

        $channel = $message->channel;

        if (! $channel instanceof Channel || ! $channel->is_active) {
            return;
        }

        $contact = $message->contact;

        if ($this->confirmationAlreadyExists($message)) {
            $channelActivityLogger->info(
                $channel,
                'contact.phone_capture_confirmation_skipped',
                'Подтверждение после получения номера уже существует.',
                $this->baseContext($message, $channel),
            );
        } else {
            $confirmationText = $this->resolvePhoneCaptureReplyText($contact, $resolveNextDataCollectionFieldAction);

            try {
                $deliveryResult = match ($channel->platform) {
                    Channel::PLATFORM_TELEGRAM => $telegramBotApiService->sendTextMessage(
                        $channel,
                        $message->external_chat_id,
                        $message->contactIdentity?->external_user_id,
                        $confirmationText,
                        ['remove_keyboard' => true],
                    ),
                    Channel::PLATFORM_MAX => $maxBotApiService->sendTextMessage(
                        $channel,
                        $message->external_chat_id,
                        $message->contactIdentity?->external_user_id,
                        $confirmationText,
                    ),
                    default => throw new InvalidArgumentException("Unsupported bot platform [{$channel->platform}]."),
                };

                $storePhoneCaptureConfirmationAction->handle($channel, $message, $deliveryResult);
                $channel->markReplySent();

                if ($this->phoneCaptureStatus === StoredInboundMessageResult::PHONE_CAPTURE_STATUS_MERGED_TO_ROOT) {
                    $channelActivityLogger->info(
                        $channel,
                        'contact.phone_capture_recognition_sent',
                        'После склейки контакта отправлено сообщение распознавания.',
                        $this->baseContext($message, $channel) + [
                            'phone_capture_status' => $this->phoneCaptureStatus,
                        ],
                    );
                } else {
                    $channelActivityLogger->info(
                        $channel,
                        'contact.phone_capture_confirmed',
                        'Подтверждение после получения номера отправлено.',
                        $this->baseContext($message, $channel) + [
                            'phone_capture_status' => $this->phoneCaptureStatus,
                        ],
                    );
                }
            } catch (Throwable $throwable) {
                $channel->markError($throwable);

                $channelActivityLogger->error(
                    $channel,
                    'contact.phone_capture_confirmation_failed',
                    'Не удалось отправить подтверждение после получения номера.',
                    $this->baseContext($message, $channel) + [
                        'error' => $throwable->getMessage(),
                    ],
                );

                Log::error('phone capture confirmation failed', [
                    'channel_id' => $channel->id,
                    'platform' => $channel->platform,
                    'message_id' => $message->id,
                    'error' => $throwable->getMessage(),
                ]);

                throw $throwable;
            }
        }

        if ($this->phoneCaptureStatus === StoredInboundMessageResult::PHONE_CAPTURE_STATUS_MERGED_TO_ROOT) {
            $this->maybeContinueDataCollectionAfterMerge($message, $channel, $channelActivityLogger, $resolveNextDataCollectionFieldAction);

            return;
        }

        $this->maybeStartDataCollection($message, $channel, $channelActivityLogger, $resolveNextDataCollectionFieldAction);
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
    protected function baseContext(Message $message, Channel $channel): array
    {
        return [
            'contact_id' => $message->contact_id,
            'channel_id' => $channel->id,
            'message_id' => $message->id,
            'platform' => $channel->platform,
            'button_type' => 'request_phone',
        ];
    }

    protected function maybeStartDataCollection(
        Message $message,
        Channel $channel,
        ChannelActivityLogger $channelActivityLogger,
        ResolveNextDataCollectionFieldAction $resolveNextDataCollectionFieldAction,
    ): void {
        if (! (bool) config('bots.data_collection.enabled', true)) {
            return;
        }

        $contact = $message->contact;

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

        ProcessDataCollectionQuestionJob::dispatch($message->id);
    }

    protected function maybeContinueDataCollectionAfterMerge(
        Message $message,
        Channel $channel,
        ChannelActivityLogger $channelActivityLogger,
        ResolveNextDataCollectionFieldAction $resolveNextDataCollectionFieldAction,
    ): void {
        if (! (bool) config('bots.data_collection.enabled', true)) {
            return;
        }

        $contact = $message->contact;

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

            ProcessDataCollectionQuestionJob::dispatch($message->id);

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

        ProcessDataCollectionQuestionJob::dispatch($message->id);
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
