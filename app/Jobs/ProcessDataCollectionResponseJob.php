<?php

namespace App\Jobs;

use App\Jobs\InferContactGenderFromFirstNameJob;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Services\DataCollection\DataCollectionFallbackTransitionResolver;
use App\Services\DataCollection\DataCollectionPromptHelper;
use App\Services\DataCollection\ExtractCityAction;
use App\Services\DataCollection\ExtractCountryAction;
use App\Services\DataCollection\ExtractFirstNameAction;
use App\Services\DataCollection\ExtractResidenceCityAction;
use App\Services\DataCollection\ResolveRussianRegionCandidatesLookupAction;
use App\Services\Bots\ChannelActivityLogger;
use App\Services\Bots\MaxBotApiService;
use App\Services\Bots\SendBotDialogTextAction;
use App\Services\Bitrix24\QueueBitrix24ContactSyncAction;
use App\Services\Contacts\ApplyContactFirstNameAction;
use App\Services\Contacts\SyncContactRussianRegionAction;
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

    protected const RUSSIAN_REGION_CONFIRM_MODE_CANDIDATE_BUTTONS = 'candidate_buttons';

    protected const RUSSIAN_REGION_CONFIRM_MODE_FREE_TEXT = 'free_text_region';

    public int $tries = 3;

    public function __construct(
        public int $inboundMessageId,
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
        $lockKey = $this->contactId ?? $this->inboundMessageId;
        $lockScope = $this->contactId !== null ? 'contact' : 'message';

        return [
            (new WithoutOverlapping("data-collection-response:{$lockScope}:{$lockKey}"))->expireAfter(180),
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
        ResolveRussianRegionCandidatesLookupAction $resolveRussianRegionCandidatesLookupAction,
        SyncContactRussianRegionAction $syncContactRussianRegionAction,
        QueueBitrix24ContactSyncAction $queueBitrix24ContactSyncAction,
        ApplyContactFirstNameAction $applyContactFirstNameAction,
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

        $channel = $message->channel;
        $contact = $message->contact;

        if (! $channel instanceof Channel || ! $channel->is_active || ! $contact instanceof Contact) {
            return;
        }

        if (! $contact->isInDataCollection()) {
            $this->logStaleResponseSkipped(
                $channelActivityLogger,
                $channel,
                $contact,
                $message,
                'collector_inactive',
            );

            return;
        }

        if ($this->expectedField !== null && $contact->data_collection_current_field !== $this->expectedField) {
            $this->logStaleResponseSkipped(
                $channelActivityLogger,
                $channel,
                $contact,
                $message,
                'field_mismatch',
            );

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

        if ($this->isSlashCommand($replyText)) {
            $this->repeatCurrentQuestionAfterSlashCommand(
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
                applyContactFirstNameAction: $applyContactFirstNameAction,
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
                resolveRussianRegionCandidatesLookupAction: $resolveRussianRegionCandidatesLookupAction,
                syncContactRussianRegionAction: $syncContactRussianRegionAction,
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
                syncContactRussianRegionAction: $syncContactRussianRegionAction,
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
                syncContactRussianRegionAction: $syncContactRussianRegionAction,
            ),
            Contact::DATA_COLLECTION_FIELD_RUSSIAN_REGION_CONFIRM => $this->handleRussianRegionConfirmReply(
                message: $message,
                channel: $channel,
                contact: $contact,
                replyText: $replyText,
                telegramBotApiService: $telegramBotApiService,
                maxBotApiService: $maxBotApiService,
                storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
                channelActivityLogger: $channelActivityLogger,
                queueBitrix24ContactSyncAction: $queueBitrix24ContactSyncAction,
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
                queueBitrix24ContactSyncAction: $queueBitrix24ContactSyncAction,
            ),
            default => null,
        };
    }

    protected function logStaleResponseSkipped(
        ChannelActivityLogger $channelActivityLogger,
        Channel $channel,
        Contact $contact,
        Message $message,
        string $reason,
    ): void {
        if ($this->expectedField === null) {
            return;
        }

        $channelActivityLogger->info(
            $channel,
            'contact.data_collection_response_stale_skipped',
            'Устаревший ответ анкеты пропущен, потому что текущий шаг контакта уже изменился.',
            [
                'platform' => $channel->platform,
                'message_id' => $message->id,
                'contact_id' => $contact->id,
                'expected_field' => $this->expectedField,
                'current_field' => $contact->data_collection_current_field,
                'current_status' => $contact->data_collection_status,
                'reason' => $reason,
            ],
        );
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
        ApplyContactFirstNameAction $applyContactFirstNameAction,
    ): void {
        try {
            $extraction = $extractFirstNameAction->handle(
                $replyText,
                $this->resolveFirstNameMessengerContext($message, $contact),
            );
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

        $result = $applyContactFirstNameAction->handle(
            $contact,
            $firstName,
            Contact::FIRST_NAME_SOURCE_CONTACT_CONFIRMED,
            ApplyContactFirstNameAction::REASON_SCENARIO_CONFIRMED,
        );

        $this->logFieldSaved($channel, $contact, $message, Contact::DATA_COLLECTION_FIELD_FIRST_NAME);

        if (
            $result->newSource === Contact::FIRST_NAME_SOURCE_CONTACT_CONFIRMED
            && ! filled($contact->gender)
        ) {
            InferContactGenderFromFirstNameJob::dispatch($contact->id, $firstName);
        }

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

    private function resolveFirstNameMessengerContext(Message $message, Contact $contact): ?string
    {
        $message->loadMissing(['dialog.currentContactIdentity', 'contactIdentity']);

        $dialogIdentity = $message->dialog?->currentContactIdentity;

        if ($dialogIdentity instanceof \App\Models\ContactIdentity && filled($dialogIdentity->display_name)) {
            return trim((string) $dialogIdentity->display_name);
        }

        $messageIdentity = $message->contactIdentity;

        if ($messageIdentity instanceof \App\Models\ContactIdentity && filled($messageIdentity->display_name)) {
            return trim((string) $messageIdentity->display_name);
        }

        $latestDialogIdentityId = $contact->dialogs()
            ->whereNotNull('current_contact_identity_id')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->value('current_contact_identity_id');

        if ($latestDialogIdentityId !== null) {
            $displayName = \App\Models\ContactIdentity::query()
                ->whereKey($latestDialogIdentityId)
                ->value('display_name');

            if (filled($displayName)) {
                return trim((string) $displayName);
            }
        }

        return null;
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
        ResolveRussianRegionCandidatesLookupAction $resolveRussianRegionCandidatesLookupAction,
        SyncContactRussianRegionAction $syncContactRussianRegionAction,
    ): void {
        try {
            $extraction = $extractResidenceCityAction->handle($replyText);
        } catch (Throwable $throwable) {
            Log::warning('contact.residence_city_extraction_exception', [
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

        if ($countryConfidence === ExtractResidenceCityAction::COUNTRY_CONFIDENCE_HIGH && filled($country)) {
            $contact->forceFill($attributes)->save();
            $syncContactRussianRegionAction->handle($contact, true);
            $this->dispatchDistanceToMoscowCalculation($contact);

            $this->logFieldSaved($channel, $contact, $message, Contact::DATA_COLLECTION_FIELD_RESIDENCE_CITY);

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

        $contact->forceFill($attributes)->save();

        $this->logFieldSaved($channel, $contact, $message, Contact::DATA_COLLECTION_FIELD_RESIDENCE_CITY);

        if ($this->applyResidenceCityRussianShortcut($contact, $city, $resolveRussianRegionCandidatesLookupAction)) {
            $this->dispatchDistanceToMoscowCalculation($contact);

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

        $syncContactRussianRegionAction->handle($contact, true);
        $this->dispatchDistanceToMoscowCalculation($contact);

        $this->moveToCountryStep(
            message: $message,
            channel: $channel,
            contact: $contact,
            telegramBotApiService: $telegramBotApiService,
            maxBotApiService: $maxBotApiService,
            storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
            channelActivityLogger: $channelActivityLogger,
            questionOverride: $this->countryQuestionAfterResidenceCity($city),
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
        SyncContactRussianRegionAction $syncContactRussianRegionAction,
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
        $syncContactRussianRegionAction->handle($contact, true);
        $this->dispatchDistanceToMoscowCalculation($contact);

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
        SyncContactRussianRegionAction $syncContactRussianRegionAction,
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
        $syncContactRussianRegionAction->handle($contact, true);
        $this->dispatchDistanceToMoscowCalculation($contact);

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

    protected function handleRussianRegionConfirmReply(
        Message $message,
        Channel $channel,
        Contact $contact,
        string $replyText,
        TelegramBotApiService $telegramBotApiService,
        MaxBotApiService $maxBotApiService,
        StoreDataCollectionOutboundMessageAction $storeDataCollectionOutboundMessageAction,
        ChannelActivityLogger $channelActivityLogger,
        QueueBitrix24ContactSyncAction $queueBitrix24ContactSyncAction,
    ): void {
        $resolution = $this->resolveRussianRegionConfirmInput($contact, $replyText);

        if ($resolution === 'skip') {
            $contact->forceFill([
                'region' => null,
                'region_status' => Contact::REGION_STATUS_AMBIGUOUS,
                'region_source' => null,
                'pending_region_candidates' => null,
            ])->save();
            $this->dispatchDistanceToMoscowCalculation($contact);

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

        if ($resolution === null) {
            $this->handleRetry(
                message: $message,
                channel: $channel,
                contact: $contact,
                currentField: Contact::DATA_COLLECTION_FIELD_RUSSIAN_REGION_CONFIRM,
                telegramBotApiService: $telegramBotApiService,
                maxBotApiService: $maxBotApiService,
                storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
                channelActivityLogger: $channelActivityLogger,
            );

            return;
        }

        $contact->forceFill([
            'region' => $resolution,
            'region_status' => Contact::REGION_STATUS_RESOLVED,
            'region_source' => Contact::REGION_SOURCE_CONFIRMED_BY_CONTACT,
            'pending_region_candidates' => null,
        ])->save();
        $this->dispatchDistanceToMoscowCalculation($contact);

        $this->logFieldSaved($channel, $contact, $message, Contact::DATA_COLLECTION_FIELD_RUSSIAN_REGION_CONFIRM);

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
        QueueBitrix24ContactSyncAction $queueBitrix24ContactSyncAction,
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
            queueBitrix24ContactSyncAction: $queueBitrix24ContactSyncAction,
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

    protected function repeatCurrentQuestionAfterSlashCommand(
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

        $this->sendReply(
            message: $message,
            channel: $channel,
            text: $this->currentPromptText($contact, $channel, $currentField),
            messageKind: Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION,
            telegramBotApiService: $telegramBotApiService,
            maxBotApiService: $maxBotApiService,
            storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
            channelActivityLogger: $channelActivityLogger,
            activityEvent: 'contact.data_collection_current_question_repeated',
            activityMessage: 'Повторно отправлен текущий вопрос сбора профиля после slash-команды.',
            telegramReplyMarkup: $this->telegramReplyMarkupForField($currentField, $contact),
            maxAttachments: $this->maxAttachmentsForField($currentField, $contact),
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
            if ($currentField === Contact::DATA_COLLECTION_FIELD_RUSSIAN_REGION_CONFIRM) {
                $this->moveToCityAfterRussianRegionFailure(
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

            $this->applyResolvedFallbackTransition(
                action: $this->fallbackTransitionResolver()->resolveAfterRetryLimit($currentField, filled($contact->city)),
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
            text: $currentField === Contact::DATA_COLLECTION_FIELD_RUSSIAN_REGION_CONFIRM
                ? $this->russianRegionConfirmRetryText($contact)
                : $this->retryMessage($currentField),
            messageKind: Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION,
            telegramBotApiService: $telegramBotApiService,
            maxBotApiService: $maxBotApiService,
            storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
            channelActivityLogger: $channelActivityLogger,
            activityEvent: 'contact.data_collection_retry_sent',
            activityMessage: 'Отправлено повторное сообщение сбора профиля.',
            telegramReplyMarkup: $this->telegramReplyMarkupForField($currentField, $contact),
            maxAttachments: $this->maxAttachmentsForField($currentField, $contact),
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

    protected function isSlashCommand(string $text): bool
    {
        return str_starts_with(trim($text), '/');
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
        if ($currentField === Contact::DATA_COLLECTION_FIELD_RUSSIAN_REGION_CONFIRM) {
            $contact->forceFill([
                'region' => null,
                'region_status' => Contact::REGION_STATUS_AMBIGUOUS,
                'region_source' => null,
                'pending_region_candidates' => null,
            ])->save();
            $this->dispatchDistanceToMoscowCalculation($contact);

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

        $this->applyResolvedFallbackTransition(
            action: $this->fallbackTransitionResolver()->resolveAfterLocalSkip($currentField, filled($contact->city)),
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

    protected function applyResolvedFallbackTransition(
        ?string $action,
        Message $message,
        Channel $channel,
        Contact $contact,
        ?string $field,
        TelegramBotApiService $telegramBotApiService,
        MaxBotApiService $maxBotApiService,
        StoreDataCollectionOutboundMessageAction $storeDataCollectionOutboundMessageAction,
        ChannelActivityLogger $channelActivityLogger,
    ): void {
        match ($action) {
            DataCollectionFallbackTransitionResolver::ACTION_MOVE_TO_RESIDENCE_CITY => $this->moveToResidenceCityStep(
                message: $message,
                channel: $channel,
                contact: $contact,
                telegramBotApiService: $telegramBotApiService,
                maxBotApiService: $maxBotApiService,
                storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
                channelActivityLogger: $channelActivityLogger,
            ),
            DataCollectionFallbackTransitionResolver::ACTION_MOVE_TO_COUNTRY => $this->moveToCountryStep(
                message: $message,
                channel: $channel,
                contact: $contact,
                telegramBotApiService: $telegramBotApiService,
                maxBotApiService: $maxBotApiService,
                storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
                channelActivityLogger: $channelActivityLogger,
            ),
            DataCollectionFallbackTransitionResolver::ACTION_MOVE_TO_CITY => $this->moveToCityStep(
                message: $message,
                channel: $channel,
                contact: $contact,
                telegramBotApiService: $telegramBotApiService,
                maxBotApiService: $maxBotApiService,
                storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
                channelActivityLogger: $channelActivityLogger,
            ),
            DataCollectionFallbackTransitionResolver::ACTION_MOVE_TO_AGE_RANGE => $this->moveToAgeRangeStep(
                message: $message,
                channel: $channel,
                contact: $contact,
                telegramBotApiService: $telegramBotApiService,
                maxBotApiService: $maxBotApiService,
                storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
                channelActivityLogger: $channelActivityLogger,
            ),
            DataCollectionFallbackTransitionResolver::ACTION_TERMINAL_SKIP, null => $this->sendTerminalSkip(
                message: $message,
                channel: $channel,
                contact: $contact,
                field: $field,
                telegramBotApiService: $telegramBotApiService,
                maxBotApiService: $maxBotApiService,
                storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
                channelActivityLogger: $channelActivityLogger,
            ),
        };
    }

    protected function moveToCountryStep(
        Message $message,
        Channel $channel,
        Contact $contact,
        TelegramBotApiService $telegramBotApiService,
        MaxBotApiService $maxBotApiService,
        StoreDataCollectionOutboundMessageAction $storeDataCollectionOutboundMessageAction,
        ChannelActivityLogger $channelActivityLogger,
        ?string $questionOverride = null,
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
            text: $questionOverride ?? $this->questionText(Contact::DATA_COLLECTION_FIELD_COUNTRY, $channel->platform),
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
            text: $this->questionText(Contact::DATA_COLLECTION_FIELD_RESIDENCE_CITY, $channel->platform),
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
        ?string $questionOverride = null,
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
            text: $questionOverride ?? $this->questionText(Contact::DATA_COLLECTION_FIELD_CITY, $channel->platform),
            messageKind: Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION,
            telegramBotApiService: $telegramBotApiService,
            maxBotApiService: $maxBotApiService,
            storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
            channelActivityLogger: $channelActivityLogger,
            activityEvent: 'contact.data_collection_next_question_sent',
            activityMessage: 'Отправлен следующий вопрос сбора профиля.',
        );
    }

    protected function moveToCityAfterRussianRegionFailure(
        Message $message,
        Channel $channel,
        Contact $contact,
        TelegramBotApiService $telegramBotApiService,
        MaxBotApiService $maxBotApiService,
        StoreDataCollectionOutboundMessageAction $storeDataCollectionOutboundMessageAction,
        ChannelActivityLogger $channelActivityLogger,
    ): void {
        $contact->forceFill([
            'city' => null,
            'region' => null,
            'region_status' => Contact::REGION_STATUS_UNKNOWN,
            'region_source' => null,
            'pending_region_candidates' => null,
            'distance_to_moscow_km' => null,
            'distance_to_moscow_status' => Contact::DISTANCE_TO_MOSCOW_STATUS_UNKNOWN,
            'distance_to_moscow_calculated_at' => null,
        ])->save();

        $this->moveToCityStep(
            message: $message,
            channel: $channel,
            contact: $contact,
            telegramBotApiService: $telegramBotApiService,
            maxBotApiService: $maxBotApiService,
            storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
            channelActivityLogger: $channelActivityLogger,
            questionOverride: $this->russianRegionFallbackToCityMessage(),
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
        if ($this->shouldAskRussianRegionConfirmation($contact)) {
            $this->moveToRussianRegionConfirmStep(
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
            text: $this->questionText(Contact::DATA_COLLECTION_FIELD_AGE_RANGE, $channel->platform),
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

    protected function moveToRussianRegionConfirmStep(
        Message $message,
        Channel $channel,
        Contact $contact,
        TelegramBotApiService $telegramBotApiService,
        MaxBotApiService $maxBotApiService,
        StoreDataCollectionOutboundMessageAction $storeDataCollectionOutboundMessageAction,
        ChannelActivityLogger $channelActivityLogger,
    ): void {
        if (! $this->shouldAskRussianRegionConfirmation($contact)) {
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

        $contact->startDataCollection(Contact::DATA_COLLECTION_FIELD_RUSSIAN_REGION_CONFIRM);

        $this->sendReply(
            message: $message,
            channel: $channel,
            text: $this->russianRegionConfirmQuestionText($contact),
            messageKind: Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION,
            telegramBotApiService: $telegramBotApiService,
            maxBotApiService: $maxBotApiService,
            storeDataCollectionOutboundMessageAction: $storeDataCollectionOutboundMessageAction,
            channelActivityLogger: $channelActivityLogger,
            activityEvent: 'contact.data_collection_next_question_sent',
            activityMessage: 'Отправлен следующий вопрос сбора профиля.',
            telegramReplyMarkup: $this->telegramReplyMarkupForField(Contact::DATA_COLLECTION_FIELD_RUSSIAN_REGION_CONFIRM, $contact),
            maxAttachments: $this->maxAttachmentsForField(Contact::DATA_COLLECTION_FIELD_RUSSIAN_REGION_CONFIRM, $contact),
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
        ?QueueBitrix24ContactSyncAction $queueBitrix24ContactSyncAction = null,
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
        ($queueBitrix24ContactSyncAction ?? app(QueueBitrix24ContactSyncAction::class))->handle($contact);

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
        app(QueueBitrix24ContactSyncAction::class)->handle($contact);

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
        return $this->promptHelper()->fieldConfig($field, $key, $default);
    }

    protected function questionText(string $field, ?string $platform = null): string
    {
        return $this->promptHelper()->questionText($field, $platform);
    }

    protected function currentPromptText(Contact $contact, Channel $channel, string $field): string
    {
        $boundary = $contact->data_collection_current_field_started_at ?? $contact->data_collection_started_at;

        $latestPromptText = $contact->messages()
            ->where('direction', Message::DIRECTION_OUTBOUND)
            ->where('message_kind', Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION)
            ->where(function ($query) use ($field): void {
                $query->where('message_parameter', $field)
                    ->orWhereNull('message_parameter');
            })
            ->when($boundary !== null, fn ($query) => $query->where('received_at', '>=', $boundary))
            ->orderByDesc('id')
            ->value('text');

        if (filled($latestPromptText)) {
            return (string) $latestPromptText;
        }

        return $field === Contact::DATA_COLLECTION_FIELD_RUSSIAN_REGION_CONFIRM
            ? $this->russianRegionConfirmQuestionText($contact)
            : $this->questionText($field, $channel->platform);
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
        return $this->promptHelper()->countryCityMismatchMessage($city, $country);
    }

    protected function countryQuestionAfterResidenceCity(string $city): string
    {
        return $this->promptHelper()->countryQuestionAfterResidenceCity($city);
    }

    protected function applyResidenceCityRussianShortcut(
        Contact $contact,
        string $city,
        ResolveRussianRegionCandidatesLookupAction $resolveRussianRegionCandidatesLookupAction,
    ): bool {
        $candidateRegions = $this->normalizeRussianShortcutCandidates(
            $resolveRussianRegionCandidatesLookupAction->handle($city)['candidate_regions'] ?? null,
        );

        if ($candidateRegions === []) {
            return false;
        }

        $payload = [
            'country' => 'Россия',
        ];

        if (count($candidateRegions) === 1) {
            $contact->forceFill(array_merge($payload, [
                'region' => $candidateRegions[0],
                'region_status' => Contact::REGION_STATUS_RESOLVED,
                'region_source' => Contact::REGION_SOURCE_AI,
                'pending_region_candidates' => null,
            ]))->save();

            return true;
        }

        if (count($candidateRegions) <= 4) {
            $contact->forceFill(array_merge($payload, [
                'region' => null,
                'region_status' => Contact::REGION_STATUS_CLARIFICATION_PENDING,
                'region_source' => null,
                'pending_region_candidates' => $candidateRegions,
            ]))->save();

            return true;
        }

        $contact->forceFill(array_merge($payload, [
            'region' => null,
            'region_status' => Contact::REGION_STATUS_AMBIGUOUS,
            'region_source' => null,
            'pending_region_candidates' => $candidateRegions,
        ]))->save();

        return true;
    }

    protected function dispatchDistanceToMoscowCalculation(Contact $contact): void
    {
        CalculateDistanceToMoscowJob::dispatch(
            $contact->id,
            $contact->city,
            $contact->country,
            $contact->region,
            $contact->region_status,
        );
    }

    /**
     * @return list<string>
     */
    protected function normalizeRussianShortcutCandidates(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $allowedRegions = array_keys(Contact::russianRegionOptions());

        if ($allowedRegions === []) {
            return [];
        }

        $normalized = [];

        foreach ($value as $candidate) {
            if (! is_string($candidate)) {
                return [];
            }

            $trimmed = trim($candidate);
            $key = $this->normalizeComparableText($trimmed);

            if ($trimmed === '' || ! in_array($trimmed, $allowedRegions, true)) {
                return [];
            }

            if (! array_key_exists($key, $normalized)) {
                $normalized[$key] = $trimmed;
            }
        }

        $values = array_values($normalized);

        usort($values, fn (string $left, string $right): int => strnatcasecmp(
            $this->normalizeComparableText($left),
            $this->normalizeComparableText($right),
        ));

        return $values;
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
        return $this->promptHelper()->resolveAgeRangeValue($replyText);
    }

    /**
     * @return string|'skip'|null
     */
    protected function resolveRussianRegionConfirmInput(Contact $contact, string $replyText): string|null
    {
        return $this->promptHelper()->resolveRussianRegionConfirmInput($contact, $replyText);
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
            $sendResult = app(SendBotDialogTextAction::class)->handleMessage(
                $message,
                $text,
                telegramReplyMarkup: $telegramReplyMarkup,
                maxAttachments: $maxAttachments,
            );

            if (! $sendResult->wasSent() || $sendResult->deliveryResult === null) {
                if ($messageKind === Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION) {
                    $this->markCurrentFieldPromptPending($message->contact);
                }

                $channelActivityLogger->info(
                    $channel,
                    'contact.data_collection_reply_skipped_dialog_not_sendable',
                    'Сообщение сбора профиля не отправлено: диалог сейчас недоступен для отправки.',
                    [
                        'contact_id' => $message->contact_id,
                        'channel_id' => $channel->id,
                        'message_id' => $message->id,
                        'dialog_id' => $sendResult->dialog?->id ?? $message->dialog_id,
                        'message_kind' => $messageKind,
                        'current_field' => $message->contact?->data_collection_current_field,
                        'route_status_code' => $sendResult->routeStatus->code,
                        'blocked_reason' => $sendResult->routeStatus->blockedReason,
                    ],
                );

                return;
            }

            $storeDataCollectionOutboundMessageAction->handle(
                $message,
                $sendResult->deliveryResult,
                $messageKind,
                $sendResult->dialog,
            );

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

    protected function markCurrentFieldPromptPending(?Contact $contact): void
    {
        if (! $contact instanceof Contact || ! filled($contact->data_collection_current_field)) {
            return;
        }

        $contact->forceFill([
            'data_collection_last_prompted_field' => null,
            'data_collection_current_field_started_at' => null,
        ])->save();
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function telegramReplyMarkupForField(?string $field, ?Contact $contact = null): ?array
    {
        return $this->promptHelper()->telegramReplyMarkupForField($field, $contact);
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    protected function maxAttachmentsForField(?string $field, ?Contact $contact = null): ?array
    {
        return $this->promptHelper()->maxAttachmentsForField($field, $contact);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function telegramReplyMarkupForCompletion(Contact $contact): ?array
    {
        return $this->promptHelper()->telegramReplyMarkupForCompletion($contact);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function telegramReplyMarkupForTerminalSkip(?string $field): ?array
    {
        return $this->promptHelper()->telegramReplyMarkupForTerminalSkip($field);
    }

    protected function shouldAskRussianRegionConfirmation(Contact $contact): bool
    {
        return $this->promptHelper()->shouldAskRussianRegionConfirmation($contact);
    }

    protected function russianRegionConfirmQuestionText(Contact $contact): string
    {
        return $this->promptHelper()->russianRegionConfirmQuestionText($contact);
    }

    protected function russianRegionConfirmRetryText(Contact $contact): string
    {
        return $this->promptHelper()->russianRegionConfirmRetryText($contact);
    }

    protected function russianRegionFallbackToCityMessage(): string
    {
        return $this->promptHelper()->russianRegionFallbackToCityMessage();
    }

    protected function normalizeComparableText(string $value): string
    {
        return $this->promptHelper()->normalizeComparableText($value);
    }

    protected function promptHelper(): DataCollectionPromptHelper
    {
        return app(DataCollectionPromptHelper::class);
    }

    protected function fallbackTransitionResolver(): DataCollectionFallbackTransitionResolver
    {
        return app(DataCollectionFallbackTransitionResolver::class);
    }
}
