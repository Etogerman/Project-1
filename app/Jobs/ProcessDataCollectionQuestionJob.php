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

        $questionText = $this->resolveQuestionText($contact, $channel->platform);

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

    protected function resolveQuestionText(Contact $contact, string $platform): ?string
    {
        return match ($contact->data_collection_current_field) {
            Contact::DATA_COLLECTION_FIELD_FIRST_NAME => (string) config(
                'bots.data_collection.first_name.question',
                config('bots.data_collection.first_question', 'Как вас зовут?')
            ),
            Contact::DATA_COLLECTION_FIELD_RESIDENCE_CITY => (string) config(
                'bots.data_collection.residence_city.question',
                'В каком городе вы живёте?'
            ),
            Contact::DATA_COLLECTION_FIELD_COUNTRY => (string) config(
                'bots.data_collection.country.question',
                'В какой стране вы живёте?'
            ),
            Contact::DATA_COLLECTION_FIELD_CITY => (string) config(
                'bots.data_collection.city.question',
                'В каком городе вы живёте?'
            ),
            Contact::DATA_COLLECTION_FIELD_RUSSIAN_REGION_CONFIRM => $this->russianRegionConfirmQuestionText($contact),
            Contact::DATA_COLLECTION_FIELD_AGE_RANGE => (string) config(
                match ($platform) {
                    Channel::PLATFORM_TELEGRAM => 'bots.data_collection.age_range.telegram_question',
                    Channel::PLATFORM_MAX => 'bots.data_collection.age_range.max_question',
                    default => 'bots.data_collection.age_range.question',
                },
                match ($platform) {
                    Channel::PLATFORM_TELEGRAM, Channel::PLATFORM_MAX => 'Укажите ваш возраст:',
                    default => "Укажите ваш возраст:\n1. До 18 лет\n2. 18 - 23 года\n3. 24 - 29 лет\n4. 30 - 39 лет\n5. Больше 40 лет",
                }
            ),
            default => null,
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function resolveTelegramReplyMarkup(Contact $contact): ?array
    {
        $buttons = match ($contact->data_collection_current_field) {
            Contact::DATA_COLLECTION_FIELD_AGE_RANGE => $this->telegramAgeRangeInlineKeyboard(),
            Contact::DATA_COLLECTION_FIELD_RUSSIAN_REGION_CONFIRM => $this->telegramRussianRegionConfirmInlineKeyboard($contact),
            default => null,
        };

        if ($buttons === null) {
            return null;
        }

        return [
            'inline_keyboard' => $buttons,
        ];
    }

    /**
     * @return array<int, array<int, array{text: string, callback_data: string}>>|null
     */
    protected function telegramAgeRangeInlineKeyboard(): ?array
    {
        $optionsByValue = [];

        foreach ((array) config('bots.data_collection.age_range.options', []) as $option) {
            if (! is_array($option) || ! filled($option['label'] ?? null) || ! filled($option['value'] ?? null)) {
                continue;
            }

            $optionsByValue[(string) $option['value']] = (string) $option['label'];
        }

        $rows = [
            ['under_18', '18_23'],
            ['24_29', '30_39'],
            ['over_40'],
        ];

        $keyboard = [];

        foreach ($rows as $rowValues) {
            $row = [];

            foreach ($rowValues as $value) {
                $label = $optionsByValue[$value] ?? null;

                if (! filled($label)) {
                    continue;
                }

                $row[] = [
                    'text' => $label,
                    'callback_data' => 'age_range:'.$value,
                ];
            }

            if ($row !== []) {
                $keyboard[] = $row;
            }
        }

        return $keyboard !== [] ? $keyboard : null;
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    protected function resolveMaxAttachments(Contact $contact): ?array
    {
        return match ($contact->data_collection_current_field) {
            Contact::DATA_COLLECTION_FIELD_AGE_RANGE => $this->maxAgeRangeAttachments(),
            Contact::DATA_COLLECTION_FIELD_RUSSIAN_REGION_CONFIRM => $this->maxRussianRegionConfirmAttachments($contact),
            default => null,
        };
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    protected function maxAgeRangeAttachments(): ?array
    {
        $optionsByValue = [];

        foreach ((array) config('bots.data_collection.age_range.options', []) as $option) {
            if (! is_array($option) || ! filled($option['label'] ?? null) || ! filled($option['value'] ?? null)) {
                continue;
            }

            $optionsByValue[(string) $option['value']] = (string) $option['label'];
        }

        $rows = [
            ['under_18', '18_23'],
            ['24_29', '30_39'],
            ['over_40'],
        ];

        $buttons = [];

        foreach ($rows as $rowValues) {
            $row = [];

            foreach ($rowValues as $value) {
                $label = $optionsByValue[$value] ?? null;

                if (! filled($label)) {
                    continue;
                }

                $row[] = [
                    'type' => 'message',
                    'text' => $label,
                ];
            }

            if ($row !== []) {
                $buttons[] = $row;
            }
        }

        if ($buttons === []) {
            return null;
        }

        return [[
            'type' => 'inline_keyboard',
            'payload' => [
                'buttons' => $buttons,
            ],
        ]];
    }

    protected function russianRegionConfirmQuestionText(Contact $contact): ?string
    {
        $candidates = $this->russianRegionCandidates($contact);

        if ($candidates === []) {
            return null;
        }

        $lines = [(string) config('bots.data_collection.russian_region_confirm.question', 'Уточните ваш регион:')];

        foreach ($candidates as $index => $candidate) {
            $lines[] = sprintf('%d. %s', $index + 1, $candidate);
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<int, array<int, array{text: string, callback_data: string}>>|null
     */
    protected function telegramRussianRegionConfirmInlineKeyboard(Contact $contact): ?array
    {
        $candidates = $this->russianRegionCandidates($contact);

        if ($candidates === []) {
            return null;
        }

        $keyboard = [];

        foreach (array_chunk(array_values($candidates), 2, true) as $chunk) {
            $row = [];

            foreach ($chunk as $index => $candidate) {
                $position = array_search($candidate, $candidates, true);

                if ($position === false) {
                    continue;
                }

                $row[] = [
                    'text' => $candidate,
                    'callback_data' => 'russian_region_confirm:'.($position + 1),
                ];
            }

            if ($row !== []) {
                $keyboard[] = $row;
            }
        }

        $keyboard[] = [[
            'text' => (string) config('bots.data_collection.russian_region_confirm.skip_button_label', 'Пропустить'),
            'callback_data' => 'russian_region_confirm:skip',
        ]];

        return $keyboard;
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    protected function maxRussianRegionConfirmAttachments(Contact $contact): ?array
    {
        $candidates = $this->russianRegionCandidates($contact);

        if ($candidates === []) {
            return null;
        }

        $buttons = [];

        foreach (array_chunk(array_values($candidates), 2) as $chunk) {
            $row = [];

            foreach ($chunk as $candidate) {
                $row[] = [
                    'type' => 'message',
                    'text' => $candidate,
                ];
            }

            if ($row !== []) {
                $buttons[] = $row;
            }
        }

        $buttons[] = [[
            'type' => 'message',
            'text' => (string) config('bots.data_collection.russian_region_confirm.skip_button_label', 'Пропустить'),
        ]];

        return [[
            'type' => 'inline_keyboard',
            'payload' => [
                'buttons' => $buttons,
            ],
        ]];
    }

    /**
     * @return list<string>
     */
    protected function russianRegionCandidates(Contact $contact): array
    {
        $candidates = $contact->pending_region_candidates;

        if (! is_array($candidates)) {
            return [];
        }

        $normalized = [];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $trimmed = trim($candidate);

            if ($trimmed === '' || in_array($trimmed, $normalized, true)) {
                continue;
            }

            $normalized[] = $trimmed;
        }

        return $normalized;
    }
}
