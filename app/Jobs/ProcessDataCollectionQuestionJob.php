<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\ChannelActivityLog;
use App\Models\Contact;
use App\Models\Dialog;
use App\Models\Message;
use App\Services\Bots\ChannelActivityLogger;
use App\Services\Bots\SendBotDialogTextAction;
use App\Services\Bots\StoreDataCollectionOutboundMessageAction;
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

class ProcessDataCollectionQuestionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    protected const RUSSIAN_REGION_CONFIRM_MODE_CANDIDATE_BUTTONS = 'candidate_buttons';

    protected const RUSSIAN_REGION_CONFIRM_MODE_FREE_TEXT = 'free_text_region';

    public int $tries = 3;

    public function __construct(
        public int $sourceMessageId,
        public bool $forceSend = false,
        public ?int $contactId = null,
        public ?string $expectedField = null,
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
        $overlapKey = $this->contactId !== null
            ? "data-collection-question:contact:{$this->contactId}"
            : "data-collection-question:message:{$this->sourceMessageId}";

        return [
            (new WithoutOverlapping($overlapKey))->expireAfter(180),
        ];
    }

    public function handle(
        SendBotDialogTextAction $sendBotDialogTextAction,
        StoreDataCollectionOutboundMessageAction $storeDataCollectionOutboundMessageAction,
        ChannelActivityLogger $channelActivityLogger,
        ResolveDialogRouteSourceAction $resolveDialogRouteSourceAction,
        DialogAutomationGate $dialogAutomationGate,
    ): void {
        if (! (bool) config('bots.data_collection.enabled', true)) {
            return;
        }

        $message = Message::query()
            ->with(['channel', 'contact', 'contactIdentity', 'dialog.channel', 'dialog.contact', 'dialog.currentContactIdentity', 'dialog.dialogStage'])
            ->find($this->sourceMessageId);

        if (! $message instanceof Message) {
            return;
        }

        if (! $dialogAutomationGate->acceptsMessage($message)) {
            return;
        }

        $sourceChannel = $message->channel;
        $routeDialog = $resolveDialogRouteSourceAction->forMessage($message);
        $fallbackUsed = false;

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

        if (! $channel instanceof Channel || ! $channel->is_active || ! $contact instanceof Contact) {
            return;
        }

        $currentField = $contact->data_collection_current_field;

        if (! $contact->isInDataCollection()) {
            return;
        }

        if ($this->expectedField !== null && $currentField !== $this->expectedField) {
            return;
        }

        $this->maybeHydrateCurrentFieldStartedAt($contact, $currentField, $message);

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

        if (! $this->forceSend && filled($currentField)) {
            if ($contact->data_collection_last_prompted_field === $currentField) {
                return;
            }

            if (
                ! filled($contact->data_collection_last_prompted_field)
                && $this->contactAlreadyHasQuestionForCurrentField($contact, $currentField, $questionText)
            ) {
                return;
            }
        }

        if (! $this->forceSend && $this->questionAlreadyExists($message)) {
            return;
        }

        if (! $routeDialog) {
            $channelActivityLogger->error(
                $channel,
                'contact.data_collection_question_route_missing',
                'Не найден route context диалога для отправки вопроса сбора профиля.',
                [
                    'contact_id' => $contact->id,
                    'channel_id' => $channel->id,
                    'message_id' => $message->id,
                    'current_field' => $contact->data_collection_current_field,
                ],
            );

            return;
        }

        if ($fallbackUsed) {
            $channelActivityLogger->warning(
                $channel,
                'contact.dialog_route_fallback_used',
                'Для отправки вопроса сбора профиля использован fallback route source через сообщение.',
                [
                    'contact_id' => $contact->id,
                    'channel_id' => $channel->id,
                    'message_id' => $message->id,
                    'dialog_id' => $routeDialog->id,
                    'current_field' => $contact->data_collection_current_field,
                ],
            );
        }

        try {
            $sendResult = $sendBotDialogTextAction->handleDialog(
                $routeDialog,
                $questionText,
                $this->resolveTelegramReplyMarkup($contact),
                $this->resolveMaxAttachments($contact),
            );

            if (! $sendResult->wasSent() || $sendResult->deliveryResult === null) {
                $channelActivityLogger->info(
                    $channel,
                    'contact.data_collection_question_skipped_dialog_not_sendable',
                    'Вопрос сбора профиля не отправлен: диалог сейчас недоступен для отправки.',
                    [
                        'contact_id' => $contact->id,
                        'channel_id' => $channel->id,
                        'message_id' => $message->id,
                        'dialog_id' => $routeDialog->id,
                        'current_field' => $contact->data_collection_current_field,
                        'route_status_code' => $sendResult->routeStatus->code,
                        'blocked_reason' => $sendResult->routeStatus->blockedReason,
                    ],
                );

                return;
            }

            $deliveryResult = $sendResult->deliveryResult;

            $storeDataCollectionOutboundMessageAction->handle(
                $message,
                $deliveryResult,
                Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION,
                $sendResult->dialog ?? $routeDialog,
                $currentField,
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
                    'dialog_id' => $routeDialog->id,
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
                    'dialog_id' => $routeDialog->id,
                    'current_field' => $contact->data_collection_current_field,
                    'error' => $throwable->getMessage(),
                ],
            );

            Log::error('data collection question failed', [
                'contact_id' => $contact->id,
                'channel_id' => $channel->id,
                'message_id' => $message->id,
                'dialog_id' => $routeDialog->id,
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

    protected function contactAlreadyHasQuestionForCurrentField(Contact $contact, string $currentField, string $questionText): bool
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

    protected function maybeHydrateCurrentFieldStartedAt(Contact $contact, ?string $currentField, Message $message): void
    {
        if (! filled($currentField) || $contact->data_collection_current_field_started_at !== null) {
            return;
        }

        $boundary = $this->resolveCurrentFieldStartedAtBoundary($contact, $currentField, $message);

        if ($boundary === null) {
            return;
        }

        $contact->forceFill([
            'data_collection_current_field_started_at' => $boundary,
        ])->save();
    }

    protected function resolveCurrentFieldStartedAtBoundary(Contact $contact, string $currentField, Message $message)
    {
        $loggedQuestionAt = $this->resolveLoggedQuestionAtForCurrentField($contact, $currentField);

        if ($loggedQuestionAt !== null) {
            return $loggedQuestionAt;
        }

        if ($currentField !== Contact::DATA_COLLECTION_FIELD_CITY) {
            return null;
        }

        if ($message->received_at !== null && $contact->data_collection_started_at !== null) {
            return $message->received_at->lt($contact->data_collection_started_at)
                ? $contact->data_collection_started_at
                : $message->received_at;
        }

        return $message->received_at ?? $contact->data_collection_started_at;
    }

    protected function resolveLoggedQuestionAtForCurrentField(Contact $contact, string $currentField)
    {
        $query = ChannelActivityLog::query()
            ->where('event', 'contact.data_collection_question_sent')
            ->where('context->contact_id', $contact->id)
            ->where('context->current_field', $currentField);

        $this->applyCreatedAtBoundary($query, $contact->data_collection_started_at);

        return $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->value('created_at');
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
        return match ($this->russianRegionConfirmMode($contact)) {
            self::RUSSIAN_REGION_CONFIRM_MODE_CANDIDATE_BUTTONS => (string) config(
                'bots.data_collection.russian_region_confirm.question_candidate_buttons',
                config('bots.data_collection.russian_region_confirm.question', 'Уточните, пожалуйста, ваш регион проживания.')
            ),
            self::RUSSIAN_REGION_CONFIRM_MODE_FREE_TEXT => (string) config(
                'bots.data_collection.russian_region_confirm.question_free_text',
                'Уточните, пожалуйста, регион проживания. В какой области, крае или республике находится ваш город?'
            ),
            default => null,
        };
    }

    /**
     * @return array<int, array<int, array{text: string, callback_data: string}>>|null
     */
    protected function telegramRussianRegionConfirmInlineKeyboard(Contact $contact): ?array
    {
        if ($this->russianRegionConfirmMode($contact) !== self::RUSSIAN_REGION_CONFIRM_MODE_CANDIDATE_BUTTONS) {
            return null;
        }

        $candidates = $this->russianRegionCandidates($contact);

        $keyboard = [];

        foreach ($candidates as $index => $candidate) {
            $keyboard[] = [[
                'text' => $candidate,
                'callback_data' => 'russian_region_confirm:'.($index + 1),
            ]];
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
        if ($this->russianRegionConfirmMode($contact) !== self::RUSSIAN_REGION_CONFIRM_MODE_CANDIDATE_BUTTONS) {
            return null;
        }

        $candidates = $this->russianRegionCandidates($contact);

        $buttons = [];

        foreach ($candidates as $candidate) {
            $buttons[] = [[
                'type' => 'message',
                'text' => $candidate,
            ]];
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
            $key = $this->normalizeComparableText($trimmed);

            if ($key === '' || array_key_exists($key, $normalized)) {
                continue;
            }

            $normalized[$key] = $trimmed;
        }

        $values = array_values($normalized);

        usort($values, fn (string $left, string $right): int => strnatcasecmp(
            $this->normalizeComparableText($left),
            $this->normalizeComparableText($right),
        ));

        return $values;
    }

    protected function russianRegionConfirmMode(Contact $contact): ?string
    {
        $candidateCount = count($this->russianRegionCandidates($contact));

        if ($candidateCount >= 2 && $candidateCount <= 4) {
            return self::RUSSIAN_REGION_CONFIRM_MODE_CANDIDATE_BUTTONS;
        }

        if ($candidateCount >= 5) {
            return self::RUSSIAN_REGION_CONFIRM_MODE_FREE_TEXT;
        }

        return null;
    }

    protected function normalizeComparableText(string $value): string
    {
        $normalized = mb_strtolower(trim($value));
        $normalized = str_replace('ё', 'е', $normalized);
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }
}
