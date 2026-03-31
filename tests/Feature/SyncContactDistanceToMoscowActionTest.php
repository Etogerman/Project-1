<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Services\Contacts\SyncContactDistanceToMoscowAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncContactDistanceToMoscowActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_action_marks_moscow_as_zero_distance(): void
    {
        $contact = Contact::factory()->create([
            'country' => 'Россия',
            'city' => 'Москва',
            'distance_to_moscow_km' => null,
            'distance_to_moscow_status' => null,
            'distance_to_moscow_calculated_at' => null,
        ]);

        $updated = app(SyncContactDistanceToMoscowAction::class)->handle($contact)->fresh();

        $this->assertSame(0, $updated->distance_to_moscow_km);
        $this->assertSame(Contact::DISTANCE_TO_MOSCOW_STATUS_RESOLVED, $updated->distance_to_moscow_status);
        $this->assertNotNull($updated->distance_to_moscow_calculated_at);
    }

    public function test_action_marks_regular_russian_city_as_pending(): void
    {
        config()->set('services.yandex_geocoder.api_key', 'yandex-key');
        config()->set('russian_region_cities.cities', [
            'санкт петербург' => [
                'city' => 'Санкт-Петербург',
                'aliases' => [],
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

        $contact = Contact::factory()->create([
            'country' => 'Россия',
            'city' => 'Санкт-Петербург',
            'distance_to_moscow_km' => 42,
            'distance_to_moscow_status' => Contact::DISTANCE_TO_MOSCOW_STATUS_RESOLVED,
            'distance_to_moscow_calculated_at' => now(),
        ]);

        $updated = app(SyncContactDistanceToMoscowAction::class)->handle($contact)->fresh();

        $this->assertNotNull($updated->distance_to_moscow_km);
        $this->assertGreaterThan(600, $updated->distance_to_moscow_km);
        $this->assertLessThan(700, $updated->distance_to_moscow_km);
        $this->assertSame(Contact::DISTANCE_TO_MOSCOW_STATUS_RESOLVED, $updated->distance_to_moscow_status);
        $this->assertNotNull($updated->distance_to_moscow_calculated_at);
    }

    public function test_action_marks_missing_city_as_pending(): void
    {
        $contact = Contact::factory()->create([
            'country' => 'Россия',
            'city' => null,
        ]);

        $updated = app(SyncContactDistanceToMoscowAction::class)->handle($contact)->fresh();

        $this->assertNull($updated->distance_to_moscow_km);
        $this->assertSame(Contact::DISTANCE_TO_MOSCOW_STATUS_PENDING, $updated->distance_to_moscow_status);
        $this->assertNull($updated->distance_to_moscow_calculated_at);
    }

    public function test_action_keeps_ambiguous_russian_city_as_pending(): void
    {
        config()->set('services.yandex_geocoder.api_key', 'yandex-key');
        config()->set('russian_region_cities.cities', [
            'михайловск' => [
                'city' => 'Михайловск',
                'aliases' => [],
                'regions' => ['Свердловская область', 'Ставропольский край'],
            ],
        ]);

        Http::fake();

        $contact = Contact::factory()->create([
            'country' => 'Россия',
            'city' => 'Михайловск',
            'region_status' => Contact::REGION_STATUS_CLARIFICATION_PENDING,
        ]);

        $updated = app(SyncContactDistanceToMoscowAction::class)->handle($contact)->fresh();

        $this->assertNull($updated->distance_to_moscow_km);
        $this->assertSame(Contact::DISTANCE_TO_MOSCOW_STATUS_PENDING, $updated->distance_to_moscow_status);
        $this->assertNull($updated->distance_to_moscow_calculated_at);
        Http::assertNothingSent();
    }

    public function test_action_resolves_ambiguous_city_after_region_confirmation(): void
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

        $contact = Contact::factory()->create([
            'country' => 'Россия',
            'city' => 'Михайловск',
            'region' => 'Ставропольский край',
            'region_status' => Contact::REGION_STATUS_RESOLVED,
        ]);

        $updated = app(SyncContactDistanceToMoscowAction::class)->handle($contact)->fresh();

        $this->assertNotNull($updated->distance_to_moscow_km);
        $this->assertGreaterThan(1200, $updated->distance_to_moscow_km);
        $this->assertLessThan(1300, $updated->distance_to_moscow_km);
        $this->assertSame(Contact::DISTANCE_TO_MOSCOW_STATUS_RESOLVED, $updated->distance_to_moscow_status);
        Http::assertSent(fn ($request) => ($request['geocode'] ?? null) === 'Михайловск, Ставропольский край, Россия');
    }

    public function test_action_marks_unknown_for_ambiguous_city_when_confirmed_region_has_no_mapping(): void
    {
        config()->set('services.yandex_geocoder.api_key', 'yandex-key');
        config()->set('russian_region_cities.cities', [
            'михайловск' => [
                'city' => 'Михайловск',
                'aliases' => [],
                'regions' => ['Свердловская область', 'Ставропольский край'],
                'geocode_queries_by_region' => [
                    'Свердловская область' => 'Михайловск, Нижнесергинский район, Свердловская область, Россия',
                ],
            ],
        ]);

        Http::fake();

        $contact = Contact::factory()->create([
            'country' => 'Россия',
            'city' => 'Михайловск',
            'region' => 'Ставропольский край',
            'region_status' => Contact::REGION_STATUS_RESOLVED,
        ]);

        $updated = app(SyncContactDistanceToMoscowAction::class)->handle($contact)->fresh();

        $this->assertNull($updated->distance_to_moscow_km);
        $this->assertSame(Contact::DISTANCE_TO_MOSCOW_STATUS_UNKNOWN, $updated->distance_to_moscow_status);
        $this->assertNotNull($updated->distance_to_moscow_calculated_at);
        Http::assertNothingSent();
    }

    public function test_action_marks_unknown_when_geocoder_returns_no_result(): void
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

        $contact = Contact::factory()->create([
            'country' => 'Россия',
            'city' => 'Неизвестный',
        ]);

        $updated = app(SyncContactDistanceToMoscowAction::class)->handle($contact)->fresh();

        $this->assertNull($updated->distance_to_moscow_km);
        $this->assertSame(Contact::DISTANCE_TO_MOSCOW_STATUS_UNKNOWN, $updated->distance_to_moscow_status);
        $this->assertNotNull($updated->distance_to_moscow_calculated_at);
    }

    public function test_action_marks_failed_without_overwriting_existing_distance(): void
    {
        config()->set('services.yandex_geocoder.api_key', 'yandex-key');

        Http::fake([
            'https://geocode-maps.yandex.ru/1.x/*' => Http::response([], 500),
        ]);

        $contact = Contact::factory()->create([
            'country' => 'Россия',
            'city' => 'Казань',
            'distance_to_moscow_km' => 810,
            'distance_to_moscow_status' => Contact::DISTANCE_TO_MOSCOW_STATUS_RESOLVED,
            'distance_to_moscow_calculated_at' => now()->subDay(),
        ]);

        $updated = app(SyncContactDistanceToMoscowAction::class)->handle($contact)->fresh();

        $this->assertSame(810, $updated->distance_to_moscow_km);
        $this->assertSame(Contact::DISTANCE_TO_MOSCOW_STATUS_FAILED, $updated->distance_to_moscow_status);
        $this->assertNotNull($updated->distance_to_moscow_calculated_at);
    }

    public function test_action_marks_non_russian_contact_as_out_of_scope(): void
    {
        $contact = Contact::factory()->create([
            'country' => 'Венгрия',
            'city' => 'Будапешт',
            'distance_to_moscow_km' => 10,
            'distance_to_moscow_status' => Contact::DISTANCE_TO_MOSCOW_STATUS_RESOLVED,
            'distance_to_moscow_calculated_at' => now(),
        ]);

        $updated = app(SyncContactDistanceToMoscowAction::class)->handle($contact)->fresh();

        $this->assertNull($updated->distance_to_moscow_km);
        $this->assertSame(Contact::DISTANCE_TO_MOSCOW_STATUS_OUT_OF_SCOPE, $updated->distance_to_moscow_status);
        $this->assertNotNull($updated->distance_to_moscow_calculated_at);
    }
}
