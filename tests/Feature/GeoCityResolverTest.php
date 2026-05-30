<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\GeoAlias;
use App\Models\GeoCity;
use App\Models\GeoCountry;
use App\Models\GeoRegion;
use App\Models\GeoResolutionEvent;
use App\Services\Geo\GeoTextNormalizer;
use App\Services\Geo\ResolveAndApplyGeoCityAction;
use App\Services\Geo\ResolveGeoCityAction;
use Database\Seeders\GeoDictionarySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeoCityResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolver_matches_msk_to_moscow(): void
    {
        $this->seed(GeoDictionarySeeder::class);

        $result = app(ResolveGeoCityAction::class)->handle('я из мск');

        $this->assertSame(ResolveGeoCityAction::STATUS_MATCHED_CITY, $result['status']);
        $this->assertSame('Москва', $result['city']);
        $this->assertSame('Москва', $result['region']);
        $this->assertSame('Россия', $result['country']);
        $this->assertSame(95, $result['confidence']);
        $this->assertSame('мск', $result['matched_alias']);
    }

    public function test_resolver_matches_moscow_translit_and_khimki(): void
    {
        $this->seed(GeoDictionarySeeder::class);

        $moscow = app(ResolveGeoCityAction::class)->handle('moscow');
        $khimki = app(ResolveGeoCityAction::class)->handle('химки');

        $this->assertSame(ResolveGeoCityAction::STATUS_MATCHED_CITY, $moscow['status']);
        $this->assertSame('Москва', $moscow['city']);

        $this->assertSame(ResolveGeoCityAction::STATUS_MATCHED_CITY, $khimki['status']);
        $this->assertSame('Химки', $khimki['city']);
        $this->assertSame('Московская область', $khimki['region']);
        $this->assertSame('Россия', $khimki['country']);
    }

    public function test_resolver_does_not_match_alias_inside_another_word(): void
    {
        $this->seed(GeoDictionarySeeder::class);

        $result = app(ResolveGeoCityAction::class)->handle('омск');

        $this->assertSame(ResolveGeoCityAction::STATUS_NOT_FOUND, $result['status']);
    }

    public function test_resolver_uses_best_alias_for_same_city(): void
    {
        $this->seed(GeoDictionarySeeder::class);

        $result = app(ResolveGeoCityAction::class)->handle('я из мск, Москва');

        $this->assertSame(ResolveGeoCityAction::STATUS_MATCHED_CITY, $result['status']);
        $this->assertSame('Москва', $result['city']);
        $this->assertSame(100, $result['confidence']);
        $this->assertSame('Москва', $result['matched_alias']);
    }

    public function test_resolver_returns_ambiguous_for_multiple_different_cities(): void
    {
        $this->seed(GeoDictionarySeeder::class);

        $result = app(ResolveGeoCityAction::class)->handle('я из химок, сейчас в москве');

        $this->assertSame(ResolveGeoCityAction::STATUS_AMBIGUOUS, $result['status']);
        $this->assertCount(2, $result['payload']['candidates']);
    }

    public function test_resolver_returns_manual_required_and_below_threshold(): void
    {
        $this->seed(GeoDictionarySeeder::class);

        GeoAlias::query()->where('alias', 'питер')->update(['auto_apply' => false]);
        GeoAlias::query()->where('alias', 'екб')->update(['confidence' => 80]);

        $manual = app(ResolveGeoCityAction::class)->handle('питер');
        $belowThreshold = app(ResolveGeoCityAction::class)->handle('екб');

        $this->assertSame(ResolveGeoCityAction::STATUS_MANUAL_REQUIRED, $manual['status']);
        $this->assertSame('Санкт-Петербург', $manual['city']);

        $this->assertSame(ResolveGeoCityAction::STATUS_BELOW_THRESHOLD, $belowThreshold['status']);
        $this->assertSame('Екатеринбург', $belowThreshold['city']);
    }

    public function test_resolver_handles_inactive_alias_and_enabled_alias_priority(): void
    {
        $this->seed(GeoDictionarySeeder::class);

        GeoAlias::query()->where('alias', 'питер')->update(['active' => false]);

        $inactive = app(ResolveGeoCityAction::class)->handle('питер');

        $this->assertSame(ResolveGeoCityAction::STATUS_INACTIVE, $inactive['status']);

        $khimki = GeoCity::query()->where('name_ru', 'Химки')->firstOrFail();

        GeoAlias::query()->create([
            'alias' => 'мск',
            'normalized_alias' => app(GeoTextNormalizer::class)->handle('мск'),
            'city_id' => $khimki->id,
            'language' => 'ru',
            'alias_type' => GeoAlias::TYPE_SHORT,
            'confidence' => 100,
            'auto_apply' => true,
            'active' => false,
        ]);

        $enabledWins = app(ResolveGeoCityAction::class)->handle('мск');

        $this->assertSame(ResolveGeoCityAction::STATUS_MATCHED_CITY, $enabledWins['status']);
        $this->assertSame('Москва', $enabledWins['city']);
    }

    public function test_resolve_and_apply_updates_contact_for_matched_city_and_writes_event(): void
    {
        $this->seed(GeoDictionarySeeder::class);

        $contact = Contact::factory()->create([
            'country' => 'Старое',
            'region' => 'Старый регион',
            'city' => 'Старый город',
        ]);

        $result = app(ResolveAndApplyGeoCityAction::class)->handle($contact, 'я из мск');

        $this->assertSame(ResolveGeoCityAction::STATUS_MATCHED_CITY, $result['status']);

        $contact->refresh();

        $this->assertSame('Россия', $contact->country);
        $this->assertSame('Москва', $contact->region);
        $this->assertSame('Москва', $contact->city);

        $this->assertDatabaseHas('geo_resolution_events', [
            'contact_id' => $contact->id,
            'status' => ResolveGeoCityAction::STATUS_MATCHED_CITY,
            'country' => 'Россия',
            'region' => 'Москва',
            'city' => 'Москва',
            'confidence' => 95,
        ]);
    }

    public function test_resolve_and_apply_does_not_mutate_contact_for_non_matched_statuses(): void
    {
        $this->seed(GeoDictionarySeeder::class);

        $contact = Contact::factory()->create([
            'country' => 'Россия',
            'region' => 'Москва',
            'city' => 'Москва',
        ]);

        $result = app(ResolveAndApplyGeoCityAction::class)->handle($contact, 'омск');

        $this->assertSame(ResolveGeoCityAction::STATUS_NOT_FOUND, $result['status']);

        $contact->refresh();

        $this->assertSame('Россия', $contact->country);
        $this->assertSame('Москва', $contact->region);
        $this->assertSame('Москва', $contact->city);

        $this->assertDatabaseHas('geo_resolution_events', [
            'contact_id' => $contact->id,
            'status' => ResolveGeoCityAction::STATUS_NOT_FOUND,
        ]);
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(GeoDictionarySeeder::class);

        $counts = [
            'countries' => GeoCountry::query()->count(),
            'regions' => GeoRegion::query()->count(),
            'cities' => GeoCity::query()->count(),
            'aliases' => GeoAlias::query()->count(),
        ];

        $this->seed(GeoDictionarySeeder::class);

        $this->assertSame($counts['countries'], GeoCountry::query()->count());
        $this->assertSame($counts['regions'], GeoRegion::query()->count());
        $this->assertSame($counts['cities'], GeoCity::query()->count());
        $this->assertSame($counts['aliases'], GeoAlias::query()->count());
        $this->assertSame(0, GeoResolutionEvent::query()->count());
    }
}
