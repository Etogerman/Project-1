<?php

namespace Tests\Feature;

use App\Services\Geo\YandexGeocoderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class YandexGeocoderServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_returns_coordinates_from_successful_response(): void
    {
        config()->set('services.yandex_geocoder.api_key', 'yandex-key');
        config()->set('services.yandex_geocoder.base_url', 'https://geocode-maps.yandex.ru/1.x/');

        Http::fake([
            'https://geocode-maps.yandex.ru/1.x/*' => Http::response([
                'response' => [
                    'GeoObjectCollection' => [
                        'featureMember' => [
                            [
                                'GeoObject' => [
                                    'Point' => [
                                        'pos' => '30.3159 59.9391',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $coordinates = app(YandexGeocoderService::class)->geocode('Санкт-Петербург, Россия');

        $this->assertSame([
            'lat' => 59.9391,
            'lng' => 30.3159,
        ], $coordinates);
    }

    public function test_service_returns_null_when_result_is_empty(): void
    {
        config()->set('services.yandex_geocoder.api_key', 'yandex-key');

        Http::fake([
            'https://geocode-maps.yandex.ru/1.x/*' => Http::response([
                'response' => [
                    'GeoObjectCollection' => [
                        'featureMember' => [],
                    ],
                ],
            ]),
        ]);

        $coordinates = app(YandexGeocoderService::class)->geocode('Неизвестный, Россия');

        $this->assertNull($coordinates);
    }

    public function test_service_throws_when_api_key_is_missing(): void
    {
        config()->set('services.yandex_geocoder.api_key', null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Yandex geocoder API key is not configured.');

        app(YandexGeocoderService::class)->geocode('Москва, Россия');
    }

    public function test_service_throws_on_http_error(): void
    {
        config()->set('services.yandex_geocoder.api_key', 'yandex-key');

        Http::fake([
            'https://geocode-maps.yandex.ru/1.x/*' => Http::response([], 500),
        ]);

        $this->expectException(\Illuminate\Http\Client\RequestException::class);

        app(YandexGeocoderService::class)->geocode('Москва, Россия');
    }
}
