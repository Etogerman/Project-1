<?php

namespace Tests\Feature;

use App\Filament\Resources\DataDictionaryEntries\DataDictionaryEntryResource;
use App\Filament\Resources\GeoAliases\GeoAliasResource;
use App\Filament\Resources\GeoCountries\GeoCountryResource;
use App\Filament\Resources\GeoCountries\Pages\ManageGeoCountries;
use App\Filament\Resources\GeoRegions\GeoRegionResource;
use App\Models\GeoCity;
use App\Models\GeoCountry;
use App\Models\GeoRegion;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentGeoDictionaryResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    public function test_admin_can_open_geo_dictionary_and_employee_cannot(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
        ]);
        $country = GeoCountry::query()->create([
            'iso2' => 'RU',
            'iso3' => 'RUS',
            'name_ru' => 'Россия',
            'normalized_name' => 'россия',
            'active' => true,
        ]);

        $this->actingAs($admin)
            ->get(GeoCountryResource::getUrl())
            ->assertOk()
            ->assertSee('Страны')
            ->assertSee('Регионы')
            ->assertSee('Города')
            ->assertSee('Варианты')
            ->assertSee('Экспорт CSV')
            ->assertSee('Импорт CSV');

        $this->actingAs($admin)
            ->get(GeoAliasResource::getUrl())
            ->assertOk()
            ->assertSee('Экспорт CSV')
            ->assertSee('Импорт CSV');

        Livewire::actingAs($admin)
            ->test(ManageGeoCountries::class)
            ->assertCanSeeTableRecords([$country]);

        $this->assertTrue(Gate::forUser($admin)->allows('viewAny', GeoCountry::class));
        $this->assertFalse(Gate::forUser($employee)->allows('viewAny', GeoCountry::class));

        $employeeResponse = $this->actingAs($employee)
            ->get(GeoCountryResource::getUrl());

        $this->assertContains($employeeResponse->getStatusCode(), [302, 403]);
    }

    public function test_admin_can_open_names_dictionary_after_geo_navigation_changes(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        $this->actingAs($admin)
            ->get(DataDictionaryEntryResource::getUrl())
            ->assertOk()
            ->assertSee('Имена');
    }

    public function test_country_identity_fields_are_not_changed_by_update_flow(): void
    {
        $country = GeoCountry::query()->create([
            'iso2' => 'RU',
            'iso3' => 'RUS',
            'name_ru' => 'Россия',
            'normalized_name' => 'россия',
            'active' => true,
        ]);

        GeoCountryResource::updateCountry($country, [
            'iso2' => 'XX',
            'iso3' => 'XXX',
            'name_ru' => 'Российская Федерация',
            'active' => false,
        ]);

        $country->refresh();

        $this->assertSame('RU', $country->iso2);
        $this->assertSame('RUS', $country->iso3);
        $this->assertSame('Российская Федерация', $country->name_ru);
        $this->assertFalse($country->active);
    }

    public function test_region_country_and_existing_code_are_not_changed_by_update_flow(): void
    {
        $country = GeoCountry::query()->create([
            'iso2' => 'RU',
            'iso3' => 'RUS',
            'name_ru' => 'Россия',
            'normalized_name' => 'россия',
            'active' => true,
        ]);
        $otherCountry = GeoCountry::query()->create([
            'iso2' => 'BY',
            'iso3' => 'BLR',
            'name_ru' => 'Беларусь',
            'normalized_name' => 'беларусь',
            'active' => true,
        ]);
        $region = GeoRegion::query()->create([
            'country_id' => $country->id,
            'code' => 'RU-MOW',
            'name_ru' => 'Москва',
            'normalized_name' => 'москва',
            'active' => true,
        ]);

        GeoRegionResource::updateRegion($region, [
            'country_id' => $otherCountry->id,
            'code' => 'XX-NEW',
            'name_ru' => 'Москва город',
            'active' => true,
        ]);

        $region->refresh();

        $this->assertSame($country->id, $region->country_id);
        $this->assertSame('RU-MOW', $region->code);
        $this->assertSame('Москва город', $region->name_ru);
    }

    public function test_delete_policy_blocks_parent_geo_records_with_children(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $country = GeoCountry::query()->create([
            'iso2' => 'RU',
            'iso3' => 'RUS',
            'name_ru' => 'Россия',
            'normalized_name' => 'россия',
            'active' => true,
        ]);
        $region = GeoRegion::query()->create([
            'country_id' => $country->id,
            'name_ru' => 'Москва',
            'normalized_name' => 'москва',
            'active' => true,
        ]);
        GeoCity::query()->create([
            'country_id' => $country->id,
            'region_id' => $region->id,
            'name_ru' => 'Москва',
            'normalized_name' => 'москва',
            'active' => true,
        ]);

        $this->assertFalse(Gate::forUser($admin)->allows('delete', $country));
        $this->assertFalse(Gate::forUser($admin)->allows('delete', $region));
    }
}
