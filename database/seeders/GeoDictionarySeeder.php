<?php

namespace Database\Seeders;

use App\Models\GeoAlias;
use App\Models\GeoCity;
use App\Models\GeoCountry;
use App\Models\GeoRegion;
use App\Services\Geo\GeoTextNormalizer;
use Illuminate\Database\Seeder;

class GeoDictionarySeeder extends Seeder
{
    public function run(): void
    {
        $normalizer = app(GeoTextNormalizer::class);

        $countries = [];

        foreach ($this->countries() as $country) {
            $countries[$country['iso2']] = GeoCountry::query()->updateOrCreate(
                ['iso2' => $country['iso2']],
                [
                    'iso3' => $country['iso3'],
                    'name_ru' => $country['name_ru'],
                    'name_en' => $country['name_en'],
                    'normalized_name' => $normalizer->handle($country['name_ru']),
                    'active' => true,
                ],
            );
        }

        $regions = [];

        foreach ($this->regions() as $region) {
            $country = $countries[$region['country_iso2']];

            $regions[$region['key']] = GeoRegion::query()->updateOrCreate(
                [
                    'country_id' => $country->id,
                    'normalized_name' => $normalizer->handle($region['name_ru']),
                ],
                [
                    'code' => $region['code'],
                    'name_ru' => $region['name_ru'],
                    'name_en' => $region['name_en'] ?? null,
                    'type' => $region['type'],
                    'active' => true,
                ],
            );
        }

        $cities = [];

        foreach ($this->cities() as $city) {
            $region = $regions[$city['region_key']];
            $country = $region->country;

            $cities[$city['key']] = GeoCity::query()->updateOrCreate(
                [
                    'country_id' => $country->id,
                    'region_id' => $region->id,
                    'normalized_name' => $normalizer->handle($city['name_ru']),
                ],
                [
                    'name_ru' => $city['name_ru'],
                    'name_en' => $city['name_en'] ?? null,
                    'population' => $city['population'] ?? null,
                    'lat' => $city['lat'] ?? null,
                    'lon' => $city['lon'] ?? null,
                    'timezone' => $city['timezone'] ?? null,
                    'source' => 'manual',
                    'source_id' => $city['source_id'] ?? null,
                    'active' => true,
                ],
            );
        }

        foreach ($this->aliases() as $alias) {
            $city = $cities[$alias['city_key']];

            GeoAlias::query()->updateOrCreate(
                [
                    'normalized_alias' => $normalizer->handle($alias['alias']),
                    'city_id' => $city->id,
                ],
                [
                    'alias' => $alias['alias'],
                    'language' => $alias['language'] ?? 'ru',
                    'alias_type' => $alias['alias_type'],
                    'confidence' => $alias['confidence'],
                    'auto_apply' => $alias['auto_apply'] ?? true,
                    'active' => $alias['active'] ?? true,
                    'comment' => $alias['comment'] ?? null,
                ],
            );
        }
    }

    /**
     * @return list<array{iso2: string, iso3: string, name_ru: string, name_en: string}>
     */
    private function countries(): array
    {
        return [
            ['iso2' => 'RU', 'iso3' => 'RUS', 'name_ru' => 'Россия', 'name_en' => 'Russia'],
            ['iso2' => 'BY', 'iso3' => 'BLR', 'name_ru' => 'Беларусь', 'name_en' => 'Belarus'],
            ['iso2' => 'KZ', 'iso3' => 'KAZ', 'name_ru' => 'Казахстан', 'name_en' => 'Kazakhstan'],
            ['iso2' => 'UZ', 'iso3' => 'UZB', 'name_ru' => 'Узбекистан', 'name_en' => 'Uzbekistan'],
            ['iso2' => 'AM', 'iso3' => 'ARM', 'name_ru' => 'Армения', 'name_en' => 'Armenia'],
            ['iso2' => 'GE', 'iso3' => 'GEO', 'name_ru' => 'Грузия', 'name_en' => 'Georgia'],
            ['iso2' => 'AE', 'iso3' => 'ARE', 'name_ru' => 'ОАЭ', 'name_en' => 'United Arab Emirates'],
            ['iso2' => 'TR', 'iso3' => 'TUR', 'name_ru' => 'Турция', 'name_en' => 'Turkey'],
            ['iso2' => 'RS', 'iso3' => 'SRB', 'name_ru' => 'Сербия', 'name_en' => 'Serbia'],
            ['iso2' => 'DE', 'iso3' => 'DEU', 'name_ru' => 'Германия', 'name_en' => 'Germany'],
            ['iso2' => 'PL', 'iso3' => 'POL', 'name_ru' => 'Польша', 'name_en' => 'Poland'],
            ['iso2' => 'PT', 'iso3' => 'PRT', 'name_ru' => 'Португалия', 'name_en' => 'Portugal'],
            ['iso2' => 'AR', 'iso3' => 'ARG', 'name_ru' => 'Аргентина', 'name_en' => 'Argentina'],
            ['iso2' => 'BR', 'iso3' => 'BRA', 'name_ru' => 'Бразилия', 'name_en' => 'Brazil'],
        ];
    }

    /**
     * @return list<array{key: string, country_iso2: string, code: string, name_ru: string, name_en?: string, type: string}>
     */
    private function regions(): array
    {
        return [
            ['key' => 'ru-moscow-city', 'country_iso2' => 'RU', 'code' => 'RU-MOW', 'name_ru' => 'Москва', 'type' => 'город федерального значения'],
            ['key' => 'ru-spb-city', 'country_iso2' => 'RU', 'code' => 'RU-SPE', 'name_ru' => 'Санкт-Петербург', 'type' => 'город федерального значения'],
            ['key' => 'ru-moscow-oblast', 'country_iso2' => 'RU', 'code' => 'RU-MOS', 'name_ru' => 'Московская область', 'type' => 'область'],
            ['key' => 'ru-sverdlovsk', 'country_iso2' => 'RU', 'code' => 'RU-SVE', 'name_ru' => 'Свердловская область', 'type' => 'область'],
            ['key' => 'ru-novosibirsk', 'country_iso2' => 'RU', 'code' => 'RU-NVS', 'name_ru' => 'Новосибирская область', 'type' => 'область'],
            ['key' => 'ru-nizhny-novgorod', 'country_iso2' => 'RU', 'code' => 'RU-NIZ', 'name_ru' => 'Нижегородская область', 'type' => 'область'],
            ['key' => 'by-minsk', 'country_iso2' => 'BY', 'code' => 'BY-HM', 'name_ru' => 'Минск', 'type' => 'город'],
            ['key' => 'kz-almaty', 'country_iso2' => 'KZ', 'code' => 'KZ-ALA', 'name_ru' => 'Алматы', 'type' => 'город'],
            ['key' => 'kz-astana', 'country_iso2' => 'KZ', 'code' => 'KZ-AST', 'name_ru' => 'Астана', 'type' => 'город'],
            ['key' => 'uz-tashkent', 'country_iso2' => 'UZ', 'code' => 'UZ-TAS', 'name_ru' => 'Ташкент', 'type' => 'город'],
            ['key' => 'am-yerevan', 'country_iso2' => 'AM', 'code' => 'AM-ER', 'name_ru' => 'Ереван', 'type' => 'город'],
            ['key' => 'ge-tbilisi', 'country_iso2' => 'GE', 'code' => 'GE-TB', 'name_ru' => 'Тбилиси', 'type' => 'город'],
            ['key' => 'ge-adjara', 'country_iso2' => 'GE', 'code' => 'GE-AJ', 'name_ru' => 'Аджария', 'type' => 'регион'],
            ['key' => 'ae-dubai', 'country_iso2' => 'AE', 'code' => 'AE-DU', 'name_ru' => 'Дубай', 'type' => 'эмират'],
            ['key' => 'tr-antalya', 'country_iso2' => 'TR', 'code' => 'TR-07', 'name_ru' => 'Анталья', 'type' => 'провинция'],
            ['key' => 'tr-istanbul', 'country_iso2' => 'TR', 'code' => 'TR-34', 'name_ru' => 'Стамбул', 'type' => 'провинция'],
            ['key' => 'rs-belgrade', 'country_iso2' => 'RS', 'code' => 'RS-BG', 'name_ru' => 'Белград', 'type' => 'город'],
            ['key' => 'de-berlin', 'country_iso2' => 'DE', 'code' => 'DE-BE', 'name_ru' => 'Берлин', 'type' => 'земля'],
            ['key' => 'pl-mazovia', 'country_iso2' => 'PL', 'code' => 'PL-14', 'name_ru' => 'Мазовецкое воеводство', 'type' => 'воеводство'],
            ['key' => 'pt-lisbon', 'country_iso2' => 'PT', 'code' => 'PT-11', 'name_ru' => 'Лиссабон', 'type' => 'округ'],
            ['key' => 'ar-buenos-aires', 'country_iso2' => 'AR', 'code' => 'AR-C', 'name_ru' => 'Буэнос-Айрес', 'type' => 'город'],
            ['key' => 'br-sao-paulo', 'country_iso2' => 'BR', 'code' => 'BR-SP', 'name_ru' => 'Сан-Паулу', 'type' => 'штат'],
            ['key' => 'br-rio', 'country_iso2' => 'BR', 'code' => 'BR-RJ', 'name_ru' => 'Рио-де-Жанейро', 'type' => 'штат'],
            ['key' => 'br-parana', 'country_iso2' => 'BR', 'code' => 'BR-PR', 'name_ru' => 'Парана', 'type' => 'штат'],
        ];
    }

    /**
     * @return list<array{key: string, region_key: string, name_ru: string, name_en?: string, population?: int, lat?: float, lon?: float, timezone?: string, source_id?: string}>
     */
    private function cities(): array
    {
        return [
            ['key' => 'moscow', 'region_key' => 'ru-moscow-city', 'name_ru' => 'Москва'],
            ['key' => 'spb', 'region_key' => 'ru-spb-city', 'name_ru' => 'Санкт-Петербург'],
            ['key' => 'khimki', 'region_key' => 'ru-moscow-oblast', 'name_ru' => 'Химки'],
            ['key' => 'ekb', 'region_key' => 'ru-sverdlovsk', 'name_ru' => 'Екатеринбург'],
            ['key' => 'nsk', 'region_key' => 'ru-novosibirsk', 'name_ru' => 'Новосибирск'],
            ['key' => 'nn', 'region_key' => 'ru-nizhny-novgorod', 'name_ru' => 'Нижний Новгород'],
            ['key' => 'minsk', 'region_key' => 'by-minsk', 'name_ru' => 'Минск'],
            ['key' => 'almaty', 'region_key' => 'kz-almaty', 'name_ru' => 'Алматы'],
            ['key' => 'astana', 'region_key' => 'kz-astana', 'name_ru' => 'Астана'],
            ['key' => 'tashkent', 'region_key' => 'uz-tashkent', 'name_ru' => 'Ташкент'],
            ['key' => 'yerevan', 'region_key' => 'am-yerevan', 'name_ru' => 'Ереван'],
            ['key' => 'tbilisi', 'region_key' => 'ge-tbilisi', 'name_ru' => 'Тбилиси'],
            ['key' => 'batumi', 'region_key' => 'ge-adjara', 'name_ru' => 'Батуми'],
            ['key' => 'dubai', 'region_key' => 'ae-dubai', 'name_ru' => 'Дубай'],
            ['key' => 'antalya', 'region_key' => 'tr-antalya', 'name_ru' => 'Анталья'],
            ['key' => 'istanbul', 'region_key' => 'tr-istanbul', 'name_ru' => 'Стамбул'],
            ['key' => 'belgrade', 'region_key' => 'rs-belgrade', 'name_ru' => 'Белград'],
            ['key' => 'berlin', 'region_key' => 'de-berlin', 'name_ru' => 'Берлин'],
            ['key' => 'warsaw', 'region_key' => 'pl-mazovia', 'name_ru' => 'Варшава'],
            ['key' => 'lisbon', 'region_key' => 'pt-lisbon', 'name_ru' => 'Лиссабон'],
            ['key' => 'buenos-aires', 'region_key' => 'ar-buenos-aires', 'name_ru' => 'Буэнос-Айрес'],
            ['key' => 'sao-paulo', 'region_key' => 'br-sao-paulo', 'name_ru' => 'Сан-Паулу'],
            ['key' => 'rio', 'region_key' => 'br-rio', 'name_ru' => 'Рио-де-Жанейро'],
            ['key' => 'curitiba', 'region_key' => 'br-parana', 'name_ru' => 'Куритиба'],
        ];
    }

    /**
     * @return list<array{alias: string, city_key: string, alias_type: string, confidence: int, language?: string, auto_apply?: bool, active?: bool, comment?: string}>
     */
    private function aliases(): array
    {
        return [
            ['alias' => 'москва', 'city_key' => 'moscow', 'alias_type' => GeoAlias::TYPE_CANONICAL, 'confidence' => 100],
            ['alias' => 'масква', 'city_key' => 'moscow', 'alias_type' => GeoAlias::TYPE_TYPO, 'confidence' => 90],
            ['alias' => 'мск', 'city_key' => 'moscow', 'alias_type' => GeoAlias::TYPE_SHORT, 'confidence' => 95],
            ['alias' => 'moscow', 'city_key' => 'moscow', 'alias_type' => GeoAlias::TYPE_TRANSLIT, 'confidence' => 95, 'language' => 'en'],
            ['alias' => 'москве', 'city_key' => 'moscow', 'alias_type' => GeoAlias::TYPE_CASE_FORM, 'confidence' => 90],
            ['alias' => 'санкт-петербург', 'city_key' => 'spb', 'alias_type' => GeoAlias::TYPE_CANONICAL, 'confidence' => 100],
            ['alias' => 'спб', 'city_key' => 'spb', 'alias_type' => GeoAlias::TYPE_SHORT, 'confidence' => 95],
            ['alias' => 'питер', 'city_key' => 'spb', 'alias_type' => GeoAlias::TYPE_SLANG, 'confidence' => 90],
            ['alias' => 'химки', 'city_key' => 'khimki', 'alias_type' => GeoAlias::TYPE_CANONICAL, 'confidence' => 100],
            ['alias' => 'химок', 'city_key' => 'khimki', 'alias_type' => GeoAlias::TYPE_CASE_FORM, 'confidence' => 90],
            ['alias' => 'екб', 'city_key' => 'ekb', 'alias_type' => GeoAlias::TYPE_SHORT, 'confidence' => 95],
            ['alias' => 'нск', 'city_key' => 'nsk', 'alias_type' => GeoAlias::TYPE_SHORT, 'confidence' => 95],
            ['alias' => 'нн', 'city_key' => 'nn', 'alias_type' => GeoAlias::TYPE_SHORT, 'confidence' => 95],
        ];
    }
}
