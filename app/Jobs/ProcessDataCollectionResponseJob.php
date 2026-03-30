<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Services\DataCollection\ExtractCityAction;
use App\Services\DataCollection\ExtractCountryAction;
use App\Services\DataCollection\ExtractFirstNameAction;
use App\Services\DataCollection\ExtractResidenceCityAction;
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
        ExtractResidenceCityAction $extractResidenceCityAction,
        ExtractCountryAction $extractCountryAction,
        ExtractCityAction $extractCityAction,
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

        if (! $contact->isInDataCollection()) {
            return;
        }

        $replyText = trim((string) ($message->text ?? ''));
        $currentField = $contact->data_collection_current_field;

        if ($currentField === Contact::DATA_COLLECTION_FIELD_CITY && ! filled($contact->country)) {
            $this->moveToCountryStep(
                message: $message,
                channel: $channel,
                contact: $contact,
                telegramBotApiService: $telegramBotApiService,
                maxBotApiService: $maxBotApiService,
                storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
                channelActivityLogger: $channelActivityLogger,
            );

            return;
        }

        if ($replyText === '') {
            $this->handleBlankReply(
                message: $message,
                channel: $channel,
                contact: $contact,
                currentField: $currentField,
                telegramBotApiService: $telegramBotApiService,
                maxBotApiService: $maxBotApiService,
                storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
                channelActivityLogger: $channelActivityLogger,
            );
            return;
        }

        if ($this->isLocalSkipCommand($replyText, $currentField)) {
            $this->handleLocalSkip(
                message: $message,
                channel: $channel,
                contact: $contact,
                currentField: $currentField,
                telegramBotApiService: $telegramBotApiService,
                maxBotApiService: $maxBotApiService,
                storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
                channelActivityLogger: $channelActivityLogger,
            );
            return;
        }

        match ($currentField) {
            Contact::DATA_COLLECTION_FIELD_FIRST_NAME => $this->handleFirstNameReply(
                message: $message,
                channel: $channel,
                contact: $contact,
                replyText: $replyText,
                telegramBotApiService: $telegramBotApiService,
                maxBotApiService: $maxBotApiService,
                storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
                channelActivityLogger: $channelActivityLogger,
                extractFirstNameAction: $extractFirstNameAction,
            ),
            Contact::DATA_COLLECTION_FIELD_RESIDENCE_CITY => $this->handleResidenceCityReply(
                message: $message,
                channel: $channel,
                contact: $contact,
                replyText: $replyText,
                telegramBotApiService: $telegramBotApiService,
                maxBotApiService: $maxBotApiService,
                storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
                channelActivityLogger: $channelActivityLogger,
                extractResidenceCityAction: $extractResidenceCityAction,
            ),
            Contact::DATA_COLLECTION_FIELD_COUNTRY => $this->handleCountryReply(
                message: $message,
                channel: $channel,
                contact: $contact,
                replyText: $replyText,
                telegramBotApiService: $telegramBotApiService,
                maxBotApiService: $maxBotApiService,
                storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
                channelActivityLogger: $channelActivityLogger,
                extractCountryAction: $extractCountryAction,
                extractCityAction: $extractCityAction,
            ),
            Contact::DATA_COLLECTION_FIELD_CITY => $this->handleCityReply(
                message: $message,
                channel: $channel,
                contact: $contact,
                replyText: $replyText,
                telegramBotApiService: $telegramBotApiService,
                maxBotApiService: $maxBotApiService,
                storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
                channelActivityLogger: $channelActivityLogger,
                extractCityAction: $extractCityAction,
            ),
            Contact::DATA_COLLECTION_FIELD_AGE_RANGE => $this->handleAgeRangeReply(
                message: $message,
                channel: $channel,
                contact: $contact,
                replyText: $replyText,
                telegramBotApiService: $telegramBotApiService,
                maxBotApiService: $maxBotApiService,
                storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
                channelActivityLogger: $channelActivityLogger,
            ),
            default => null,
        };
    }

    protected function handleFirstNameReply(
        Message $message,
        Channel $channel,
        Contact $contact,
        string $replyText,
        TelegramBotApiService $telegramBotApiService,
        MaxBotApiService $maxBotApiService,
        StoreDataCollectionOutboundMessageAction $storeDataCollectionOutboundMessageAction,
        ChannelActivityLogger $channelActivityLogger,
        ExtractFirstNameAction $extractFirstNameAction,
    ): void {
        try {
            $extraction = $extractFirstNameAction->handle($replyText, $contact->name);
        } catch (Throwable $throwable) {
            Log::warning('contact.first_name_extraction_exception', [
                'contact_id' => $contact->id,
                'channel_id' => $channel->id,
                'message_id' => $message->id,
                'reply_preview' => $this->replyPreview($replyText),
                'exception_class' => $throwable::class,
                'error' => $throwable->getMessage(),
            ]);

            $this->handleExtractionError(
                message: $message,
                channel: $channel,
                contact: $contact,
                field: Contact::DATA_COLLECTION_FIELD_FIRST_NAME,
                errorMessage: 'Не удалось распознать имя через Gemini.',
                fallbackMessage: $this->fallbackErrorMessage(Contact::DATA_COLLECTION_FIELD_FIRST_NAME),
                telegramBotApiService: $telegramBotApiService,
                maxBotApiService: $maxBotApiService,
                storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
                channelActivityLogger: $channelActivityLogger,
                throwable: $throwable,
            );

            return;
        }

        if (($extraction['decision'] ?? null) !== ExtractFirstNameAction::DECISION_ACCEPT) {
            $this->handleRetry(
                message: $message,
                channel: $channel,
                contact: $contact,
                currentField: Contact::DATA_COLLECTION_FIELD_FIRST_NAME,
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

        $this->logFieldSaved($channel, $contact, $message, Contact::DATA_COLLECTION_FIELD_FIRST_NAME);

        $this->moveToResidenceCityStep(
            message: $message,
            channel: $channel,
            contact: $contact,
            telegramBotApiService: $telegramBotApiService,
            maxBotApiService: $maxBotApiService,
            storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
            channelActivityLogger: $channelActivityLogger,
        );
    }

    protected function handleResidenceCityReply(
        Message $message,
        Channel $channel,
        Contact $contact,
        string $replyText,
        TelegramBotApiService $telegramBotApiService,
        MaxBotApiService $maxBotApiService,
        StoreDataCollectionOutboundMessageAction $storeDataCollectionOutboundMessageAction,
        ChannelActivityLogger $channelActivityLogger,
        ExtractResidenceCityAction $extractResidenceCityAction,
    ): void {
        try {
            $extraction = $extractResidenceCityAction->handle($replyText);
        } catch (Throwable $throwable) {
            $this->handleExtractionError(
                message: $message,
                channel: $channel,
                contact: $contact,
                field: Contact::DATA_COLLECTION_FIELD_RESIDENCE_CITY,
                errorMessage: 'Не удалось распознать город проживания через Gemini.',
                fallbackMessage: $this->fallbackErrorMessage(Contact::DATA_COLLECTION_FIELD_RESIDENCE_CITY),
                telegramBotApiService: $telegramBotApiService,
                maxBotApiService: $maxBotApiService,
                storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
                channelActivityLogger: $channelActivityLogger,
                throwable: $throwable,
            );

            return;
        }

        if (($extraction['decision'] ?? null) !== ExtractResidenceCityAction::DECISION_ACCEPT) {
            $this->handleRetry(
                message: $message,
                channel: $channel,
                contact: $contact,
                currentField: Contact::DATA_COLLECTION_FIELD_RESIDENCE_CITY,
                telegramBotApiService: $telegramBotApiService,
                maxBotApiService: $maxBotApiService,
                storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
                channelActivityLogger: $channelActivityLogger,
            );

            return;
        }

        $city = (string) ($extraction['city'] ?? '');
        $country = $this->nullableString($extraction['country'] ?? null);
        $countryConfidence = $this->nullableString($extraction['country_confidence'] ?? null);

        $attributes = ['city' => $city];

        if ($countryConfidence === ExtractResidenceCityAction::COUNTRY_CONFIDENCE_HIGH && filled($country)) {
            $attributes['country'] = $country;
        }

        $contact->forceFill($attributes)->save();

        $this->logFieldSaved($channel, $contact, $message, Contact::DATA_COLLECTION_FIELD_RESIDENCE_CITY);

        if ($countryConfidence === ExtractResidenceCityAction::COUNTRY_CONFIDENCE_HIGH && filled($country)) {
            $this->moveToAgeRangeStep(
                message: $message,
                channel: $channel,
                contact: $contact,
                telegramBotApiService: $telegramBotApiService,
                maxBotApiService: $maxBotApiService,
                storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
                channelActivityLogger: $channelActivityLogger,
            );

            return;
        }

        $this->moveToCountryStep(
            message: $message,
            channel: $channel,
            contact: $contact,
            telegramBotApiService: $telegramBotApiService,
            maxBotApiService: $maxBotApiService,
            storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
            channelActivityLogger: $channelActivityLogger,
        );
    }

    protected function replyPreview(string $value): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($value));

        if (! is_string($normalized) || $normalized === '') {
            return '';
        }

        return mb_substr($normalized, 0, 120);
    }

    protected function handleCountryReply(
        Message $message,
        Channel $channel,
        Contact $contact,
        string $replyText,
        TelegramBotApiService $telegramBotApiService,
        MaxBotApiService $maxBotApiService,
        StoreDataCollectionOutboundMessageAction $storeDataCollectionOutboundMessageAction,
        ChannelActivityLogger $channelActivityLogger,
        ExtractCountryAction $extractCountryAction,
        ExtractCityAction $extractCityAction,
    ): void {
        try {
            $extraction = $extractCountryAction->handle($replyText);
        } catch (Throwable $throwable) {
            $this->handleExtractionError(
                message: $message,
                channel: $channel,
                contact: $contact,
                field: Contact::DATA_COLLECTION_FIELD_COUNTRY,
                errorMessage: 'Не удалось распознать страну через Gemini.',
                fallbackMessage: $this->fallbackErrorMessage(Contact::DATA_COLLECTION_FIELD_COUNTRY),
                telegramBotApiService: $telegramBotApiService,
                maxBotApiService: $maxBotApiService,
                storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
                channelActivityLogger: $channelActivityLogger,
                throwable: $throwable,
            );

            return;
        }

        if (($extraction['decision'] ?? null) !== ExtractCountryAction::DECISION_ACCEPT) {
            $this->handleRetry(
                message: $message,
                channel: $channel,
                contact: $contact,
                currentField: Contact::DATA_COLLECTION_FIELD_COUNTRY,
                telegramBotApiService: $telegramBotApiService,
                maxBotApiService: $maxBotApiService,
                storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
                channelActivityLogger: $channelActivityLogger,
            );

            return;
        }

        $country = (string) ($extraction['country'] ?? '');

        if (filled($contact->city)) {
            try {
                $cityMatchesCountry = $this->cityMatchesCountry($contact->city, $country, $extractCityAction);
            } catch (Throwable $throwable) {
                $this->handleExtractionError(
                    message: $message,
                    channel: $channel,
                    contact: $contact,
                    field: Contact::DATA_COLLECTION_FIELD_COUNTRY,
                    errorMessage: 'Не удалось проверить совместимость страны и города.',
                    fallbackMessage: $this->fallbackErrorMessage(Contact::DATA_COLLECTION_FIELD_COUNTRY),
                    telegramBotApiService: $telegramBotApiService,
                    maxBotApiService: $maxBotApiService,
                    storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
                    channelActivityLogger: $channelActivityLogger,
                    throwable: $throwable,
                );

                return;
            }

            if (! $cityMatchesCountry) {
                $this->handleCountryCityMismatch(
                    message: $message,
                    channel: $channel,
                    contact: $contact,
                    attemptedCountry: $country,
                    telegramBotApiService: $telegramBotApiService,
                    maxBotApiService: $maxBotApiService,
                    storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
                    channelActivityLogger: $channelActivityLogger,
                );

                return;
            }
        }

        $contact->forceFill([
            'country' => $country,
        ])->save();

        $this->logFieldSaved($channel, $contact, $message, Contact::DATA_COLLECTION_FIELD_COUNTRY);

        if (filled($contact->city)) {
            $this->moveToAgeRangeStep(
                message: $message,
                channel: $channel,
                contact: $contact,
                telegramBotApiService: $telegramBotApiService,
                maxBotApiService: $maxBotApiService,
                storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
                channelActivityLogger: $channelActivityLogger,
            );

            return;
        }

        $this->moveToCityStep(
            message: $message,
            channel: $channel,
            contact: $contact,
            telegramBotApiService: $telegramBotApiService,
            maxBotApiService: $maxBotApiService,
            storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
            channelActivityLogger: $channelActivityLogger,
        );
    }

    protected function handleCityReply(
        Message $message,
        Channel $channel,
        Contact $contact,
        string $replyText,
        TelegramBotApiService $telegramBotApiService,
        MaxBotApiService $maxBotApiService,
        StoreDataCollectionOutboundMessageAction $storeDataCollectionOutboundMessageAction,
        ChannelActivityLogger $channelActivityLogger,
        ExtractCityAction $extractCityAction,
    ): void {
        try {
            $extraction = $extractCityAction->handle($replyText, $contact->country);
        } catch (Throwable $throwable) {
            $this->handleExtractionError(
                message: $message,
                channel: $channel,
                contact: $contact,
                field: Contact::DATA_COLLECTION_FIELD_CITY,
                errorMessage: 'Не удалось распознать город через Gemini.',
                fallbackMessage: $this->fallbackErrorMessage(Contact::DATA_COLLECTION_FIELD_CITY),
                telegramBotApiService: $telegramBotApiService,
                maxBotApiService: $maxBotApiService,
                storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
                channelActivityLogger: $channelActivityLogger,
                throwable: $throwable,
            );

            return;
        }

        if (($extraction['decision'] ?? null) !== ExtractCityAction::DECISION_ACCEPT) {
            $this->handleRetry(
                message: $message,
                channel: $channel,
                contact: $contact,
                currentField: Contact::DATA_COLLECTION_FIELD_CITY,
                telegramBotApiService: $telegramBotApiService,
                maxBotApiService: $maxBotApiService,
                storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
                channelActivityLogger: $channelActivityLogger,
            );

            return;
        }

        $city = (string) ($extraction['city'] ?? '');

        $contact->forceFill([
            'city' => $city,
        ])->save();

        $this->logFieldSaved($channel, $contact, $message, Contact::DATA_COLLECTION_FIELD_CITY);

        $this->moveToAgeRangeStep(
            message: $message,
            channel: $channel,
            contact: $contact,
            telegramBotApiService: $telegramBotApiService,
            maxBotApiService: $maxBotApiService,
            storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
            channelActivityLogger: $channelActivityLogger,
        );
    }

    protected function handleAgeRangeReply(
        Message $message,
        Channel $channel,
        Contact $contact,
        string $replyText,
        TelegramBotApiService $telegramBotApiService,
        MaxBotApiService $maxBotApiService,
        StoreDataCollectionOutboundMessageAction $storeDataCollectionOutboundMessageAction,
        ChannelActivityLogger $channelActivityLogger,
    ): void {
        $ageRange = $this->resolveAgeRangeValue($replyText);

        if ($ageRange === null) {
            $this->handleRetry(
                message: $message,
                channel: $channel,
                contact: $contact,
                currentField: Contact::DATA_COLLECTION_FIELD_AGE_RANGE,
                telegramBotApiService: $telegramBotApiService,
                maxBotApiService: $maxBotApiService,
                storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
                channelActivityLogger: $channelActivityLogger,
            );

            return;
        }

        $contact->forceFill([
            'age_range' => $ageRange,
        ])->save();

        $this->logFieldSaved($channel, $contact, $message, Contact::DATA_COLLECTION_FIELD_AGE_RANGE);

        $this->sendCompletion(
            message: $message,
            channel: $channel,
            contact: $contact,
            telegramBotApiService: $telegramBotApiService,
            maxBotApiService: $maxBotApiService,
            storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
            channelActivityLogger: $channelActivityLogger,
        );
    }

    protected function handleBlankReply(
        Message $message,
        Channel $channel,
        Contact $contact,
        ?string $currentField,
        TelegramBotApiService $telegramBotApiService,
        MaxBotApiService $maxBotApiService,
        StoreDataCollectionOutboundMessageAction $storeDataCollectionOutboundMessageAction,
        ChannelActivityLogger $channelActivityLogger,
    ): void {
        $this->handleRetry(
            message: $message,
            channel: $channel,
            contact: $contact,
            currentField: $currentField,
            telegramBotApiService: $telegramBotApiService,
            maxBotApiService: $maxBotApiService,
            storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
            channelActivityLogger: $channelActivityLogger,
        );
    }

    protected function handleRetry(
        Message $message,
        Channel $channel,
        Contact $contact,
        ?string $currentField,
        TelegramBotApiService $telegramBotApiService,
        MaxBotApiService $maxBotApiService,
        StoreDataCollectionOutboundMessageAction $storeDataCollectionOutboundMessageAction,
        ChannelActivityLogger $channelActivityLogger,
    ): void {
        $currentField = $currentField ?? Contact::DATA_COLLECTION_FIELD_FIRST_NAME;
        $attempts = $this->incrementAttempts($contact);

        if ($attempts >= $this->maxAttempts($currentField)) {
            if ($currentField === Contact::DATA_COLLECTION_FIELD_FIRST_NAME) {
                $this->moveToResidenceCityStep(
                    message: $message,
                    channel: $channel,
                    contact: $contact,
                    telegramBotApiService: $telegramBotApiService,
                    maxBotApiService: $maxBotApiService,
                    storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
                    channelActivityLogger: $channelActivityLogger,
                );

                return;
            }

            if ($currentField === Contact::DATA_COLLECTION_FIELD_RESIDENCE_CITY) {
                $this->moveToAgeRangeStep(
                    message: $message,
                    channel: $channel,
                    contact: $contact,
                    telegramBotApiService: $telegramBotApiService,
                    maxBotApiService: $maxBotApiService,
                    storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
                    channelActivityLogger: $channelActivityLogger,
                );

                return;
            }

            if ($currentField === Contact::DATA_COLLECTION_FIELD_COUNTRY) {
                if (filled($contact->city)) {
                    $this->moveToAgeRangeStep(
                        message: $message,
                        channel: $channel,
                        contact: $contact,
                        telegramBotApiService: $telegramBotApiService,
                        maxBotApiService: $maxBotApiService,
                        storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
                        channelActivityLogger: $channelActivityLogger,
                    );

                    return;
                }

                $this->moveToCityStep(
                    message: $message,
                    channel: $channel,
                    contact: $contact,
                    telegramBotApiService: $telegramBotApiService,
                    maxBotApiService: $maxBotApiService,
                    storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
                    channelActivityLogger: $channelActivityLogger,
                );

                return;
            }

            if ($currentField === Contact::DATA_COLLECTION_FIELD_CITY) {
                $this->moveToAgeRangeStep(
                    message: $message,
                    channel: $channel,
                    contact: $contact,
                    telegramBotApiService: $telegramBotApiService,
                    maxBotApiService: $maxBotApiService,
                    storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
                    channelActivityLogger: $channelActivityLogger,
                );

                return;
            }

            $this->sendTerminalSkip(
                message: $message,
                channel: $channel,
                contact: $contact,
                field: $currentField,
                telegramBotApiService: $telegramBotApiService,
                maxBotApiService: $maxBotApiService,
                storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
                channelActivityLogger: $channelActivityLogger,
            );

            return;
        }

        $this->sendReply(
            message: $message,
            channel: $channel,
            text: $this->retryMessage($currentField),
            messageKind: Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION,
            telegramBotApiService: $telegramBotApiService,
            maxBotApiService: $maxBotApiService,
            storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
            channelActivityLogger: $channelActivityLogger,
            activityEvent: 'contact.data_collection_retry_sent',
            activityMessage: 'Отправлено повторное сообщение сбора профиля.',
            telegramReplyMarkup: $this->telegramReplyMarkupForField($currentField),
            maxAttachments: $this->maxAttachmentsForField($currentField),
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

    protected function isLocalSkipCommand(string $text, ?string $currentField): bool
    {
        $normalized = mb_strtolower(trim($text));

        return in_array($normalized, (array) $this->fieldConfig($currentField, 'skip_commands', ['пропустить', 'skip']), true);
    }

    protected function retryMessage(?string $field): string
    {
        return (string) $this->fieldConfig($field, 'retry_message', 'Подскажите, пожалуйста, как к вам обращаться? Можно только имя.');
    }

    protected function skipMessage(?string $field): string
    {
        return (string) $this->fieldConfig($field, 'skip_message', 'Хорошо, имя пока пропустим.');
    }

    protected function fallbackErrorMessage(?string $field): string
    {
        return (string) $this->fieldConfig($field, 'fallback_error_message', 'Не смогли распознать значение. Повторите ответ, пожалуйста.');
    }

    protected function completionMessage(): string
    {
        return (string) config('bots.data_collection.completion_message', 'Спасибо, данные сохранили.');
    }

    protected function maxAttempts(?string $field): int
    {
        return max(1, (int) $this->fieldConfig($field, 'max_attempts', 2));
    }

    protected function handleLocalSkip(
        Message $message,
        Channel $channel,
        Contact $contact,
        ?string $currentField,
        TelegramBotApiService $telegramBotApiService,
        MaxBotApiService $maxBotApiService,
        StoreDataCollectionOutboundMessageAction $storeDataCollectionOutboundMessageAction,
        ChannelActivityLogger $channelActivityLogger,
    ): void {
        if ($currentField === Contact::DATA_COLLECTION_FIELD_FIRST_NAME) {
            $this->moveToResidenceCityStep(
                message: $message,
                channel: $channel,
                contact: $contact,
                telegramBotApiService: $telegramBotApiService,
                maxBotApiService: $maxBotApiService,
                storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
                channelActivityLogger: $channelActivityLogger,
            );

            return;
        }

        if ($currentField === Contact::DATA_COLLECTION_FIELD_RESIDENCE_CITY) {
            $this->moveToAgeRangeStep(
                message: $message,
                channel: $channel,
                contact: $contact,
                telegramBotApiService: $telegramBotApiService,
                maxBotApiService: $maxBotApiService,
                storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
                channelActivityLogger: $channelActivityLogger,
            );

            return;
        }

        if ($currentField === Contact::DATA_COLLECTION_FIELD_COUNTRY) {
            if (filled($contact->city)) {
                $this->moveToAgeRangeStep(
                    message: $message,
                    channel: $channel,
                    contact: $contact,
                    telegramBotApiService: $telegramBotApiService,
                    maxBotApiService: $maxBotApiService,
                    storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
                    channelActivityLogger: $channelActivityLogger,
                );

                return;
            }

            $this->moveToCityStep(
                message: $message,
                channel: $channel,
                contact: $contact,
                telegramBotApiService: $telegramBotApiService,
                maxBotApiService: $maxBotApiService,
                storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
                channelActivityLogger: $channelActivityLogger,
            );

            return;
        }

        if ($currentField === Contact::DATA_COLLECTION_FIELD_CITY) {
            $this->moveToAgeRangeStep(
                message: $message,
                channel: $channel,
                contact: $contact,
                telegramBotApiService: $telegramBotApiService,
                maxBotApiService: $maxBotApiService,
                storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
                channelActivityLogger: $channelActivityLogger,
            );

            return;
        }

        $this->sendTerminalSkip(
            message: $message,
            channel: $channel,
            contact: $contact,
            field: $currentField,
            telegramBotApiService: $telegramBotApiService,
            maxBotApiService: $maxBotApiService,
            storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
            channelActivityLogger: $channelActivityLogger,
        );
    }

    protected function moveToCountryStep(
        Message $message,
        Channel $channel,
        Contact $contact,
        TelegramBotApiService $telegramBotApiService,
        MaxBotApiService $maxBotApiService,
        StoreDataCollectionOutboundMessageAction $storeDataCollectionOutboundMessageAction,
        ChannelActivityLogger $channelActivityLogger,
    ): void {
        if (filled($contact->country)) {
            if (filled($contact->city)) {
                $this->moveToAgeRangeStep(
                    message: $message,
                    channel: $channel,
                    contact: $contact,
                    telegramBotApiService: $telegramBotApiService,
                    maxBotApiService: $maxBotApiService,
                    storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
                    channelActivityLogger: $channelActivityLogger,
                );

                return;
            }

            $this->moveToCityStep(
                message: $message,
                channel: $channel,
                contact: $contact,
                telegramBotApiService: $telegramBotApiService,
                maxBotApiService: $maxBotApiService,
                storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
                channelActivityLogger: $channelActivityLogger,
            );

            return;
        }

        $contact->startDataCollection(Contact::DATA_COLLECTION_FIELD_COUNTRY);

        $this->sendReply(
            message: $message,
            channel: $channel,
            text: $this->questionText(Contact::DATA_COLLECTION_FIELD_COUNTRY),
            messageKind: Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION,
            telegramBotApiService: $telegramBotApiService,
            maxBotApiService: $maxBotApiService,
            storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
            channelActivityLogger: $channelActivityLogger,
            activityEvent: 'contact.data_collection_next_question_sent',
            activityMessage: 'Отправлен следующий вопрос сбора профиля.',
        );
    }

    protected function moveToResidenceCityStep(
        Message $message,
        Channel $channel,
        Contact $contact,
        TelegramBotApiService $telegramBotApiService,
        MaxBotApiService $maxBotApiService,
        StoreDataCollectionOutboundMessageAction $storeDataCollectionOutboundMessageAction,
        ChannelActivityLogger $channelActivityLogger,
    ): void {
        if (filled($contact->city)) {
            if (filled($contact->country)) {
                $this->moveToAgeRangeStep(
                    message: $message,
                    channel: $channel,
                    contact: $contact,
                    telegramBotApiService: $telegramBotApiService,
                    maxBotApiService: $maxBotApiService,
                    storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
                    channelActivityLogger: $channelActivityLogger,
                );

                return;
            }

            $this->moveToCountryStep(
                message: $message,
                channel: $channel,
                contact: $contact,
                telegramBotApiService: $telegramBotApiService,
                maxBotApiService: $maxBotApiService,
                storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
                channelActivityLogger: $channelActivityLogger,
            );

            return;
        }

        if (filled($contact->country)) {
            $this->moveToCityStep(
                message: $message,
                channel: $channel,
                contact: $contact,
                telegramBotApiService: $telegramBotApiService,
                maxBotApiService: $maxBotApiService,
                storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
                channelActivityLogger: $channelActivityLogger,
            );

            return;
        }

        $contact->startDataCollection(Contact::DATA_COLLECTION_FIELD_RESIDENCE_CITY);

        $this->sendReply(
            message: $message,
            channel: $channel,
            text: $this->questionText(Contact::DATA_COLLECTION_FIELD_RESIDENCE_CITY),
            messageKind: Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION,
            telegramBotApiService: $telegramBotApiService,
            maxBotApiService: $maxBotApiService,
            storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
            channelActivityLogger: $channelActivityLogger,
            activityEvent: 'contact.data_collection_next_question_sent',
            activityMessage: 'Отправлен следующий вопрос сбора профиля.',
        );
    }

    protected function moveToCityStep(
        Message $message,
        Channel $channel,
        Contact $contact,
        TelegramBotApiService $telegramBotApiService,
        MaxBotApiService $maxBotApiService,
        StoreDataCollectionOutboundMessageAction $storeDataCollectionOutboundMessageAction,
        ChannelActivityLogger $channelActivityLogger,
    ): void {
        if (filled($contact->city)) {
            $this->moveToAgeRangeStep(
                message: $message,
                channel: $channel,
                contact: $contact,
                telegramBotApiService: $telegramBotApiService,
                maxBotApiService: $maxBotApiService,
                storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
                channelActivityLogger: $channelActivityLogger,
            );

            return;
        }

        $contact->startDataCollection(Contact::DATA_COLLECTION_FIELD_CITY);

        $this->sendReply(
            message: $message,
            channel: $channel,
            text: $this->questionText(Contact::DATA_COLLECTION_FIELD_CITY),
            messageKind: Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION,
            telegramBotApiService: $telegramBotApiService,
            maxBotApiService: $maxBotApiService,
            storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
            channelActivityLogger: $channelActivityLogger,
            activityEvent: 'contact.data_collection_next_question_sent',
            activityMessage: 'Отправлен следующий вопрос сбора профиля.',
        );
    }

    protected function moveToAgeRangeStep(
        Message $message,
        Channel $channel,
        Contact $contact,
        TelegramBotApiService $telegramBotApiService,
        MaxBotApiService $maxBotApiService,
        StoreDataCollectionOutboundMessageAction $storeDataCollectionOutboundMessageAction,
        ChannelActivityLogger $channelActivityLogger,
    ): void {
        if (filled($contact->age_range)) {
            $this->sendCompletion(
                message: $message,
                channel: $channel,
                contact: $contact,
                telegramBotApiService: $telegramBotApiService,
                maxBotApiService: $maxBotApiService,
                storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
                channelActivityLogger: $channelActivityLogger,
            );

            return;
        }

        $contact->startDataCollection(Contact::DATA_COLLECTION_FIELD_AGE_RANGE);

        $this->sendReply(
            message: $message,
            channel: $channel,
            text: $this->questionText(Contact::DATA_COLLECTION_FIELD_AGE_RANGE),
            messageKind: Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION,
            telegramBotApiService: $telegramBotApiService,
            maxBotApiService: $maxBotApiService,
            storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
            channelActivityLogger: $channelActivityLogger,
            activityEvent: 'contact.data_collection_next_question_sent',
            activityMessage: 'Отправлен следующий вопрос сбора профиля.',
            telegramReplyMarkup: $this->telegramReplyMarkupForField(Contact::DATA_COLLECTION_FIELD_AGE_RANGE),
            maxAttachments: $this->maxAttachmentsForField(Contact::DATA_COLLECTION_FIELD_AGE_RANGE),
        );
    }

    protected function sendCompletion(
        Message $message,
        Channel $channel,
        Contact $contact,
        TelegramBotApiService $telegramBotApiService,
        MaxBotApiService $maxBotApiService,
        StoreDataCollectionOutboundMessageAction $storeDataCollectionOutboundMessageAction,
        ChannelActivityLogger $channelActivityLogger,
    ): void {
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
            telegramReplyMarkup: $this->telegramReplyMarkupForCompletion($contact),
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
            ],
        );
    }

    protected function sendTerminalSkip(
        Message $message,
        Channel $channel,
        Contact $contact,
        ?string $field,
        TelegramBotApiService $telegramBotApiService,
        MaxBotApiService $maxBotApiService,
        StoreDataCollectionOutboundMessageAction $storeDataCollectionOutboundMessageAction,
        ChannelActivityLogger $channelActivityLogger,
    ): void {
        $this->sendReply(
            message: $message,
            channel: $channel,
            text: $this->skipMessage($field),
            messageKind: Message::KIND_OUTBOUND_DATA_COLLECTION_COMPLETION,
            telegramBotApiService: $telegramBotApiService,
            maxBotApiService: $maxBotApiService,
            storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
            channelActivityLogger: $channelActivityLogger,
            activityEvent: 'contact.data_collection_skipped',
            activityMessage: 'Шаг сбора профиля пропущен.',
            telegramReplyMarkup: $this->telegramReplyMarkupForTerminalSkip($field),
        );

        $contact->completeDataCollection();

        $channelActivityLogger->info(
            $channel,
            'contact.data_collection_completed',
            'Сбор профиля завершён после пропуска шага.',
            [
                'contact_id' => $contact->id,
                'channel_id' => $channel->id,
                'message_id' => $message->id,
                'field' => $field,
            ],
        );
    }

    protected function handleExtractionError(
        Message $message,
        Channel $channel,
        Contact $contact,
        string $field,
        string $errorMessage,
        string $fallbackMessage,
        TelegramBotApiService $telegramBotApiService,
        MaxBotApiService $maxBotApiService,
        StoreDataCollectionOutboundMessageAction $storeDataCollectionOutboundMessageAction,
        ChannelActivityLogger $channelActivityLogger,
        Throwable $throwable,
    ): void {
        $channelActivityLogger->error(
            $channel,
            'contact.data_collection_extraction_failed',
            $errorMessage,
            [
                'contact_id' => $contact->id,
                'channel_id' => $channel->id,
                'message_id' => $message->id,
                'field' => $field,
                'error' => $throwable->getMessage(),
            ],
        );

        $this->sendReply(
            message: $message,
            channel: $channel,
            text: $fallbackMessage,
            messageKind: Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION,
            telegramBotApiService: $telegramBotApiService,
            maxBotApiService: $maxBotApiService,
            storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
            channelActivityLogger: $channelActivityLogger,
            activityEvent: 'contact.data_collection_fallback_sent',
            activityMessage: 'Отправлено безопасное сообщение после ошибки распознавания шага анкеты.',
        );
    }

    protected function logFieldSaved(Channel $channel, Contact $contact, Message $message, string $field): void
    {
        $channelActivityLogger = app(ChannelActivityLogger::class);

        $channelActivityLogger->info(
            $channel,
            'contact.data_collection_field_saved',
            'Ответ пользователя сохранён в профиль контакта.',
            [
                'contact_id' => $contact->id,
                'channel_id' => $channel->id,
                'message_id' => $message->id,
                'field' => $field,
                'attempts_count' => $contact->data_collection_attempts_count,
            ],
        );
    }

    /**
     * @param  mixed  $default
     */
    protected function fieldConfig(?string $field, string $key, mixed $default = null): mixed
    {
        if (! is_string($field) || $field === '') {
            return $default;
        }

        return config("bots.data_collection.{$field}.{$key}", $default);
    }

    protected function questionText(string $field): string
    {
        return match ($field) {
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
            Contact::DATA_COLLECTION_FIELD_AGE_RANGE => (string) config(
                'bots.data_collection.age_range.question',
                "Укажите ваш возраст:\n1. Еще нет 18 лет\n2. 18 - 23 года\n3. 24 - 29 лет\n4. 30 - 39 лет\n5. Больше 40 лет"
            ),
            default => '',
        };
    }

    protected function cityMatchesCountry(string $city, string $country, ExtractCityAction $extractCityAction): bool
    {
        $validation = $extractCityAction->handle($city, $country);

        return ($validation['decision'] ?? null) === ExtractCityAction::DECISION_ACCEPT;
    }

    protected function handleCountryCityMismatch(
        Message $message,
        Channel $channel,
        Contact $contact,
        string $attemptedCountry,
        TelegramBotApiService $telegramBotApiService,
        MaxBotApiService $maxBotApiService,
        StoreDataCollectionOutboundMessageAction $storeDataCollectionOutboundMessageAction,
        ChannelActivityLogger $channelActivityLogger,
    ): void {
        $attempts = $this->incrementAttempts($contact);

        if ($attempts >= $this->maxAttempts(Contact::DATA_COLLECTION_FIELD_COUNTRY)) {
            $this->moveToAgeRangeStep(
                message: $message,
                channel: $channel,
                contact: $contact,
                telegramBotApiService: $telegramBotApiService,
                maxBotApiService: $maxBotApiService,
                storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
                channelActivityLogger: $channelActivityLogger,
            );

            return;
        }

        $this->sendReply(
            message: $message,
            channel: $channel,
            text: $this->countryCityMismatchMessage($contact->city, $attemptedCountry),
            messageKind: Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION,
            telegramBotApiService: $telegramBotApiService,
            maxBotApiService: $maxBotApiService,
            storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
            channelActivityLogger: $channelActivityLogger,
            activityEvent: 'contact.data_collection_retry_sent',
            activityMessage: 'Отправлено сообщение о несоответствии страны и города.',
        );
    }

    protected function countryCityMismatchMessage(?string $city, string $country): string
    {
        $template = (string) config(
            'bots.data_collection.country.city_mismatch_message',
            'Похоже, город «{city}» не относится к стране «{country}». Подскажите, пожалуйста, страну, где вы живёте.'
        );

        return str_replace(
            ['{city}', '{country}'],
            [$city ?: 'указанный город', $country],
            $template,
        );
    }

    protected function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    protected function resolveAgeRangeValue(string $replyText): ?string
    {
        $normalizedReply = $this->normalizeAgeRangeInput($replyText);

        if ($normalizedReply === '') {
            return null;
        }

        $options = config('bots.data_collection.age_range.options', []);

        if (! is_array($options)) {
            return null;
        }

        foreach ($options as $option) {
            if (! is_array($option)) {
                continue;
            }

            $value = (string) ($option['value'] ?? '');

            if ($value === '') {
                continue;
            }

            $candidates = [
                $value,
                (string) ($option['label'] ?? ''),
            ];

            foreach ((array) ($option['aliases'] ?? []) as $alias) {
                if (is_scalar($alias)) {
                    $candidates[] = (string) $alias;
                }
            }

            foreach ($candidates as $candidate) {
                if ($candidate !== '' && $normalizedReply === $this->normalizeAgeRangeInput($candidate)) {
                    return $value;
                }
            }
        }

        return null;
    }

    protected function normalizeAgeRangeInput(string $value): string
    {
        $normalized = mb_strtolower(trim($value));
        $normalized = str_replace('ё', 'е', $normalized);
        $normalized = preg_replace('/[–—−]/u', '-', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s*-\s*/u', '-', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        return trim($normalized);
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
        ?array $telegramReplyMarkup = null,
        ?array $maxAttachments = null,
    ): void {
        try {
            $deliveryResult = match ($channel->platform) {
                Channel::PLATFORM_TELEGRAM => $telegramBotApiService->sendTextMessage(
                    $channel,
                    $message->external_chat_id,
                    $message->contactIdentity?->external_user_id,
                    $text,
                    $telegramReplyMarkup,
                ),
                Channel::PLATFORM_MAX => $maxBotApiService->sendTextMessage(
                    $channel,
                    $message->external_chat_id,
                    $message->contactIdentity?->external_user_id,
                    $text,
                    $maxAttachments,
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

    /**
     * @return array<string, mixed>|null
     */
    protected function telegramReplyMarkupForField(?string $field): ?array
    {
        if ($field !== Contact::DATA_COLLECTION_FIELD_AGE_RANGE) {
            return null;
        }

        $keyboard = [];

        foreach ((array) config('bots.data_collection.age_range.options', []) as $option) {
            if (! is_array($option) || ! filled($option['label'] ?? null)) {
                continue;
            }

            $keyboard[] = [[
                'text' => (string) $option['label'],
            ]];
        }

        if ($keyboard === []) {
            return null;
        }

        return [
            'keyboard' => $keyboard,
            'resize_keyboard' => true,
            'one_time_keyboard' => true,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    protected function maxAttachmentsForField(?string $field): ?array
    {
        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function telegramReplyMarkupForCompletion(Contact $contact): ?array
    {
        return $contact->data_collection_current_field === Contact::DATA_COLLECTION_FIELD_AGE_RANGE
            ? ['remove_keyboard' => true]
            : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function telegramReplyMarkupForTerminalSkip(?string $field): ?array
    {
        return $field === Contact::DATA_COLLECTION_FIELD_AGE_RANGE
            ? ['remove_keyboard' => true]
            : null;
    }
}
