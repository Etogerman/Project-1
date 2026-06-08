<?php

namespace App\Services\Geo;

use App\Models\GeoAlias;
use App\Models\GeoCity;
use App\Models\GeoCountry;
use App\Models\GeoRegion;
use Illuminate\Support\Facades\DB;
use Throwable;

class GeoCsvImportService
{
    private const LOCATION_HEADERS = [
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
    ];

    private const ALIAS_HEADERS = [
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
    ];

    private const ALIAS_TYPES = [
        GeoAlias::TYPE_CANONICAL,
        GeoAlias::TYPE_SHORT,
        GeoAlias::TYPE_TRANSLIT,
        GeoAlias::TYPE_CASE_FORM,
        GeoAlias::TYPE_OLD_NAME,
        GeoAlias::TYPE_SLANG,
        GeoAlias::TYPE_TYPO,
        GeoAlias::TYPE_FOREIGN_NAME,
    ];

    public function __construct(
        private readonly GeoTextNormalizer $normalizer,
    ) {}

    public function importLocations(string $path, bool $dryRun = false, string $delimiter = ';'): GeoImportReport
    {
        $parsed = $this->readCsv($path, self::LOCATION_HEADERS, $delimiter, $dryRun);

        if ($parsed['report'] instanceof GeoImportReport) {
            return $parsed['report'];
        }

        $warnings = $parsed['warnings'];
        $rows = $parsed['rows'];
        $processed = count($rows);
        $errors = [];
        $skipped = 0;

        $validatedCountries = [];
        $validatedRegions = [];
        $validatedCities = [];
        $invalidCountries = [];
        $invalidRegions = [];

        foreach ($rows as $row) {
            $type = $row['type'];

            if (! in_array($type, ['country', 'region', 'city'], true)) {
                $errors[] = $this->issue($row['line'], 'invalid_type', 'Тип строки должен быть country, region или city.', ['type' => $type]);
                $skipped++;

                continue;
            }

            if ($type === 'country') {
                $country = $this->validateCountryLocationRow($row);

                if ($country['errors'] !== []) {
                    array_push($errors, ...$country['errors']);
                    $invalidCountries[$row['country_iso2'] ?? ''] = true;
                    $skipped++;

                    continue;
                }

                $validatedCountries[] = $country['data'];

                continue;
            }

            if ($type === 'region') {
                $region = $this->validateRegionLocationRow($row);

                if ($region['errors'] !== []) {
                    array_push($errors, ...$region['errors']);
                    $invalidRegions[$this->regionKey((string) ($row['country_iso2'] ?? ''), (string) ($row['region_name_ru'] ?? ''))] = true;
                    $skipped++;

                    continue;
                }

                $validatedRegions[] = $region['data'];

                continue;
            }

            $city = $this->validateCityLocationRow($row);

            if ($city['errors'] !== []) {
                array_push($errors, ...$city['errors']);
                $skipped++;

                continue;
            }

            $validatedCities[] = $city['data'];
        }

        $countryPlan = $this->planCountries($validatedCountries, $errors, $warnings, $skipped);
        $invalidCountries = array_merge($invalidCountries, $countryPlan['invalid_keys']);

        $regionPlan = $this->planRegions($validatedRegions, $countryPlan['records'], $invalidCountries, $errors, $warnings, $skipped);
        $invalidRegions = array_merge($invalidRegions, $regionPlan['invalid_keys']);

        $cityPlan = $this->planCities($validatedCities, $countryPlan['records'], $regionPlan['records'], $invalidCountries, $invalidRegions, $errors, $warnings, $skipped);

        $plans = [
            ...$countryPlan['plans'],
            ...$regionPlan['plans'],
            ...$cityPlan['plans'],
        ];

        $created = collect($plans)->where('mode', 'create')->count();
        $updated = collect($plans)->where('mode', 'update')->count();

        if (! $dryRun && $plans !== []) {
            try {
                DB::transaction(function () use ($plans): void {
                    foreach ($plans as $plan) {
                        $this->persistPlan($plan);
                    }
                });
            } catch (Throwable $exception) {
                return new GeoImportReport(
                    file: $path,
                    dryRun: false,
                    processed: $processed,
                    errors: [$this->issue(null, 'unexpected_database_error', 'База данных отклонила импорт географии.', [
                        'error' => $exception->getMessage(),
                    ])],
                    warnings: $warnings,
                    fatal: true,
                );
            }
        }

        return new GeoImportReport(
            file: $path,
            dryRun: $dryRun,
            processed: $processed,
            created: $created,
            updated: $updated,
            skipped: $skipped,
            errors: $errors,
            warnings: $warnings,
        );
    }

    public function importAliases(string $path, bool $dryRun = false, string $delimiter = ';'): GeoImportReport
    {
        $parsed = $this->readCsv($path, self::ALIAS_HEADERS, $delimiter, $dryRun);

        if ($parsed['report'] instanceof GeoImportReport) {
            return $parsed['report'];
        }

        $warnings = $parsed['warnings'];
        $rows = $parsed['rows'];
        $processed = count($rows);
        $errors = [];
        $skipped = 0;
        $validated = [];

        foreach ($rows as $row) {
            $alias = $this->validateAliasRow($row);

            if ($alias['errors'] !== []) {
                array_push($errors, ...$alias['errors']);
                $skipped++;

                continue;
            }

            $validated[] = $alias['data'];
        }

        $planResult = $this->planAliases($validated, $errors, $warnings, $skipped);
        $plans = $planResult['plans'];
        $created = collect($plans)->where('mode', 'create')->count();
        $updated = collect($plans)->where('mode', 'update')->count();

        if (! $dryRun && $plans !== []) {
            try {
                DB::transaction(function () use ($plans): void {
                    foreach ($plans as $plan) {
                        $this->persistPlan($plan);
                    }
                });
            } catch (Throwable $exception) {
                return new GeoImportReport(
                    file: $path,
                    dryRun: false,
                    processed: $processed,
                    errors: [$this->issue(null, 'unexpected_database_error', 'База данных отклонила импорт вариантов написания.', [
                        'error' => $exception->getMessage(),
                    ])],
                    warnings: $warnings,
                    fatal: true,
                );
            }
        }

        return new GeoImportReport(
            file: $path,
            dryRun: $dryRun,
            processed: $processed,
            created: $created,
            updated: $updated,
            skipped: $skipped,
            errors: $errors,
            warnings: $warnings,
        );
    }

    /**
     * @param  list<string>  $expectedHeaders
     * @return array{rows:list<array<string, mixed>>,warnings:list<array<string,mixed>>,report:?GeoImportReport}
     */
    private function readCsv(string $path, array $expectedHeaders, string $delimiter, bool $dryRun): array
    {
        if (! is_readable($path)) {
            return $this->fatalReadResult($path, $dryRun, 'file_not_readable', 'Файл CSV не найден или недоступен для чтения.');
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return $this->fatalReadResult($path, $dryRun, 'file_not_readable', 'Файл CSV не удалось прочитать.');
        }

        if (trim($contents) === '') {
            return $this->fatalReadResult($path, $dryRun, 'empty_file', 'CSV-файл пустой.');
        }

        if (! mb_check_encoding($contents, 'UTF-8')) {
            return $this->fatalReadResult($path, $dryRun, 'invalid_encoding', 'CSV-файл должен быть в кодировке UTF-8.');
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return $this->fatalReadResult($path, $dryRun, 'file_not_readable', 'Файл CSV не удалось открыть.');
        }

        try {
            $rawHeaders = fgetcsv($handle, separator: $delimiter);
        } catch (Throwable) {
            fclose($handle);

            return $this->fatalReadResult($path, $dryRun, 'invalid_header', 'Заголовок CSV не удалось прочитать.');
        }

        if (! is_array($rawHeaders)) {
            fclose($handle);

            return $this->fatalReadResult($path, $dryRun, 'empty_file', 'CSV-файл не содержит заголовок.');
        }

        $headers = array_map(fn (mixed $header): string => $this->normalizeHeader((string) $header), $rawHeaders);
        $missingHeaders = array_values(array_diff($expectedHeaders, $headers));

        if (count($headers) <= 1 && $missingHeaders !== []) {
            fclose($handle);

            return $this->fatalReadResult($path, $dryRun, 'wrong_delimiter', 'CSV выглядит как файл с другим разделителем. Нужен разделитель «;».');
        }

        if ($missingHeaders !== []) {
            fclose($handle);

            return $this->fatalReadResult($path, $dryRun, 'missing_required_header', 'В CSV не хватает обязательных заголовков.', [
                'headers' => $missingHeaders,
            ]);
        }

        $warnings = [];
        $extraHeaders = array_values(array_diff($headers, $expectedHeaders));

        if ($extraHeaders !== []) {
            $warnings[] = $this->issue(null, 'extra_columns_ignored', 'Лишние колонки CSV будут проигнорированы.', [
                'headers' => $extraHeaders,
            ]);
        }

        $rows = [];
        $line = 1;

        while (($row = fgetcsv($handle, separator: $delimiter)) !== false) {
            $line++;

            if ($this->isBlankCsvRow($row)) {
                continue;
            }

            $mapped = ['line' => $line];

            foreach ($headers as $index => $header) {
                if (! in_array($header, $expectedHeaders, true)) {
                    continue;
                }

                $mapped[$header] = $this->cleanScalar($row[$index] ?? null);
            }

            foreach ($expectedHeaders as $header) {
                $mapped[$header] ??= null;
            }

            $rows[] = $mapped;
        }

        fclose($handle);

        return [
            'rows' => $rows,
            'warnings' => $warnings,
            'report' => null,
        ];
    }

    /**
     * @return array{rows:list<array<string, mixed>>,warnings:list<array<string,mixed>>,report:GeoImportReport}
     */
    private function fatalReadResult(string $path, bool $dryRun, string $code, string $message, array $context = []): array
    {
        return [
            'rows' => [],
            'warnings' => [],
            'report' => new GeoImportReport(
                file: $path,
                dryRun: $dryRun,
                errors: [$this->issue(null, $code, $message, $context)],
                fatal: true,
            ),
        ];
    }

    /**
     * @return array{data:array<string,mixed>|null,errors:list<array<string,mixed>>}
     */
    private function validateCountryLocationRow(array $row): array
    {
        $errors = [];
        $iso2 = $this->normalizeIso2($row['country_iso2'] ?? null, $row['line'], $errors);
        $iso3 = $this->normalizeIso3($row['country_iso3'] ?? null, $row['line'], $errors);
        $nameRu = $this->requiredText($row['country_name_ru'] ?? null, $row['line'], 'country_name_required', 'Для страны нужно русское название.', $errors);
        $active = $this->normalizeBoolean($row['active'] ?? null, true, $row['line'], $errors);

        if ($errors !== []) {
            return ['data' => null, 'errors' => $errors];
        }

        return [
            'errors' => [],
            'data' => [
                'line' => $row['line'],
                'iso2' => $iso2,
                'iso3' => $iso3,
                'name_ru' => $nameRu,
                'name_en' => $row['country_name_en'] ?? null,
                'normalized_name' => $this->normalizer->handle($nameRu),
                'active' => $active,
            ],
        ];
    }

    /**
     * @return array{data:array<string,mixed>|null,errors:list<array<string,mixed>>}
     */
    private function validateRegionLocationRow(array $row): array
    {
        $errors = [];
        $iso2 = $this->normalizeIso2($row['country_iso2'] ?? null, $row['line'], $errors);
        $nameRu = $this->requiredText($row['region_name_ru'] ?? null, $row['line'], 'region_name_required', 'Для региона нужно русское название.', $errors);
        $active = $this->normalizeBoolean($row['active'] ?? null, true, $row['line'], $errors);

        if ($errors !== []) {
            return ['data' => null, 'errors' => $errors];
        }

        return [
            'errors' => [],
            'data' => [
                'line' => $row['line'],
                'country_iso2' => $iso2,
                'code' => $row['region_code'] ?? null,
                'name_ru' => $nameRu,
                'name_en' => null,
                'normalized_name' => $this->normalizer->handle($nameRu),
                'type' => $row['region_type'] ?? null,
                'active' => $active,
            ],
        ];
    }

    /**
     * @return array{data:array<string,mixed>|null,errors:list<array<string,mixed>>}
     */
    private function validateCityLocationRow(array $row): array
    {
        $errors = [];
        $iso2 = $this->normalizeIso2($row['country_iso2'] ?? null, $row['line'], $errors);
        $regionName = $this->requiredText($row['region_name_ru'] ?? null, $row['line'], 'region_name_required', 'Для города нужно название региона.', $errors);
        $cityName = $this->requiredText($row['city_name_ru'] ?? null, $row['line'], 'city_name_required', 'Для города нужно русское название.', $errors);
        $population = $this->normalizePopulation($row['population'] ?? null, $row['line'], $errors);
        $lat = $this->normalizeCoordinate($row['lat'] ?? null, -90, 90, 'invalid_lat', $row['line'], $errors);
        $lon = $this->normalizeCoordinate($row['lon'] ?? null, -180, 180, 'invalid_lon', $row['line'], $errors);
        $timezone = $this->normalizeTimezone($row['timezone'] ?? null, $row['line'], $errors);
        $active = $this->normalizeBoolean($row['active'] ?? null, true, $row['line'], $errors);

        if ($errors !== []) {
            return ['data' => null, 'errors' => $errors];
        }

        return [
            'errors' => [],
            'data' => [
                'line' => $row['line'],
                'country_iso2' => $iso2,
                'region_name_ru' => $regionName,
                'region_normalized_name' => $this->normalizer->handle($regionName),
                'name_ru' => $cityName,
                'name_en' => $row['city_name_en'] ?? null,
                'normalized_name' => $this->normalizer->handle($cityName),
                'population' => $population,
                'lat' => $lat,
                'lon' => $lon,
                'timezone' => $timezone,
                'active' => $active,
            ],
        ];
    }

    /**
     * @return array{data:array<string,mixed>|null,errors:list<array<string,mixed>>}
     */
    private function validateAliasRow(array $row): array
    {
        $errors = [];
        $alias = $this->requiredText($row['alias'] ?? null, $row['line'], 'alias_required', 'Для варианта написания нужен текст.', $errors);
        $iso2 = $this->normalizeIso2($row['country_iso2'] ?? null, $row['line'], $errors);
        $regionName = $this->requiredText($row['region_name_ru'] ?? null, $row['line'], 'region_name_required', 'Для alias нужен регион города.', $errors);
        $cityName = $this->requiredText($row['city_name_ru'] ?? null, $row['line'], 'city_name_required', 'Для alias нужен город.', $errors);
        $language = $this->normalizeLanguage($row['language'] ?? null, $row['line'], $errors);
        $aliasType = $this->normalizeAliasType($row['alias_type'] ?? null, $row['line'], $errors);
        $confidence = $this->normalizeConfidence($row['confidence'] ?? null, $row['line'], $errors);
        $autoApply = $this->normalizeBoolean($row['auto_apply'] ?? null, true, $row['line'], $errors);
        $active = $this->normalizeBoolean($row['active'] ?? null, true, $row['line'], $errors);

        if ($errors !== []) {
            return ['data' => null, 'errors' => $errors];
        }

        $country = GeoCountry::query()->where('iso2', $iso2)->first();

        if (! $country instanceof GeoCountry) {
            return ['data' => null, 'errors' => [$this->issue($row['line'], 'country_not_found', 'Страна alias не найдена.', ['country_iso2' => $iso2])]];
        }

        $region = GeoRegion::query()
            ->where('country_id', $country->id)
            ->where('normalized_name', $this->normalizer->handle($regionName))
            ->first();

        if (! $region instanceof GeoRegion) {
            return ['data' => null, 'errors' => [$this->issue($row['line'], 'region_not_found', 'Регион alias не найден.', ['region' => $regionName])]];
        }

        $city = GeoCity::query()
            ->where('country_id', $country->id)
            ->where('region_id', $region->id)
            ->where('normalized_name', $this->normalizer->handle($cityName))
            ->first();

        if (! $city instanceof GeoCity) {
            return ['data' => null, 'errors' => [$this->issue($row['line'], 'city_not_found', 'Город alias не найден.', ['city' => $cityName])]];
        }

        return [
            'errors' => [],
            'data' => [
                'line' => $row['line'],
                'alias' => $alias,
                'normalized_alias' => $this->normalizer->handle($alias),
                'city_id' => $city->id,
                'language' => $language,
                'alias_type' => $aliasType,
                'confidence' => $confidence,
                'auto_apply' => $autoApply,
                'active' => $active,
                'comment' => $row['comment'] ?? null,
            ],
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @param  list<array<string,mixed>>  $errors
     * @param  list<array<string,mixed>>  $warnings
     * @return array{plans:list<array<string,mixed>>,records:array<string,GeoCountry>,invalid_keys:array<string,bool>}
     */
    private function planCountries(array $rows, array &$errors, array &$warnings, int &$skipped): array
    {
        $plans = [];
        $records = [];
        $invalidKeys = [];

        foreach ($this->uniqueRowsByKey($rows, fn (array $row): string => (string) $row['iso2'], 'country_duplicate_conflict', $errors, $warnings, $skipped) as $row) {
            $existing = GeoCountry::query()->where('iso2', $row['iso2'])->first();

            if ($existing instanceof GeoCountry && $existing->iso3 !== $row['iso3']) {
                $errors[] = $this->issue($row['line'], 'country_iso3_conflict', 'Нельзя изменить ISO3 существующей страны.', ['iso2' => $row['iso2']]);
                $invalidKeys[$row['iso2']] = true;
                $skipped++;

                continue;
            }

            $iso3Taken = GeoCountry::query()
                ->where('iso3', $row['iso3'])
                ->when($existing instanceof GeoCountry, fn ($query) => $query->whereKeyNot($existing->id))
                ->exists();

            if ($iso3Taken) {
                $errors[] = $this->issue($row['line'], 'country_iso3_taken', 'ISO3 уже занят другой страной.', ['iso3' => $row['iso3']]);
                $invalidKeys[$row['iso2']] = true;
                $skipped++;

                continue;
            }

            $nameTaken = GeoCountry::query()
                ->where('normalized_name', $row['normalized_name'])
                ->when($existing instanceof GeoCountry, fn ($query) => $query->whereKeyNot($existing->id))
                ->exists();

            if ($nameTaken) {
                $errors[] = $this->issue($row['line'], 'country_name_taken', 'Название страны уже занято другой страной.', ['name_ru' => $row['name_ru']]);
                $invalidKeys[$row['iso2']] = true;
                $skipped++;

                continue;
            }

            $records[$row['iso2']] = $existing ?? new GeoCountry([
                'iso2' => $row['iso2'],
                'iso3' => $row['iso3'],
            ]);

            $plans[] = [
                'model' => GeoCountry::class,
                'record' => $existing,
                'mode' => $existing instanceof GeoCountry ? 'update' : 'create',
                'key' => ['iso2' => $row['iso2']],
                'data' => [
                    'iso2' => $row['iso2'],
                    'iso3' => $row['iso3'],
                    'name_ru' => $row['name_ru'],
                    'name_en' => $row['name_en'],
                    'normalized_name' => $row['normalized_name'],
                    'active' => $row['active'],
                ],
            ];
        }

        foreach (GeoCountry::query()->get() as $country) {
            $records[$country->iso2] ??= $country;
        }

        return [
            'plans' => $plans,
            'records' => $records,
            'invalid_keys' => $invalidKeys,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @param  array<string,GeoCountry>  $countries
     * @param  array<string,bool>  $invalidCountries
     * @param  list<array<string,mixed>>  $errors
     * @param  list<array<string,mixed>>  $warnings
     * @return array{plans:list<array<string,mixed>>,records:array<string,GeoRegion>,invalid_keys:array<string,bool>}
     */
    private function planRegions(array $rows, array $countries, array $invalidCountries, array &$errors, array &$warnings, int &$skipped): array
    {
        $plans = [];
        $records = [];
        $invalidKeys = [];

        foreach ($this->uniqueRowsByKey($rows, fn (array $row): string => $this->regionKey($row['country_iso2'], $row['name_ru']), 'region_duplicate_conflict', $errors, $warnings, $skipped) as $row) {
            $key = $this->regionKey($row['country_iso2'], $row['name_ru']);

            if (($invalidCountries[$row['country_iso2']] ?? false) === true) {
                $errors[] = $this->issue($row['line'], 'region_parent_invalid', 'Регион пропущен, потому что страна в этом файле не прошла проверку.', ['country_iso2' => $row['country_iso2']]);
                $invalidKeys[$key] = true;
                $skipped++;

                continue;
            }

            $country = $countries[$row['country_iso2']] ?? GeoCountry::query()->where('iso2', $row['country_iso2'])->first();

            if (! $country instanceof GeoCountry) {
                $errors[] = $this->issue($row['line'], 'country_not_found', 'Страна региона не найдена.', ['country_iso2' => $row['country_iso2']]);
                $invalidKeys[$key] = true;
                $skipped++;

                continue;
            }

            $existing = $country->exists
                ? GeoRegion::query()
                    ->where('country_id', $country->id)
                    ->where('normalized_name', $row['normalized_name'])
                    ->first()
                : null;

            if ($existing instanceof GeoRegion && filled($existing->code) && filled($row['code']) && $existing->code !== $row['code']) {
                $errors[] = $this->issue($row['line'], 'region_code_conflict', 'Нельзя изменить код существующего региона.', ['region' => $row['name_ru']]);
                $invalidKeys[$key] = true;
                $skipped++;

                continue;
            }

            if (filled($row['code'])) {
                $codeTaken = $country->exists
                    && GeoRegion::query()
                        ->where('country_id', $country->id)
                        ->where('code', $row['code'])
                        ->when($existing instanceof GeoRegion, fn ($query) => $query->whereKeyNot($existing->id))
                        ->exists();

                if ($codeTaken) {
                    $errors[] = $this->issue($row['line'], 'region_code_taken', 'Код региона уже занят другим регионом.', ['code' => $row['code']]);
                    $invalidKeys[$key] = true;
                    $skipped++;

                    continue;
                }
            }

            $records[$key] = $existing ?? new GeoRegion([
                'country_id' => $country->exists ? $country->id : null,
                'normalized_name' => $row['normalized_name'],
            ]);

            $plans[] = [
                'model' => GeoRegion::class,
                'record' => $existing,
                'mode' => $existing instanceof GeoRegion ? 'update' : 'create',
                'key' => [
                    'country_id' => $country->exists ? $country->id : null,
                    'normalized_name' => $row['normalized_name'],
                ],
                'meta' => [
                    'country_iso2' => $row['country_iso2'],
                ],
                'data' => [
                    'country_id' => $country->exists ? $country->id : null,
                    'code' => filled($existing?->code) ? $existing->code : $row['code'],
                    'name_ru' => $row['name_ru'],
                    'name_en' => $row['name_en'],
                    'normalized_name' => $row['normalized_name'],
                    'type' => $row['type'],
                    'active' => $row['active'],
                ],
            ];
        }

        foreach (GeoRegion::query()->with('country')->get() as $region) {
            if ($region->country instanceof GeoCountry) {
                $records[$this->regionKey($region->country->iso2, $region->name_ru)] ??= $region;
            }
        }

        return [
            'plans' => $plans,
            'records' => $records,
            'invalid_keys' => $invalidKeys,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @param  array<string,GeoCountry>  $countries
     * @param  array<string,GeoRegion>  $regions
     * @param  array<string,bool>  $invalidCountries
     * @param  array<string,bool>  $invalidRegions
     * @param  list<array<string,mixed>>  $errors
     * @param  list<array<string,mixed>>  $warnings
     * @return array{plans:list<array<string,mixed>>}
     */
    private function planCities(array $rows, array $countries, array $regions, array $invalidCountries, array $invalidRegions, array &$errors, array &$warnings, int &$skipped): array
    {
        $plans = [];

        foreach ($this->uniqueRowsByKey($rows, fn (array $row): string => $this->cityKey($row['country_iso2'], $row['region_name_ru'], $row['name_ru']), 'city_duplicate_conflict', $errors, $warnings, $skipped) as $row) {
            $regionKey = $this->regionKey($row['country_iso2'], $row['region_name_ru']);

            if (($invalidCountries[$row['country_iso2']] ?? false) === true || ($invalidRegions[$regionKey] ?? false) === true) {
                $errors[] = $this->issue($row['line'], 'city_parent_invalid', 'Город пропущен, потому что страна или регион в этом файле не прошли проверку.', [
                    'country_iso2' => $row['country_iso2'],
                    'region' => $row['region_name_ru'],
                ]);
                $skipped++;

                continue;
            }

            $country = $countries[$row['country_iso2']] ?? GeoCountry::query()->where('iso2', $row['country_iso2'])->first();
            $region = $regions[$regionKey] ?? null;

            if (! $country instanceof GeoCountry) {
                $errors[] = $this->issue($row['line'], 'country_not_found', 'Страна города не найдена.', ['country_iso2' => $row['country_iso2']]);
                $skipped++;

                continue;
            }

            if (! $region instanceof GeoRegion) {
                $errors[] = $this->issue($row['line'], 'region_not_found', 'Регион города не найден.', ['region' => $row['region_name_ru']]);
                $skipped++;

                continue;
            }

            $existing = $country->exists && $region->exists
                ? GeoCity::query()
                    ->where('country_id', $country->id)
                    ->where('region_id', $region->id)
                    ->where('normalized_name', $row['normalized_name'])
                    ->first()
                : null;

            $plans[] = [
                'model' => GeoCity::class,
                'record' => $existing,
                'mode' => $existing instanceof GeoCity ? 'update' : 'create',
                'key' => [
                    'country_id' => $country->exists ? $country->id : null,
                    'region_id' => $region->exists ? $region->id : null,
                    'normalized_name' => $row['normalized_name'],
                ],
                'meta' => [
                    'country_iso2' => $row['country_iso2'],
                    'region_normalized_name' => $row['region_normalized_name'],
                ],
                'data' => [
                    'country_id' => $country->exists ? $country->id : null,
                    'region_id' => $region->exists ? $region->id : null,
                    'name_ru' => $row['name_ru'],
                    'name_en' => $row['name_en'],
                    'normalized_name' => $row['normalized_name'],
                    'population' => $row['population'],
                    'lat' => $row['lat'],
                    'lon' => $row['lon'],
                    'timezone' => $row['timezone'],
                    'source' => 'csv',
                    'source_id' => null,
                    'active' => $row['active'],
                ],
            ];
        }

        return ['plans' => $plans];
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @param  list<array<string,mixed>>  $errors
     * @param  list<array<string,mixed>>  $warnings
     * @return array{plans:list<array<string,mixed>>}
     */
    private function planAliases(array $rows, array &$errors, array &$warnings, int &$skipped): array
    {
        $plans = [];
        $conflictingAliases = [];

        foreach (collect($rows)->groupBy('normalized_alias') as $normalizedAlias => $group) {
            if ($group->pluck('city_id')->unique()->count() > 1) {
                foreach ($group as $row) {
                    $errors[] = $this->issue($row['line'], 'alias_conflict', 'Один alias в файле указывает на разные города.', [
                        'alias' => $row['alias'],
                    ]);
                    $skipped++;
                }

                $conflictingAliases[(string) $normalizedAlias] = true;
            }
        }

        $cleanRows = collect($rows)
            ->reject(fn (array $row): bool => ($conflictingAliases[$row['normalized_alias']] ?? false) === true)
            ->values()
            ->all();

        foreach ($this->uniqueRowsByKey($cleanRows, fn (array $row): string => $row['normalized_alias'].'|'.$row['city_id'], 'alias_duplicate_conflict', $errors, $warnings, $skipped) as $row) {
            $targetConflict = GeoAlias::query()
                ->where('normalized_alias', $row['normalized_alias'])
                ->where('city_id', '!=', $row['city_id'])
                ->exists();

            if ($targetConflict) {
                $errors[] = $this->issue($row['line'], 'alias_target_conflict', 'Alias уже привязан к другому городу.', ['alias' => $row['alias']]);
                $skipped++;

                continue;
            }

            $existing = GeoAlias::query()
                ->where('normalized_alias', $row['normalized_alias'])
                ->where('city_id', $row['city_id'])
                ->first();

            $plans[] = [
                'model' => GeoAlias::class,
                'record' => $existing,
                'mode' => $existing instanceof GeoAlias ? 'update' : 'create',
                'key' => [
                    'normalized_alias' => $row['normalized_alias'],
                    'city_id' => $row['city_id'],
                ],
                'data' => [
                    'alias' => $row['alias'],
                    'normalized_alias' => $row['normalized_alias'],
                    'city_id' => $row['city_id'],
                    'language' => $row['language'],
                    'alias_type' => $row['alias_type'],
                    'confidence' => $row['confidence'],
                    'auto_apply' => $row['auto_apply'],
                    'active' => $row['active'],
                    'comment' => $row['comment'],
                ],
            ];
        }

        return ['plans' => $plans];
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @param  callable(array<string,mixed>):string  $keyResolver
     * @param  list<array<string,mixed>>  $errors
     * @param  list<array<string,mixed>>  $warnings
     * @return list<array<string,mixed>>
     */
    private function uniqueRowsByKey(array $rows, callable $keyResolver, string $conflictCode, array &$errors, array &$warnings, int &$skipped): array
    {
        $result = [];

        foreach (collect($rows)->groupBy($keyResolver) as $key => $group) {
            $first = $group->first();

            if ($group->count() === 1) {
                $result[] = $first;

                continue;
            }

            $uniquePayloads = $group
                ->map(fn (array $row): string => json_encode($this->comparableRow($row), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))
                ->unique();

            if ($uniquePayloads->count() === 1) {
                $result[] = $first;

                foreach ($group->slice(1) as $duplicate) {
                    $warnings[] = $this->issue($duplicate['line'], 'skipped_duplicate', 'Повторная одинаковая строка CSV пропущена.', ['key' => (string) $key]);
                    $skipped++;
                }

                continue;
            }

            foreach ($group as $row) {
                $errors[] = $this->issue($row['line'], $conflictCode, 'В CSV есть конфликтующие строки с одним ключом.', ['key' => (string) $key]);
                $skipped++;
            }
        }

        return $result;
    }

    /**
     * @param  array{model:class-string,record:mixed,mode:string,key:array<string,mixed>,data:array<string,mixed>,meta?:array<string,mixed>}  $plan
     */
    private function persistPlan(array $plan): void
    {
        $record = $plan['record'];
        $data = $this->resolvePlanData($plan);

        if ($record instanceof $plan['model']) {
            $record->fill($data)->save();

            return;
        }

        $plan['model']::query()->create($data);
    }

    /**
     * @param  array{model:class-string,record:mixed,mode:string,key:array<string,mixed>,data:array<string,mixed>,meta?:array<string,mixed>}  $plan
     * @return array<string,mixed>
     */
    private function resolvePlanData(array $plan): array
    {
        $data = $plan['data'];
        $meta = $plan['meta'] ?? [];

        if ($plan['model'] === GeoRegion::class && empty($data['country_id'])) {
            $country = GeoCountry::query()->where('iso2', $meta['country_iso2'] ?? null)->firstOrFail();
            $data['country_id'] = $country->id;
        }

        if ($plan['model'] === GeoCity::class) {
            if (empty($data['country_id'])) {
                $country = GeoCountry::query()->where('iso2', $meta['country_iso2'] ?? null)->firstOrFail();
                $data['country_id'] = $country->id;
            }

            if (empty($data['region_id'])) {
                $region = GeoRegion::query()
                    ->where('country_id', $data['country_id'])
                    ->where('normalized_name', $meta['region_normalized_name'] ?? null)
                    ->firstOrFail();
                $data['region_id'] = $region->id;
            }
        }

        return $data;
    }

    private function normalizeHeader(string $header): string
    {
        return trim(str_replace("\u{FEFF}", '', $header));
    }

    private function cleanScalar(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function isBlankCsvRow(array $row): bool
    {
        foreach ($row as $value) {
            if ($this->cleanScalar($value) !== null) {
                return false;
            }
        }

        return true;
    }

    private function normalizeIso2(mixed $value, int $line, array &$errors): ?string
    {
        $normalized = mb_strtoupper((string) $this->cleanScalar($value));

        if (! preg_match('/^[A-Z]{2}$/', $normalized)) {
            $errors[] = $this->issue($line, 'invalid_iso2', 'ISO2 должен состоять из двух латинских букв.', ['value' => $value]);

            return null;
        }

        return $normalized;
    }

    private function normalizeIso3(mixed $value, int $line, array &$errors): ?string
    {
        $normalized = mb_strtoupper((string) $this->cleanScalar($value));

        if (! preg_match('/^[A-Z]{3}$/', $normalized)) {
            $errors[] = $this->issue($line, 'invalid_iso3', 'ISO3 должен состоять из трёх латинских букв.', ['value' => $value]);

            return null;
        }

        return $normalized;
    }

    private function requiredText(mixed $value, int $line, string $code, string $message, array &$errors): ?string
    {
        $clean = $this->cleanScalar($value);

        if ($clean === null) {
            $errors[] = $this->issue($line, $code, $message);
        }

        return $clean;
    }

    private function normalizeBoolean(mixed $value, bool $default, int $line, array &$errors): bool
    {
        $clean = $this->cleanScalar($value);

        if ($clean === null) {
            return $default;
        }

        return match (mb_strtolower($clean)) {
            '1', 'true', 'да', 'yes' => true,
            '0', 'false', 'нет', 'no' => false,
            default => $this->invalidBoolean($line, $clean, $errors),
        };
    }

    private function invalidBoolean(int $line, string $value, array &$errors): bool
    {
        $errors[] = $this->issue($line, 'invalid_boolean', 'Значение должно быть да/нет, true/false или 1/0.', ['value' => $value]);

        return false;
    }

    private function normalizePopulation(mixed $value, int $line, array &$errors): ?int
    {
        $clean = $this->cleanScalar($value);

        if ($clean === null) {
            return null;
        }

        if (! preg_match('/^\d+$/', $clean)) {
            $errors[] = $this->issue($line, 'invalid_population', 'Население должно быть целым числом не меньше 0.', ['value' => $clean]);

            return null;
        }

        return (int) $clean;
    }

    private function normalizeCoordinate(mixed $value, float $min, float $max, string $code, int $line, array &$errors): ?float
    {
        $clean = $this->cleanScalar($value);

        if ($clean === null) {
            return null;
        }

        if (! is_numeric($clean) || (float) $clean < $min || (float) $clean > $max) {
            $errors[] = $this->issue($line, $code, 'Координата вне допустимого диапазона.', ['value' => $clean]);

            return null;
        }

        return (float) $clean;
    }

    private function normalizeTimezone(mixed $value, int $line, array &$errors): ?string
    {
        $clean = $this->cleanScalar($value);

        if ($clean === null) {
            return null;
        }

        if (mb_strlen($clean) > 64) {
            $errors[] = $this->issue($line, 'invalid_timezone', 'Часовой пояс должен быть не длиннее 64 символов.', ['value' => $clean]);

            return null;
        }

        return $clean;
    }

    private function normalizeLanguage(mixed $value, int $line, array &$errors): string
    {
        $clean = $this->cleanScalar($value) ?? 'ru';

        if (! preg_match('/^[a-zA-Z]{2,8}(?:-[a-zA-Z]{2,8})?$/', $clean)) {
            $errors[] = $this->issue($line, 'invalid_language', 'Язык должен быть коротким кодом вроде ru, en или pt-BR.', ['value' => $clean]);

            return 'ru';
        }

        return $clean;
    }

    private function normalizeAliasType(mixed $value, int $line, array &$errors): string
    {
        $clean = $this->cleanScalar($value) ?? GeoAlias::TYPE_CANONICAL;

        if (! in_array($clean, self::ALIAS_TYPES, true)) {
            $errors[] = $this->issue($line, 'invalid_alias_type', 'Недопустимый тип alias.', ['value' => $clean]);

            return GeoAlias::TYPE_CANONICAL;
        }

        return $clean;
    }

    private function normalizeConfidence(mixed $value, int $line, array &$errors): int
    {
        $clean = $this->cleanScalar($value);

        if ($clean === null) {
            return 100;
        }

        if (! preg_match('/^\d+$/', $clean) || (int) $clean < 1 || (int) $clean > 100) {
            $errors[] = $this->issue($line, 'invalid_confidence', 'Уверенность должна быть целым числом от 1 до 100.', ['value' => $clean]);

            return 100;
        }

        return (int) $clean;
    }

    private function regionKey(string $countryIso2, string $regionName): string
    {
        return mb_strtoupper($countryIso2).'|'.$this->normalizer->handle($regionName);
    }

    private function cityKey(string $countryIso2, string $regionName, string $cityName): string
    {
        return $this->regionKey($countryIso2, $regionName).'|'.$this->normalizer->handle($cityName);
    }

    /**
     * @return array<string,mixed>
     */
    private function comparableRow(array $row): array
    {
        $copy = $row;
        unset($copy['line']);
        ksort($copy);

        return $copy;
    }

    /**
     * @return array{line:int|null,code:string,message:string,context?:array<string,mixed>}
     */
    private function issue(?int $line, string $code, string $message, array $context = []): array
    {
        $issue = [
            'line' => $line,
            'code' => $code,
            'message' => $message,
        ];

        if ($context !== []) {
            $issue['context'] = $context;
        }

        return $issue;
    }
}
