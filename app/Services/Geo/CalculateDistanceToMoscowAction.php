<?php

namespace App\Services\Geo;

use App\Models\Contact;

class CalculateDistanceToMoscowAction
{
    public function __construct(
        private readonly YandexGeocoderService $yandexGeocoderService,
        private readonly ResolveRussianLocalityGeocodeQueryAction $resolveRussianLocalityGeocodeQueryAction,
    ) {}

    public function handle(Contact $contact, ?string $geocodeQuery = null): ?int
    {
        $country = $this->normalizeNullableString($contact->country);
        $city = $this->normalizeNullableString($contact->city);

        if ($country === null || ! $this->isRussianCountry($country) || $city === null) {
            return null;
        }

        if ($this->isMoscowCity($city)) {
            return 0;
        }

        if ($geocodeQuery === null) {
            $queryResolution = $this->resolveRussianLocalityGeocodeQueryAction->handle($contact);

            if ($queryResolution['status'] !== 'ready' || ! is_string($queryResolution['query'])) {
                return null;
            }

            $geocodeQuery = $queryResolution['query'];
        }

        $coordinates = $this->yandexGeocoderService->geocode($geocodeQuery);

        if ($coordinates === null) {
            return null;
        }

        $referenceLat = (float) config('services.moscow_distance.reference_lat', 55.7558);
        $referenceLng = (float) config('services.moscow_distance.reference_lng', 37.6173);

        return (int) round($this->calculateHaversineDistance(
            $coordinates['lat'],
            $coordinates['lng'],
            $referenceLat,
            $referenceLng,
        ));
    }

    private function calculateHaversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusKm = 6371.0;
        $deltaLat = deg2rad($lat2 - $lat1);
        $deltaLng = deg2rad($lng2 - $lng1);

        $a = sin($deltaLat / 2) ** 2
            + cos(deg2rad($lat1))
            * cos(deg2rad($lat2))
            * sin($deltaLng / 2) ** 2;

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadiusKm * $c;
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

        return in_array($normalized, ['россия', 'российская федерация', 'рф', 'russia'], true);
    }

    private function isMoscowCity(string $city): bool
    {
        return mb_strtolower(trim($city)) === 'москва';
    }
}
