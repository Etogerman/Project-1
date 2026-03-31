<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Services\Geo\CalculateDistanceToMoscowAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CalculateDistanceToMoscowActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_action_returns_zero_for_moscow_without_api_call(): void
    {
        config()->set('services.yandex_geocoder.api_key', 'yandex-key');

        Http::fake();

        $contact = Contact::factory()->make([
            'country' => 'Россия',
            'city' => 'Москва',
        ]);

        $distance = app(CalculateDistanceToMoscowAction::class)->handle($contact);

        $this->assertSame(0, $distance);
        Http::assertNothingSent();
    }

    public function test_action_returns_reasonable_distance_for_saint_petersburg(): void
    {
        config()->set('services.yandex_geocoder.api_key', 'yandex-key');
        config()->set('russian_region_cities.cities', [
            'санкт петербург' => [
                'city' => 'Санкт-Петербург',
                'aliases' => ['спб'],
                'regions' => ['Санкт-Петербург'],
                'geocode_query' => 'Санкт-Петербург, Россия',
            ],
        ]);

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

        $contact = Contact::factory()->make([
            'country' => 'Россия',
            'city' => 'Санкт-Петербург',
        ]);

        $distance = app(CalculateDistanceToMoscowAction::class)->handle($contact);

        $this->assertIsInt($distance);
        $this->assertGreaterThan(600, $distance);
        $this->assertLessThan(700, $distance);
        Http::assertSent(fn ($request) => ($request['geocode'] ?? null) === 'Санкт-Петербург, Россия');
    }

    public function test_action_returns_reasonable_distance_for_krasnodar(): void
    {
        config()->set('services.yandex_geocoder.api_key', 'yandex-key');

        Http::fake([
            'https://geocode-maps.yandex.ru/1.x/*' => Http::response([
                'response' => [
                    'GeoObjectCollection' => [
                        'featureMember' => [
                            [
                                'GeoObject' => [
                                    'Point' => [
                                        'pos' => '38.9747 45.0355',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $contact = Contact::factory()->make([
            'country' => 'Россия',
            'city' => 'Краснодар',
        ]);

        $distance = app(CalculateDistanceToMoscowAction::class)->handle($contact);

        $this->assertIsInt($distance);
        $this->assertGreaterThan(1100, $distance);
        $this->assertLessThan(1300, $distance);
    }

    public function test_action_returns_null_for_ambiguous_city_without_confirmed_region(): void
    {
        config()->set('services.yandex_geocoder.api_key', 'yandex-key');
        config()->set('russian_region_cities.cities', [
            'михайловск' => [
                'city' => 'Михайловск',
                'aliases' => [],
                'regions' => ['Свердловская область', 'Ставропольский край'],
                'geocode_queries_by_region' => [
                    'Свердловская область' => 'Михайловск, Нижнесергинский район, Свердловская область, Россия',
                    'Ставропольский край' => 'Михайловск, Ставропольский край, Россия',
                ],
            ],
        ]);

        Http::fake();

        $contact = Contact::factory()->make([
            'country' => 'Россия',
            'city' => 'Михайловск',
            'region' => null,
            'region_status' => Contact::REGION_STATUS_CLARIFICATION_PENDING,
        ]);

        $distance = app(CalculateDistanceToMoscowAction::class)->handle($contact);

        $this->assertNull($distance);
        Http::assertNothingSent();
    }

    public function test_action_uses_exact_query_for_ambiguous_city_with_confirmed_region(): void
    {
        config()->set('services.yandex_geocoder.api_key', 'yandex-key');
        config()->set('russian_region_cities.cities', [
            'михайловск' => [
                'city' => 'Михайловск',
                'aliases' => [],
                'regions' => ['Свердловская область', 'Ставропольский край'],
                'geocode_queries_by_region' => [
                    'Свердловская область' => 'Михайловск, Нижнесергинский район, Свердловская область, Россия',
                    'Ставропольский край' => 'Михайловск, Ставропольский край, Россия',
                ],
            ],
        ]);

        Http::fake([
            'https://geocode-maps.yandex.ru/1.x/*' => Http::response([
                'response' => [
                    'GeoObjectCollection' => [
                        'featureMember' => [
                            [
                                'GeoObject' => [
                                    'Point' => [
                                        'pos' => '42.0288 45.1293',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $contact = Contact::factory()->make([
            'country' => 'Россия',
            'city' => 'Михайловск',
            'region' => 'Ставропольский край',
            'region_status' => Contact::REGION_STATUS_RESOLVED,
        ]);

        $distance = app(CalculateDistanceToMoscowAction::class)->handle($contact);

        $this->assertIsInt($distance);
        $this->assertGreaterThan(1200, $distance);
        $this->assertLessThan(1300, $distance);
        Http::assertSent(fn ($request) => ($request['geocode'] ?? null) === 'Михайловск, Ставропольский край, Россия');
    }

    public function test_action_returns_null_for_non_russian_contact(): void
    {
        config()->set('services.yandex_geocoder.api_key', 'yandex-key');

        Http::fake();

        $contact = Contact::factory()->make([
            'country' => 'Венгрия',
            'city' => 'Будапешт',
        ]);

        $distance = app(CalculateDistanceToMoscowAction::class)->handle($contact);

        $this->assertNull($distance);
        Http::assertNothingSent();
    }

    public function test_action_returns_null_when_city_is_missing(): void
    {
        config()->set('services.yandex_geocoder.api_key', 'yandex-key');

        Http::fake();

        $contact = Contact::factory()->make([
            'country' => 'Россия',
            'city' => null,
        ]);

        $distance = app(CalculateDistanceToMoscowAction::class)->handle($contact);

        $this->assertNull($distance);
        Http::assertNothingSent();
    }
}
