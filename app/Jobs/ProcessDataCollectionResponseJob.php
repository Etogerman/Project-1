<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
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

        $firstName = trim((string) ($message->text ?? ''));

        if ($firstName === '') {
            $this->sendReply(
                message: $message,
                channel: $channel,
                text: (string) config('bots.data_collection.first_question', 'Как вас зовут?'),
                messageKind: Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION,
                telegramBotApiService: $telegramBotApiService,
                maxBotApiService: $maxBotApiService,
                storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
                channelActivityLogger: $channelActivityLogger,
                activityEvent: 'contact.data_collection_question_repeated',
                activityMessage: 'Вопрос сбора профиля повторно отправлен: ожидалось имя.',
            );

            return;
        }

        $contact->forceFill([
            'first_name' => $firstName,
        ])->save();

        $channelActivityLogger->info(
            $channel,
            'contact.data_collection_field_saved',
            'Ответ пользователя сохранён в профиль контакта.',
            [
                'contact_id' => $contact->id,
                'channel_id' => $channel->id,
                'message_id' => $message->id,
                'field' => Contact::DATA_COLLECTION_FIELD_FIRST_NAME,
            ],
        );

        $this->sendReply(
            message: $message,
            channel: $channel,
            text: (string) config('bots.data_collection.completion_message', 'Спасибо, имя сохранили.'),
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
            'contact.data_collection_completed',
            'Сбор профиля завершён.',
            [
                'contact_id' => $contact->id,
                'channel_id' => $channel->id,
                'message_id' => $message->id,
                'field' => Contact::DATA_COLLECTION_FIELD_FIRST_NAME,
            ],
        );
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
