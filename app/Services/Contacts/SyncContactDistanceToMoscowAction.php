<?php

namespace App\Services\Contacts;

use App\Models\Contact;
use App\Services\Geo\CalculateDistanceToMoscowAction;
use App\Services\Geo\ResolveRussianLocalityGeocodeQueryAction;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncContactDistanceToMoscowAction
{
    public function __construct(
        private readonly CalculateDistanceToMoscowAction $calculateDistanceToMoscowAction,
        private readonly ResolveRussianLocalityGeocodeQueryAction $resolveRussianLocalityGeocodeQueryAction,
    ) {}

    public function handle(Contact $contact): Contact
    {
        $country = $this->normalizeNullableString($contact->country);
        $city = $this->normalizeNullableString($contact->city);

        if ($country !== null && ! $this->isRussianCountry($country)) {
            $contact->forceFill([
                'distance_to_moscow_km' => null,
                'distance_to_moscow_status' => Contact::DISTANCE_TO_MOSCOW_STATUS_OUT_OF_SCOPE,
                'distance_to_moscow_calculated_at' => now(),
            ])->save();

            return $contact;
        }

        if ($country === null || $city === null) {
            $contact->forceFill([
                'distance_to_moscow_km' => null,
                'distance_to_moscow_status' => Contact::DISTANCE_TO_MOSCOW_STATUS_PENDING,
                'distance_to_moscow_calculated_at' => null,
            ])->save();

            return $contact;
        }

        if ($this->isMoscowCity($city)) {
            $contact->forceFill([
                'distance_to_moscow_km' => 0,
                'distance_to_moscow_status' => Contact::DISTANCE_TO_MOSCOW_STATUS_RESOLVED,
                'distance_to_moscow_calculated_at' => now(),
            ])->save();

            return $contact;
        }

        $queryResolution = $this->resolveRussianLocalityGeocodeQueryAction->handle($contact);

        if ($queryResolution['status'] === 'out_of_scope') {
            $contact->forceFill([
                'distance_to_moscow_km' => null,
                'distance_to_moscow_status' => Contact::DISTANCE_TO_MOSCOW_STATUS_OUT_OF_SCOPE,
                'distance_to_moscow_calculated_at' => now(),
            ])->save();

            return $contact;
        }

        if ($queryResolution['status'] === 'pending') {
            $contact->forceFill([
                'distance_to_moscow_km' => null,
                'distance_to_moscow_status' => Contact::DISTANCE_TO_MOSCOW_STATUS_PENDING,
                'distance_to_moscow_calculated_at' => null,
            ])->save();

            return $contact;
        }

        if ($queryResolution['status'] === 'unknown') {
            $contact->forceFill([
                'distance_to_moscow_km' => null,
                'distance_to_moscow_status' => Contact::DISTANCE_TO_MOSCOW_STATUS_UNKNOWN,
                'distance_to_moscow_calculated_at' => now(),
            ])->save();

            return $contact;
        }

        try {
            $distance = $this->calculateDistanceToMoscowAction->handle(
                $contact,
                is_string($queryResolution['query'] ?? null) ? $queryResolution['query'] : null,
            );
        } catch (Throwable $throwable) {
            Log::warning('contact.distance_to_moscow_calculation_failed', [
                'contact_id' => $contact->id,
                'country' => $contact->country,
                'city' => $contact->city,
                'exception_class' => $throwable::class,
                'error' => $throwable->getMessage(),
            ]);

            $contact->forceFill([
                'distance_to_moscow_status' => Contact::DISTANCE_TO_MOSCOW_STATUS_FAILED,
            ])->save();

            return $contact;
        }

        if ($distance === null) {
            $contact->forceFill([
                'distance_to_moscow_km' => null,
                'distance_to_moscow_status' => Contact::DISTANCE_TO_MOSCOW_STATUS_UNKNOWN,
                'distance_to_moscow_calculated_at' => now(),
            ])->save();

            return $contact;
        }

        $contact->forceFill([
            'distance_to_moscow_km' => $distance,
            'distance_to_moscow_status' => Contact::DISTANCE_TO_MOSCOW_STATUS_RESOLVED,
            'distance_to_moscow_calculated_at' => now(),
        ])->save();

        return $contact;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function isRussianCountry(string $country): bool
    {
        $normalized = mb_strtolower(trim($country));

        return in_array($normalized, ['ru', 'rus', 'россия', 'российская федерация', 'рф', 'russia'], true);
    }

    private function isMoscowCity(string $city): bool
    {
        return mb_strtolower(trim($city)) === 'москва';
    }
}
