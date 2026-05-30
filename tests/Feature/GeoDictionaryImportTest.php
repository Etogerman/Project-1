<?php

namespace Tests\Feature;

use App\Models\GeoCity;
use App\Models\GeoCountry;
use App\Models\GeoRegion;
use App\Services\Geo\GeoCsvImportService;
use App\Services\Geo\ResolveGeoCityAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class GeoDictionaryImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_locations_import_handles_unordered_country_region_city_rows(): void
    {
        $path = $this->writeCsv('geo_locations', [
            'type;country_iso2;country_iso3;country_name_ru;country_name_en;region_code;region_name_ru;region_type;city_name_ru;city_name_en;population;lat;lon;timezone;active',
            'city;RU;;; ; ;Москва;город федерального значения;Москва;Moscow;13000000;55.7558;37.6173;Europe/Moscow;да',
            'region;RU;;; ;RU-MOW;Москва;город федерального значения;;;;;;да',
            'country;ru;rus;Россия;Russia;;;;;;;;;;да',
        ]);

        $dryRun = app(GeoCsvImportService::class)->importLocations($path, dryRun: true);

        $this->assertSame(0, $dryRun->exitCode());
        $this->assertSame(3, $dryRun->created);
        $this->assertDatabaseMissing('geo_countries', ['iso2' => 'RU']);

        $report = app(GeoCsvImportService::class)->importLocations($path);

        $this->assertSame(0, $report->exitCode());
        $this->assertSame(3, $report->created);
        $this->assertDatabaseHas('geo_countries', ['iso2' => 'RU', 'iso3' => 'RUS', 'name_ru' => 'Россия']);
        $this->assertDatabaseHas('geo_regions', ['name_ru' => 'Москва', 'code' => 'RU-MOW']);
        $this->assertDatabaseHas('geo_cities', ['name_ru' => 'Москва', 'timezone' => 'Europe/Moscow']);
    }

    public function test_locations_import_commits_valid_rows_and_reports_invalid_rows(): void
    {
        $path = $this->writeCsv('geo_locations_partial', [
            'type;country_iso2;country_iso3;country_name_ru;country_name_en;region_code;region_name_ru;region_type;city_name_ru;city_name_en;population;lat;lon;timezone;active',
            'country;RU;RUS;Россия;Russia;;;;;;;;;;да',
            'country;RUS;XXX;Ошибка;Bad;;;;;;;;;;да',
        ]);

        $report = app(GeoCsvImportService::class)->importLocations($path);

        $this->assertSame(1, $report->exitCode());
        $this->assertSame(1, $report->created);
        $this->assertSame('invalid_iso2', $report->errors[0]['code']);
        $this->assertDatabaseHas('geo_countries', ['iso2' => 'RU']);
        $this->assertDatabaseMissing('geo_countries', ['name_ru' => 'Ошибка']);
    }

    public function test_import_rejects_wrong_delimiter_without_writes(): void
    {
        $path = $this->writeCsv('geo_locations_wrong_delimiter', [
            'type,country_iso2,country_iso3,country_name_ru,country_name_en,region_code,region_name_ru,region_type,city_name_ru,city_name_en,population,lat,lon,timezone,active',
            'country,RU,RUS,Россия,Russia,,,,,,,,,,да',
        ]);

        $report = app(GeoCsvImportService::class)->importLocations($path);

        $this->assertSame(2, $report->exitCode());
        $this->assertTrue($report->fatal);
        $this->assertSame('wrong_delimiter', $report->errors[0]['code']);
        $this->assertDatabaseCount('geo_countries', 0);
    }

    public function test_alias_import_creates_alias_and_resolver_uses_it(): void
    {
        $city = $this->createMoscow();
        $path = $this->writeCsv('geo_aliases', [
            'alias;city_name_ru;region_name_ru;country_iso2;language;alias_type;confidence;auto_apply;active;comment',
            'мск;Москва;Москва;RU;ru;short;95;да;да;сокращение',
        ]);

        $report = app(GeoCsvImportService::class)->importAliases($path);

        $this->assertSame(0, $report->exitCode());
        $this->assertSame(1, $report->created);
        $this->assertDatabaseHas('geo_aliases', [
            'city_id' => $city->id,
            'normalized_alias' => 'мск',
            'confidence' => 95,
        ]);

        $resolved = app(ResolveGeoCityAction::class)->handle('я из мск');

        $this->assertSame(ResolveGeoCityAction::STATUS_MATCHED_CITY, $resolved['status']);
        $this->assertSame('Москва', $resolved['city']);
    }

    public function test_alias_import_rejects_same_alias_for_two_cities_in_one_file(): void
    {
        $this->createMoscow();
        $this->createCity('Химки', 'Московская область', 'RU-MOS');

        $path = $this->writeCsv('geo_aliases_conflict', [
            'alias;city_name_ru;region_name_ru;country_iso2;language;alias_type;confidence;auto_apply;active;comment',
            'город;Москва;Москва;RU;ru;canonical;100;да;да;',
            'город;Химки;Московская область;RU;ru;canonical;100;да;да;',
        ]);

        $report = app(GeoCsvImportService::class)->importAliases($path);

        $this->assertSame(1, $report->exitCode());
        $this->assertSame(['alias_conflict', 'alias_conflict'], array_column($report->errors, 'code'));
        $this->assertDatabaseCount('geo_aliases', 0);
    }

    public function test_geo_import_commands_use_shared_service(): void
    {
        $path = $this->writeCsv('geo_locations_command', [
            'type;country_iso2;country_iso3;country_name_ru;country_name_en;region_code;region_name_ru;region_type;city_name_ru;city_name_en;population;lat;lon;timezone;active',
            'country;RU;RUS;Россия;Russia;;;;;;;;;;да',
        ]);

        $this->artisan('geo:import-locations', [
            'file' => $path,
            '--dry-run' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseMissing('geo_countries', ['iso2' => 'RU']);

        $this->artisan('geo:import-locations', [
            'file' => $path,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('geo_countries', ['iso2' => 'RU']);
    }

    private function createMoscow(): GeoCity
    {
        return $this->createCity('Москва', 'Москва', 'RU-MOW');
    }

    private function createCity(string $cityName, string $regionName, string $regionCode): GeoCity
    {
        $country = GeoCountry::query()->firstOrCreate(
            ['iso2' => 'RU'],
            [
                'iso3' => 'RUS',
                'name_ru' => 'Россия',
                'name_en' => 'Russia',
                'normalized_name' => 'россия',
                'active' => true,
            ],
        );

        $region = GeoRegion::query()->firstOrCreate(
            [
                'country_id' => $country->id,
                'normalized_name' => mb_strtolower($regionName),
            ],
            [
                'code' => $regionCode,
                'name_ru' => $regionName,
                'active' => true,
            ],
        );

        return GeoCity::query()->firstOrCreate(
            [
                'country_id' => $country->id,
                'region_id' => $region->id,
                'normalized_name' => mb_strtolower($cityName),
            ],
            [
                'name_ru' => $cityName,
                'active' => true,
            ],
        );
    }

    /**
     * @param  list<string>  $lines
     */
    private function writeCsv(string $name, array $lines): string
    {
        $directory = storage_path('framework/testing/geo-imports');

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $path = $directory.'/'.$name.'-'.Str::uuid().'.csv';
        file_put_contents($path, implode(PHP_EOL, $lines).PHP_EOL);

        return $path;
    }
}
