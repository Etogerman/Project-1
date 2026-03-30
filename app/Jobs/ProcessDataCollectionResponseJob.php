<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Services\DataCollection\ExtractFirstNameAction;
use App\Services\Bots\ChannelActivityLogger;
use App\Services\Bots\MaxBotApiService;
use App\Services\Bots\StoreDataCollectionOutboundMessageAction;
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

class ProcessDataCollectionResponseJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public int $inboundMessageId) {}

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
            (new WithoutOverlapping("data-collection-response:message:{$this->inboundMessageId}"))->expireAfter(180),
        ];
    }

    public function handle(
        TelegramBotApiService $telegramBotApiService,
        MaxBotApiService $maxBotApiService,
        StoreDataCollectionOutboundMessageAction $storeDataCollectionOutboundMessageAction,
        ChannelActivityLogger $channelActivityLogger,
        ExtractFirstNameAction $extractFirstNameAction,
    ): void {
        if (! (bool) config('bots.data_collection.enabled', true)) {
            return;
        }

        $message = Message::query()
            ->with(['channel', 'contact', 'contactIdentity'])
            ->find($this->inboundMessageId);

        if (! $message instanceof Message) {
            return;
        }

        if ($message->direction !== Message::DIRECTION_INBOUND || $message->message_kind !== Message::KIND_INBOUND_USER) {
            return;
        }

        if ($message->auto_reply_sent_at !== null) {
            return;
        }

        $channel = $message->channel;
        $contact = $message->contact;

        if (! $channel instanceof Channel || ! $channel->is_active || ! $contact instanceof Contact) {
            return;
        }

        if (! $contact->isInDataCollection() || $contact->data_collection_current_field !== Contact::DATA_COLLECTION_FIELD_FIRST_NAME) {
            return;
        }

        $replyText = trim((string) ($message->text ?? ''));

        if ($replyText === '') {
            $this->handleRetry(
                message: $message,
                channel: $channel,
                contact: $contact,
                text: $this->retryMessage(),
                telegramBotApiService: $telegramBotApiService,
                maxBotApiService: $maxBotApiService,
                storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
                channelActivityLogger: $channelActivityLogger,
            );
            return;
        }

        if ($this->isLocalSkipCommand($replyText)) {
            $this->sendReply(
                message: $message,
                channel: $channel,
                text: $this->skipMessage(),
                messageKind: Message::KIND_OUTBOUND_DATA_COLLECTION_COMPLETION,
                telegramBotApiService: $telegramBotApiService,
                maxBotApiService: $maxBotApiService,
                storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
                channelActivityLogger: $channelActivityLogger,
                activityEvent: 'contact.data_collection_skipped',
                activityMessage: 'Пользователь пропустил шаг сбора имени.',
            );

            $contact->completeDataCollection();

            return;
        }

        try {
            $extraction = $extractFirstNameAction->handle($replyText, $contact->name);
        } catch (Throwable $throwable) {
            $channelActivityLogger->error(
                $channel,
                'contact.data_collection_first_name_extraction_failed',
                'Не удалось распознать имя через Gemini.',
                [
                    'contact_id' => $contact->id,
                    'channel_id' => $channel->id,
                    'message_id' => $message->id,
                    'field' => Contact::DATA_COLLECTION_FIELD_FIRST_NAME,
                    'error' => $throwable->getMessage(),
                ],
            );

            $this->sendReply(
                message: $message,
                channel: $channel,
                text: $this->fallbackErrorMessage(),
                messageKind: Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION,
                telegramBotApiService: $telegramBotApiService,
                maxBotApiService: $maxBotApiService,
                storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
                channelActivityLogger: $channelActivityLogger,
                activityEvent: 'contact.data_collection_fallback_sent',
                activityMessage: 'Отправлено безопасное сообщение после ошибки распознавания имени.',
            );

            return;
        }

        if (($extraction['decision'] ?? null) !== ExtractFirstNameAction::DECISION_ACCEPT) {
            $this->handleRetry(
                message: $message,
                channel: $channel,
                contact: $contact,
                text: $this->retryMessage(),
                telegramBotApiService: $telegramBotApiService,
                maxBotApiService: $maxBotApiService,
                storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
                channelActivityLogger: $channelActivityLogger,
            );

            return;
        }

        $firstName = (string) ($extraction['first_name'] ?? '');

        $contact->forceFill([
            'first_name' => $firstName,
        ])->save();

        $this->sendReply(
            message: $message,
            channel: $channel,
            text: $this->completionMessage(),
            messageKind: Message::KIND_OUTBOUND_DATA_COLLECTION_COMPLETION,
            telegramBotApiService: $telegramBotApiService,
            maxBotApiService: $maxBotApiService,
            storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
            channelActivityLogger: $channelActivityLogger,
            activityEvent: 'contact.data_collection_completion_sent',
            activityMessage: 'Финальное сообщение сбора профиля отправлено.',
        );

        $contact->completeDataCollection();

        $channelActivityLogger->info(
            $channel,
            'contact.data_collection_field_saved',
            'Ответ пользователя сохранён в профиль контакта.',
            [
                'contact_id' => $contact->id,
                'channel_id' => $channel->id,
                'message_id' => $message->id,
                'field' => Contact::DATA_COLLECTION_FIELD_FIRST_NAME,
                'attempts_count' => $contact->data_collection_attempts_count,
            ],
        );
    }

    protected function handleRetry(
        Message $message,
        Channel $channel,
        Contact $contact,
        string $text,
        TelegramBotApiService $telegramBotApiService,
        MaxBotApiService $maxBotApiService,
        StoreDataCollectionOutboundMessageAction $storeDataCollectionOutboundMessageAction,
        ChannelActivityLogger $channelActivityLogger,
    ): void {
        $attempts = $this->incrementAttempts($contact);

        if ($attempts >= $this->maxAttempts()) {
            $this->sendReply(
                message: $message,
                channel: $channel,
                text: $this->skipMessage(),
                messageKind: Message::KIND_OUTBOUND_DATA_COLLECTION_COMPLETION,
                telegramBotApiService: $telegramBotApiService,
                maxBotApiService: $maxBotApiService,
                storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
                channelActivityLogger: $channelActivityLogger,
                activityEvent: 'contact.data_collection_skipped_after_attempts',
                activityMessage: 'Шаг сбора имени завершён после превышения лимита попыток.',
            );

            $contact->completeDataCollection();

            return;
        }

        $this->sendReply(
            message: $message,
            channel: $channel,
            text: $text,
            messageKind: Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION,
            telegramBotApiService: $telegramBotApiService,
            maxBotApiService: $maxBotApiService,
            storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
            channelActivityLogger: $channelActivityLogger,
            activityEvent: 'contact.data_collection_retry_sent',
            activityMessage: 'Отправлено повторное сообщение сбора имени.',
        );
    }

    protected function incrementAttempts(Contact $contact): int
    {
        $attempts = (int) $contact->data_collection_attempts_count + 1;

        $contact->forceFill([
            'data_collection_attempts_count' => $attempts,
        ])->save();

        return $attempts;
    }

    protected function isLocalSkipCommand(string $text): bool
    {
        $normalized = mb_strtolower(trim($text));

        return in_array($normalized, (array) config('bots.data_collection.first_name.skip_commands', ['пропустить', 'skip']), true);
    }

    protected function retryMessage(): string
    {
        return (string) config(
            'bots.data_collection.first_name.retry_message',
            'Подскажите, пожалуйста, как к вам обращаться? Можно только имя.'
        );
    }

    protected function skipMessage(): string
    {
        return (string) config('bots.data_collection.first_name.skip_message', 'Хорошо, имя пока пропустим.');
    }

    protected function fallbackErrorMessage(): string
    {
        return (string) config(
            'bots.data_collection.first_name.fallback_error_message',
            'Не смогли распознать имя. Напишите, пожалуйста, только имя.'
        );
    }

    protected function completionMessage(): string
    {
        return (string) config('bots.data_collection.completion_message', 'Спасибо, имя сохранили.');
    }

    protected function maxAttempts(): int
    {
        return max(1, (int) config('bots.data_collection.first_name.max_attempts', 2));
    }

    protected function sendReply(
        Message $message,
        Channel $channel,
        string $text,
        string $messageKind,
        TelegramBotApiService $telegramBotApiService,
        MaxBotApiService $maxBotApiService,
        StoreDataCollectionOutboundMessageAction $storeDataCollectionOutboundMessageAction,
        ChannelActivityLogger $channelActivityLogger,
        string $activityEvent,
        string $activityMessage,
    ): void {
        try {
            $deliveryResult = match ($channel->platform) {
                Channel::PLATFORM_TELEGRAM => $telegramBotApiService->sendTextMessage(
                    $channel,
                    $message->external_chat_id,
                    $message->contactIdentity?->external_user_id,
                    $text,
                ),
                Channel::PLATFORM_MAX => $maxBotApiService->sendTextMessage(
                    $channel,
                    $message->external_chat_id,
                    $message->contactIdentity?->external_user_id,
                    $text,
                ),
                default => throw new InvalidArgumentException("Unsupported bot platform [{$channel->platform}]."),
            };

            $storeDataCollectionOutboundMessageAction->handle($message, $deliveryResult, $messageKind);

            $channel->markReplySent();

            $channelActivityLogger->info(
                $channel,
                $activityEvent,
                $activityMessage,
                [
                    'contact_id' => $message->contact_id,
                    'channel_id' => $channel->id,
                    'message_id' => $message->id,
                    'current_field' => $message->contact?->data_collection_current_field,
                ],
            );
        } catch (Throwable $throwable) {
            $channel->markError($throwable);

            $channelActivityLogger->error(
                $channel,
                'contact.data_collection_reply_failed',
                'Не удалось отправить сообщение сбора профиля.',
                [
                    'contact_id' => $message->contact_id,
                    'channel_id' => $channel->id,
                    'message_id' => $message->id,
                    'message_kind' => $messageKind,
                    'error' => $throwable->getMessage(),
                ],
            );

            Log::error('data collection reply failed', [
                'contact_id' => $message->contact_id,
                'channel_id' => $channel->id,
                'message_id' => $message->id,
                'message_kind' => $messageKind,
                'error' => $throwable->getMessage(),
            ]);

            throw $throwable;
        }
    }
}
