<?php

namespace Tests\Feature;

use App\Services\DataCollection\ResolveRussianRegionCandidatesLookupAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResolveRussianRegionCandidatesLookupActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_action_returns_exact_city_candidates_without_mixing_similar_names(): void
    {
        config()->set('russian_region_cities.cities', [
            'михайловск' => [
                'city' => 'Михайловск',
                'aliases' => [],
                'regions' => ['Свердловская область', 'Ставропольский край'],
            ],
            'михайловка' => [
                'city' => 'Михайловка',
                'aliases' => [],
                'regions' => ['Волгоградская область', 'Приморский край', 'Воронежская область'],
            ],
        ]);

        $result = app(ResolveRussianRegionCandidatesLookupAction::class)->handle('Михайловск');

        $this->assertSame([
            'matched_city' => 'Михайловск',
            'candidate_regions' => ['Свердловская область', 'Ставропольский край'],
        ], $result);
    }

    public function test_action_returns_full_candidate_list_for_exact_match(): void
    {
        config()->set('russian_region_cities.cities', [
            'михайловка' => [
                'city' => 'Михайловка',
                'aliases' => [],
                'regions' => ['Волгоградская область', 'Приморский край', 'Воронежская область'],
            ],
        ]);

        $result = app(ResolveRussianRegionCandidatesLookupAction::class)->handle('Михайловка');

        $this->assertSame([
            'matched_city' => 'Михайловка',
            'candidate_regions' => ['Волгоградская область', 'Приморский край', 'Воронежская область'],
        ], $result);
    }

    public function test_action_returns_full_candidate_list_for_alias_match(): void
    {
        config()->set('russian_region_cities.cities', [
            'санкт петербург' => [
                'city' => 'Санкт-Петербург',
                'aliases' => ['спб', 'питер'],
                'regions' => ['Санкт-Петербург'],
            ],
        ]);

        $result = app(ResolveRussianRegionCandidatesLookupAction::class)->handle('СПБ');

        $this->assertSame([
            'matched_city' => 'Санкт-Петербург',
            'candidate_regions' => ['Санкт-Петербург'],
        ], $result);
    }

    public function test_action_returns_empty_result_for_unknown_city(): void
    {
        config()->set('russian_region_cities.cities', [
            'михайловск' => [
                'city' => 'Михайловск',
                'aliases' => [],
                'regions' => ['Свердловская область', 'Ставропольский край'],
            ],
        ]);

        $result = app(ResolveRussianRegionCandidatesLookupAction::class)->handle('Сан-Хосе');

        $this->assertSame([
            'matched_city' => null,
            'candidate_regions' => [],
        ], $result);
    }
}
