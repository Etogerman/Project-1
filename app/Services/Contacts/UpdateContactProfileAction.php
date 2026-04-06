<?php

namespace App\Services\Contacts;

use App\Jobs\CalculateDistanceToMoscowJob;
use App\Models\Contact;
use App\Services\Bitrix24\QueueBitrix24ContactSyncAction;
use App\Services\DataCollection\ResolveNextDataCollectionFieldAction;
use Illuminate\Support\Carbon;

class UpdateContactProfileAction
{
    public function __construct(
        private readonly SyncContactRussianRegionAction $syncContactRussianRegionAction,
        private readonly ResolveRootContactAction $resolveRootContactAction,
        private readonly QueueBitrix24ContactSyncAction $queueBitrix24ContactSyncAction,
        private readonly ResolveNextDataCollectionFieldAction $resolveNextDataCollectionFieldAction,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(Contact $contact, array $attributes): Contact
    {
        $contact = $this->resolveRootContactAction->handle($contact);
        $collectorWasActive = $contact->isInDataCollection();

        $firstName = $this->normalizeNullableString($attributes['first_name'] ?? null);
        $lastName = $this->normalizeNullableString($attributes['last_name'] ?? null);
        $gender = $this->normalizeGender($attributes['gender'] ?? null);
        $country = $this->normalizeNullableString($attributes['country'] ?? null);
        $city = $this->normalizeNullableString($attributes['city'] ?? null);
        $region = $this->normalizeRegion($attributes['region'] ?? null);
        $ageRange = $this->normalizeAgeRange($attributes['age_range'] ?? null);
        $birthDate = $this->normalizeBirthDate($attributes['birth_date'] ?? null);
        $ageYears = $birthDate === null
            ? $this->normalizeNullableInt($attributes['age_years'] ?? null)
            : null;
        $countryOrCityChanged = $contact->country !== $country || $contact->city !== $city;
        $regionChanged = $contact->region !== $region;
        $locationChanged = $countryOrCityChanged || $regionChanged;

        $payload = [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'gender' => $gender,
            'birth_date' => $birthDate,
            'age_years' => $ageYears,
            'age_range' => $ageRange,
            'country' => $country,
            'city' => $city,
        ];

        if ($country !== null && ! $this->isRussianCountry($country)) {
            $contact->forceFill(array_merge($payload, [
                'region' => null,
                'region_status' => Contact::REGION_STATUS_OUT_OF_SCOPE,
                'region_source' => null,
                'pending_region_candidates' => null,
            ]))->save();

            if ($locationChanged) {
                $this->dispatchDistanceToMoscowCalculation($contact);
            }

            $this->reconcileActiveDataCollectionState($contact, $collectorWasActive);
            $this->queueBitrix24ContactSyncAction->handle($contact);

            return $contact->fresh();
        }

        if ($regionChanged) {
            $contact->forceFill(array_merge($payload, [
                'region' => $region,
                'region_status' => $region === null ? null : Contact::REGION_STATUS_RESOLVED,
                'region_source' => $region === null ? null : Contact::REGION_SOURCE_MANUAL,
                'pending_region_candidates' => null,
            ]))->save();

            if ($locationChanged) {
                $this->dispatchDistanceToMoscowCalculation($contact);
            }

            $this->reconcileActiveDataCollectionState($contact, $collectorWasActive);
            $this->queueBitrix24ContactSyncAction->handle($contact);

            return $contact->fresh();
        }

        $contact->forceFill($payload)->save();

        if ($countryOrCityChanged) {
            $this->syncContactRussianRegionAction->handle($contact, false);
        }

        if ($locationChanged) {
            $this->dispatchDistanceToMoscowCalculation($contact);
        }

        $this->reconcileActiveDataCollectionState($contact, $collectorWasActive);
        $this->queueBitrix24ContactSyncAction->handle($contact);

        return $contact->fresh();
    }

    private function dispatchDistanceToMoscowCalculation(Contact $contact): void
    {
        CalculateDistanceToMoscowJob::dispatch(
            $contact->id,
            $contact->city,
            $contact->country,
            $contact->region,
            $contact->region_status,
        );
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function normalizeNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function normalizeBirthDate(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Carbon::parse($value)->toDateString();
    }

    private function normalizeAgeRange(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        return array_key_exists($trimmed, Contact::ageRangeOptions()) ? $trimmed : null;
    }

    private function normalizeGender(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        return array_key_exists($trimmed, Contact::genderOptions()) ? $trimmed : null;
    }

    private function normalizeRegion(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        return array_key_exists($trimmed, Contact::russianRegionOptions()) ? $trimmed : null;
    }

    private function isRussianCountry(string $country): bool
    {
        $normalized = mb_strtolower(trim($country));

        return in_array($normalized, ['россия', 'российская федерация', 'рф', 'russia'], true);
    }

    private function reconcileActiveDataCollectionState(Contact $contact, bool $collectorWasActive): void
    {
        if (! $collectorWasActive) {
            return;
        }

        $nextField = $this->resolveNextDataCollectionFieldAction->handle($contact);

        if ($nextField === null) {
            $contact->completeDataCollection();

            return;
        }

        if ($nextField !== $contact->data_collection_current_field) {
            $contact->startDataCollection($nextField);
        }
    }
}
