<?php

namespace App\Services\Geo;

use App\Models\GeoAlias;
use App\Models\GeoCity;
use App\Models\GeoCountry;
use App\Models\GeoRegion;
use League\Csv\Writer;

class GeoCsvExportService
{
    public function exportLocations(): string
    {
        $csv = Writer::fromString('');
        $csv->setDelimiter(';');
        $csv->insertOne([
            'type',
            'country_iso2',
            'country_iso3',
            'country_name_ru',
            'country_name_en',
            'region_code',
            'region_name_ru',
            'region_type',
            'city_name_ru',
            'city_name_en',
            'population',
            'lat',
            'lon',
            'timezone',
            'active',
        ]);

        GeoCountry::query()
            ->orderBy('iso2')
            ->each(function (GeoCountry $country) use ($csv): void {
                $csv->insertOne([
                    'country',
                    $country->iso2,
                    $country->iso3,
                    $country->name_ru,
                    $country->name_en,
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    $this->booleanLabel($country->active),
                ]);
            });

        GeoRegion::query()
            ->with('country')
            ->join('geo_countries', 'geo_regions.country_id', '=', 'geo_countries.id')
            ->orderBy('geo_countries.iso2')
            ->orderBy('geo_regions.name_ru')
            ->select('geo_regions.*')
            ->each(function (GeoRegion $region) use ($csv): void {
                $csv->insertOne([
                    'region',
                    $region->country?->iso2,
                    '',
                    '',
                    '',
                    $region->code,
                    $region->name_ru,
                    $region->type,
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    $this->booleanLabel($region->active),
                ]);
            });

        GeoCity::query()
            ->with(['country', 'region'])
            ->join('geo_countries', 'geo_cities.country_id', '=', 'geo_countries.id')
            ->join('geo_regions', 'geo_cities.region_id', '=', 'geo_regions.id')
            ->orderBy('geo_countries.iso2')
            ->orderBy('geo_regions.name_ru')
            ->orderBy('geo_cities.name_ru')
            ->select('geo_cities.*')
            ->each(function (GeoCity $city) use ($csv): void {
                $csv->insertOne([
                    'city',
                    $city->country?->iso2,
                    '',
                    '',
                    '',
                    $city->region?->code,
                    $city->region?->name_ru,
                    $city->region?->type,
                    $city->name_ru,
                    $city->name_en,
                    $city->population,
                    $city->lat,
                    $city->lon,
                    $city->timezone,
                    $this->booleanLabel($city->active),
                ]);
            });

        return "\xEF\xBB\xBF".$csv->toString();
    }

    public function exportAliases(): string
    {
        $csv = Writer::fromString('');
        $csv->setDelimiter(';');
        $csv->insertOne([
            'alias',
            'city_name_ru',
            'region_name_ru',
            'country_iso2',
            'language',
            'alias_type',
            'confidence',
            'auto_apply',
            'active',
            'comment',
        ]);

        GeoAlias::query()
            ->with(['city.country', 'city.region'])
            ->join('geo_cities', 'geo_aliases.city_id', '=', 'geo_cities.id')
            ->join('geo_regions', 'geo_cities.region_id', '=', 'geo_regions.id')
            ->join('geo_countries', 'geo_cities.country_id', '=', 'geo_countries.id')
            ->orderBy('geo_countries.iso2')
            ->orderBy('geo_regions.name_ru')
            ->orderBy('geo_cities.name_ru')
            ->orderBy('geo_aliases.alias')
            ->select('geo_aliases.*')
            ->each(function (GeoAlias $alias) use ($csv): void {
                $city = $alias->city;

                $csv->insertOne([
                    $alias->alias,
                    $city?->name_ru,
                    $city?->region?->name_ru,
                    $city?->country?->iso2,
                    $alias->language,
                    $alias->alias_type,
                    $alias->confidence,
                    $this->booleanLabel($alias->auto_apply),
                    $this->booleanLabel($alias->active),
                    $alias->comment,
                ]);
            });

        return "\xEF\xBB\xBF".$csv->toString();
    }

    private function booleanLabel(bool $value): string
    {
        return $value ? 'да' : 'нет';
    }
}
