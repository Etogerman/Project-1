<?php

namespace Tests\Feature;

use App\Jobs\CalculateDistanceToMoscowJob;
use App\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CalculateDistanceToMoscowJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_sets_zero_distance_for_moscow(): void
    {
        $contact = Contact::factory()->create([
            'country' => 'Россия',
            'city' => 'Москва',
        ]);

        CalculateDistanceToMoscowJob::dispatchSync($contact->id);

        $contact->refresh();

        $this->assertSame(0, $contact->distance_to_moscow_km);
        $this->assertSame(Contact::DISTANCE_TO_MOSCOW_STATUS_RESOLVED, $contact->distance_to_moscow_status);
        $this->assertNotNull($contact->distance_to_moscow_calculated_at);
    }

    public function test_job_marks_non_russian_contact_as_out_of_scope(): void
    {
        $contact = Contact::factory()->create([
            'country' => 'Венгрия',
            'city' => 'Будапешт',
        ]);

        CalculateDistanceToMoscowJob::dispatchSync($contact->id);

        $contact->refresh();

        $this->assertNull($contact->distance_to_moscow_km);
        $this->assertSame(Contact::DISTANCE_TO_MOSCOW_STATUS_OUT_OF_SCOPE, $contact->distance_to_moscow_status);
    }

    public function test_job_marks_regular_russian_city_as_pending(): void
    {
        config()->set('services.yandex_geocoder.api_key', 'yandex-key');
        config()->set('russian_region_cities.cities', [
            'казань' => [
                'city' => 'Казань',
                'aliases' => [],
                'regions' => ['Республика Татарстан'],
                'geocode_query' => 'Казань, Республика Татарстан, Россия',
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
                                        'pos' => '49.1064 55.7961',
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
            'city' => 'Казань',
        ]);

        CalculateDistanceToMoscowJob::dispatchSync($contact->id);

        $contact->refresh();

        $this->assertNotNull($contact->distance_to_moscow_km);
        $this->assertGreaterThan(700, $contact->distance_to_moscow_km);
        $this->assertLessThan(800, $contact->distance_to_moscow_km);
        $this->assertSame(Contact::DISTANCE_TO_MOSCOW_STATUS_RESOLVED, $contact->distance_to_moscow_status);
        $this->assertNotNull($contact->distance_to_moscow_calculated_at);
    }

    public function test_job_resolves_distance_for_ambiguous_city_after_region_confirmation(): void
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

        CalculateDistanceToMoscowJob::dispatchSync($contact->id);

        $contact->refresh();

        $this->assertNotNull($contact->distance_to_moscow_km);
        $this->assertGreaterThan(1200, $contact->distance_to_moscow_km);
        $this->assertLessThan(1300, $contact->distance_to_moscow_km);
        $this->assertSame(Contact::DISTANCE_TO_MOSCOW_STATUS_RESOLVED, $contact->distance_to_moscow_status);
    }

    public function test_job_marks_unknown_when_geocoder_returns_no_result(): void
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

        CalculateDistanceToMoscowJob::dispatchSync($contact->id);

        $contact->refresh();

        $this->assertNull($contact->distance_to_moscow_km);
        $this->assertSame(Contact::DISTANCE_TO_MOSCOW_STATUS_UNKNOWN, $contact->distance_to_moscow_status);
        $this->assertNotNull($contact->distance_to_moscow_calculated_at);
    }

    public function test_job_marks_failed_without_overwriting_existing_distance(): void
    {
        config()->set('services.yandex_geocoder.api_key', 'yandex-key');

        Http::fake([
            'https://geocode-maps.yandex.ru/1.x/*' => Http::response([], 500),
        ]);

        $contact = Contact::factory()->create([
            'country' => 'Россия',
            'city' => 'Казань',
            'distance_to_moscow_km' => 815,
            'distance_to_moscow_status' => Contact::DISTANCE_TO_MOSCOW_STATUS_RESOLVED,
            'distance_to_moscow_calculated_at' => now()->subHour(),
        ]);

        CalculateDistanceToMoscowJob::dispatchSync($contact->id);

        $contact->refresh();

        $this->assertSame(815, $contact->distance_to_moscow_km);
        $this->assertSame(Contact::DISTANCE_TO_MOSCOW_STATUS_FAILED, $contact->distance_to_moscow_status);
    }

    public function test_job_skips_stale_snapshot_when_location_changed(): void
    {
        $contact = Contact::factory()->create([
            'country' => 'Россия',
            'city' => 'Казань',
            'distance_to_moscow_km' => 815,
            'distance_to_moscow_status' => Contact::DISTANCE_TO_MOSCOW_STATUS_RESOLVED,
            'distance_to_moscow_calculated_at' => now()->subHour(),
        ]);

        $contact->forceFill([
            'city' => 'Самара',
        ])->save();

        Http::fake();

        CalculateDistanceToMoscowJob::dispatchSync($contact->id, 'Казань', 'Россия', null, null);

        $contact->refresh();

        $this->assertSame('Самара', $contact->city);
        $this->assertSame(815, $contact->distance_to_moscow_km);
        $this->assertSame(Contact::DISTANCE_TO_MOSCOW_STATUS_RESOLVED, $contact->distance_to_moscow_status);
        Http::assertNothingSent();
    }

    public function test_job_resolves_merged_contact_to_root(): void
    {
        $root = Contact::factory()->create([
            'country' => 'Россия',
            'city' => 'Москва',
        ]);
        $merged = Contact::factory()->create([
            'merged_into_contact_id' => $root->id,
            'merged_at' => now(),
            'country' => 'Россия',
            'city' => 'Москва',
        ]);

        CalculateDistanceToMoscowJob::dispatchSync($merged->id);

        $this->assertSame(0, $root->fresh()->distance_to_moscow_km);
        $this->assertSame(Contact::DISTANCE_TO_MOSCOW_STATUS_RESOLVED, $root->fresh()->distance_to_moscow_status);
        $this->assertNull($merged->fresh()->distance_to_moscow_km);
    }
}
