<?php

namespace App\Services\Geo;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class YandexGeocoderService
{
    /**
     * @return array{lat: float, lng: float}|null
     */
    public function geocode(string $query): ?array
    {
        $apiKey = trim((string) config('services.yandex_geocoder.api_key', ''));

        if ($apiKey === '') {
            throw new RuntimeException('Yandex geocoder API key is not configured.');
        }

        $response = Http::baseUrl((string) config('services.yandex_geocoder.base_url', 'https://geocode-maps.yandex.ru/1.x/'))
            ->get('', [
                'apikey' => $apiKey,
                'geocode' => $query,
                'format' => 'json',
                'results' => 1,
            ])
            ->throw();

        $pos = data_get($response->json(), 'response.GeoObjectCollection.featureMember.0.GeoObject.Point.pos');

        if (! is_string($pos) || trim($pos) === '') {
            return null;
        }

        $parts = preg_split('/\s+/', trim($pos));

        if (! is_array($parts) || count($parts) !== 2 || ! is_numeric($parts[0]) || ! is_numeric($parts[1])) {
            return null;
        }

        return [
            'lat' => (float) $parts[1],
            'lng' => (float) $parts[0],
        ];
    }
}
