<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Services\Geo\ResolveRussianLocalityGeocodeQueryAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResolveRussianLocalityGeocodeQueryActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_action_returns_ready_query_for_unique_city(): void
    {
        config()->set('russian_region_cities.cities', [
            'санкт петербург' => [
                'city' => 'Санкт-Петербург',
                'aliases' => ['спб'],
                'regions' => ['Санкт-Петербург'],
                'geocode_query' => 'Санкт-Петербург, Россия',
            ],
        ]);

        $contact = Contact::factory()->make([
            'country' => 'Россия',
            'city' => 'СПБ',
            'region' => null,
            'region_status' => null,
        ]);

        $resolved = app(ResolveRussianLocalityGeocodeQueryAction::class)->handle($contact);

        $this->assertSame([
            'status' => 'ready',
            'query' => 'Санкт-Петербург, Россия',
            'matched_city' => 'Санкт-Петербург',
        ], $resolved);
    }

    public function test_action_returns_pending_for_ambiguous_city_without_region(): void
    {
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

        $contact = Contact::factory()->make([
            'country' => 'Россия',
            'city' => 'Михайловск',
            'region' => null,
            'region_status' => Contact::REGION_STATUS_CLARIFICATION_PENDING,
        ]);

        $resolved = app(ResolveRussianLocalityGeocodeQueryAction::class)->handle($contact);

        $this->assertSame([
            'status' => 'pending',
            'query' => null,
            'matched_city' => 'Михайловск',
        ], $resolved);
    }

    public function test_action_returns_exact_query_for_ambiguous_city_with_confirmed_region(): void
    {
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

        $contact = Contact::factory()->make([
            'country' => 'Россия',
            'city' => 'Михайловск',
            'region' => 'Ставропольский край',
            'region_status' => Contact::REGION_STATUS_RESOLVED,
        ]);

        $resolved = app(ResolveRussianLocalityGeocodeQueryAction::class)->handle($contact);

        $this->assertSame([
            'status' => 'ready',
            'query' => 'Михайловск, Ставропольский край, Россия',
            'matched_city' => 'Михайловск',
        ], $resolved);
    }

    public function test_action_returns_unknown_for_confirmed_region_without_mapping(): void
    {
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

        $contact = Contact::factory()->make([
            'country' => 'Россия',
            'city' => 'Михайловск',
            'region' => 'Ставропольский край',
            'region_status' => Contact::REGION_STATUS_RESOLVED,
        ]);

        $resolved = app(ResolveRussianLocalityGeocodeQueryAction::class)->handle($contact);

        $this->assertSame([
            'status' => 'unknown',
            'query' => null,
            'matched_city' => 'Михайловск',
        ], $resolved);
    }

    public function test_action_does_not_mix_similar_city_names(): void
    {
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
            'михайловка' => [
                'city' => 'Михайловка',
                'aliases' => [],
                'regions' => ['Волгоградская область'],
                'geocode_query' => 'Михайловка, Волгоградская область, Россия',
            ],
        ]);

        $contact = Contact::factory()->make([
            'country' => 'Россия',
            'city' => 'Михайловка',
            'region' => null,
            'region_status' => null,
        ]);

        $resolved = app(ResolveRussianLocalityGeocodeQueryAction::class)->handle($contact);

        $this->assertSame([
            'status' => 'ready',
            'query' => 'Михайловка, Волгоградская область, Россия',
            'matched_city' => 'Михайловка',
        ], $resolved);
    }
}
