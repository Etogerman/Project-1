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

class ProcessDataCollectionQuestionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public int $sourceMessageId, public bool $forceSend = false) {}

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
            (new WithoutOverlapping("data-collection-question:message:{$this->sourceMessageId}"))->expireAfter(180),
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
            ->find($this->sourceMessageId);

        if (! $message instanceof Message) {
            return;
        }

        $channel = $message->channel;
        $contact = $message->contact;

        if (! $channel instanceof Channel || ! $channel->is_active || ! $contact instanceof Contact) {
            return;
        }

        if (! $contact->isInDataCollection()) {
            return;
        }

        if (! $this->forceSend && $this->questionAlreadyExists($message)) {
            return;
        }

        $questionText = $this->resolveQuestionText($contact);

        if ($questionText === null) {
            $channelActivityLogger->error(
                $channel,
                'contact.data_collection_question_unknown_field',
                'Не удалось определить текст вопроса для текущего шага сбора профиля.',
                [
                    'contact_id' => $contact->id,
                    'channel_id' => $channel->id,
                    'message_id' => $message->id,
                    'current_field' => $contact->data_collection_current_field,
                ],
            );

            return;
        }

        try {
            $deliveryResult = match ($channel->platform) {
                Channel::PLATFORM_TELEGRAM => $telegramBotApiService->sendTextMessage(
                    $channel,
                    $message->external_chat_id,
                    $message->contactIdentity?->external_user_id,
                    $questionText,
                    $this->resolveTelegramReplyMarkup($contact),
                ),
                Channel::PLATFORM_MAX => $maxBotApiService->sendTextMessage(
                    $channel,
                    $message->external_chat_id,
                    $message->contactIdentity?->external_user_id,
                    $questionText,
                    $this->resolveMaxAttachments($contact),
                ),
                default => throw new InvalidArgumentException("Unsupported bot platform [{$channel->platform}]."),
            };

            $storeDataCollectionOutboundMessageAction->handle(
                $message,
                $deliveryResult,
                Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION,
            );

            $channel->markReplySent();

            $channelActivityLogger->info(
                $channel,
                'contact.data_collection_question_sent',
                'Отправлен вопрос сбора профиля.',
                [
                    'contact_id' => $contact->id,
                    'channel_id' => $channel->id,
                    'message_id' => $message->id,
                    'current_field' => $contact->data_collection_current_field,
                ],
            );
        } catch (Throwable $throwable) {
            $channel->markError($throwable);

            $channelActivityLogger->error(
                $channel,
                'contact.data_collection_question_failed',
                'Не удалось отправить вопрос сбора профиля.',
                [
                    'contact_id' => $contact->id,
                    'channel_id' => $channel->id,
                    'message_id' => $message->id,
                    'current_field' => $contact->data_collection_current_field,
                    'error' => $throwable->getMessage(),
                ],
            );

            Log::error('data collection question failed', [
                'contact_id' => $contact->id,
                'channel_id' => $channel->id,
                'message_id' => $message->id,
                'error' => $throwable->getMessage(),
            ]);

            throw $throwable;
        }
    }

    protected function questionAlreadyExists(Message $message): bool
    {
        return $message->replies()
            ->where('message_kind', Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION)
            ->exists();
    }

    protected function resolveQuestionText(Contact $contact): ?string
    {
        return match ($contact->data_collection_current_field) {
            Contact::DATA_COLLECTION_FIELD_FIRST_NAME => (string) config(
                'bots.data_collection.first_name.question',
                config('bots.data_collection.first_question', 'Как вас зовут?')
            ),
            Contact::DATA_COLLECTION_FIELD_COUNTRY => (string) config(
                'bots.data_collection.country.question',
                'В какой стране вы находитесь?'
            ),
            Contact::DATA_COLLECTION_FIELD_CITY => (string) config(
                'bots.data_collection.city.question',
                'В каком городе вы находитесь?'
            ),
            Contact::DATA_COLLECTION_FIELD_AGE_RANGE => (string) config(
                'bots.data_collection.age_range.question',
                "Укажите ваш возраст:\n1. Еще нет 18 лет\n2. 18 - 23 года\n3. 24 - 29 лет\n4. 30 - 39 лет\n5. Больше 40 лет"
            ),
            default => null,
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function resolveTelegramReplyMarkup(Contact $contact): ?array
    {
        if ($contact->data_collection_current_field !== Contact::DATA_COLLECTION_FIELD_AGE_RANGE) {
            return null;
        }

        $buttons = [];

        foreach ((array) config('bots.data_collection.age_range.options', []) as $option) {
            if (! is_array($option) || ! filled($option['label'] ?? null)) {
                continue;
            }

            $buttons[] = [[
                'text' => (string) $option['label'],
            ]];
        }

        if ($buttons === []) {
            return null;
        }

        return [
            'keyboard' => $buttons,
            'resize_keyboard' => true,
            'one_time_keyboard' => true,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    protected function resolveMaxAttachments(Contact $contact): ?array
    {
        return null;
    }
}
