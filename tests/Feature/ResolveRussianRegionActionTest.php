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

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse([
                'status' => Contact::REGION_STATUS_RESOLVED,
                'region' => 'Московская область',
            ])),
        ]);

        $result = app(ResolveRussianRegionAction::class)->handle('Россия', 'Москва');

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
        ]);

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse([
                'status' => Contact::REGION_STATUS_CLARIFICATION_PENDING,
                'region' => null,
                'candidate_regions' => ['Волгоградская область', 'Приморский край'],
            ])),
        ]);

        $result = app(ResolveRussianRegionAction::class)->handle('Россия', 'Михайловка');

        $this->assertSame([
            'status' => Contact::REGION_STATUS_CLARIFICATION_PENDING,
            'region' => null,
            'candidate_regions' => ['Волгоградская область', 'Приморский край'],
        ], $result);
    }

    public function test_action_returns_ambiguous_for_russian_city_with_too_many_matches(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');
        config()->set('bots.data_collection.russian_region.allowed_regions', [
            'Волгоградская область',
            'Приморский край',
            'Воронежская область',
            'Тульская область',
            'Калужская область',
        ]);

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse([
                'status' => Contact::REGION_STATUS_AMBIGUOUS,
                'region' => null,
                'candidate_regions' => [],
            ])),
        ]);

        $result = app(ResolveRussianRegionAction::class)->handle('Россия', 'Михайловка');

        $this->assertSame([
            'status' => Contact::REGION_STATUS_AMBIGUOUS,
            'region' => null,
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
