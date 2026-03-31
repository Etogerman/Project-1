<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Services\DataCollection\ResolveRussianRegionAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ResolveRussianRegionActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_action_resolves_region_for_russian_city(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');
        config()->set('bots.data_collection.russian_region.allowed_regions', [
            'Московская область',
            'Республика Татарстан',
        ]);
        config()->set('russian_region_cities.cities', [
            'москва' => [
                'city' => 'Москва',
                'aliases' => [],
                'regions' => ['Московская область'],
            ],
        ]);

        Http::fake();

        $result = app(ResolveRussianRegionAction::class)->handle('Россия', 'Москва');

        Http::assertNothingSent();

        $this->assertSame([
            'status' => Contact::REGION_STATUS_RESOLVED,
            'region' => 'Московская область',
            'candidate_regions' => [],
        ], $result);
    }

    public function test_action_returns_clarification_pending_for_russian_city_with_multiple_matches(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');
        config()->set('bots.data_collection.russian_region.allowed_regions', [
            'Волгоградская область',
            'Приморский край',
            'Воронежская область',
        ]);
        config()->set('russian_region_cities.cities', [
            'михайловка' => [
                'city' => 'Михайловка',
                'aliases' => [],
                'regions' => ['Волгоградская область', 'Приморский край', 'Воронежская область'],
            ],
        ]);

        Http::fake();

        $result = app(ResolveRussianRegionAction::class)->handle('Россия', 'Михайловка');

        Http::assertNothingSent();

        $this->assertSame([
            'status' => Contact::REGION_STATUS_CLARIFICATION_PENDING,
            'region' => null,
            'candidate_regions' => ['Волгоградская область', 'Воронежская область', 'Приморский край'],
        ], $result);
    }

    public function test_action_returns_ambiguous_for_russian_city_with_too_many_lookup_matches(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');
        config()->set('bots.data_collection.russian_region.allowed_regions', [
            'Волгоградская область',
            'Приморский край',
            'Воронежская область',
            'Тульская область',
            'Калужская область',
        ]);
        config()->set('russian_region_cities.cities', [
            'александровка' => [
                'city' => 'Александровка',
                'aliases' => [],
                'regions' => [
                    'Волгоградская область',
                    'Приморский край',
                    'Воронежская область',
                    'Тульская область',
                    'Калужская область',
                ],
            ],
        ]);

        Http::fake();

        $result = app(ResolveRussianRegionAction::class)->handle('Россия', 'Александровка');

        Http::assertNothingSent();

        $this->assertSame([
            'status' => Contact::REGION_STATUS_AMBIGUOUS,
            'region' => null,
            'candidate_regions' => [
                'Волгоградская область',
                'Воронежская область',
                'Калужская область',
                'Приморский край',
                'Тульская область',
            ],
        ], $result);
    }

    public function test_action_ignores_gemini_clarification_when_lookup_has_no_exact_candidates(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');
        config()->set('bots.data_collection.russian_region.allowed_regions', [
            'Волгоградская область',
            'Приморский край',
        ]);
        config()->set('russian_region_cities.cities', []);

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse([
                'status' => Contact::REGION_STATUS_CLARIFICATION_PENDING,
                'region' => null,
                'candidate_regions' => ['Волгоградская область', 'Приморский край'],
            ])),
        ]);

        $result = app(ResolveRussianRegionAction::class)->handle('Россия', 'Михайловка');

        $this->assertSame([
            'status' => Contact::REGION_STATUS_UNKNOWN,
            'region' => null,
            'candidate_regions' => [],
        ], $result);
    }

    public function test_action_falls_back_to_gemini_for_resolved_region_when_lookup_has_no_match(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');
        config()->set('bots.data_collection.russian_region.allowed_regions', [
            'Московская область',
            'Республика Татарстан',
        ]);
        config()->set('russian_region_cities.cities', []);

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse([
                'status' => Contact::REGION_STATUS_RESOLVED,
                'region' => 'Республика Татарстан',
            ])),
        ]);

        $result = app(ResolveRussianRegionAction::class)->handle('Россия', 'Набережные Челны');

        $this->assertSame([
            'status' => Contact::REGION_STATUS_RESOLVED,
            'region' => 'Республика Татарстан',
            'candidate_regions' => [],
        ], $result);
    }

    public function test_action_returns_out_of_scope_for_non_russian_country_without_calling_gemini(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse([
                'status' => Contact::REGION_STATUS_RESOLVED,
                'region' => 'Не должно использоваться',
            ])),
        ]);

        $result = app(ResolveRussianRegionAction::class)->handle('Венгрия', 'Будапешт');

        Http::assertNothingSent();

        $this->assertSame([
            'status' => Contact::REGION_STATUS_OUT_OF_SCOPE,
            'region' => null,
            'candidate_regions' => [],
        ], $result);
    }

    public function test_action_returns_unknown_when_city_is_missing(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');

        Http::fake();

        $result = app(ResolveRussianRegionAction::class)->handle('Россия', null);

        Http::assertNothingSent();

        $this->assertSame([
            'status' => Contact::REGION_STATUS_UNKNOWN,
            'region' => null,
            'candidate_regions' => [],
        ], $result);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function geminiResponse(array $payload): array
    {
        return [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            [
                                'text' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
