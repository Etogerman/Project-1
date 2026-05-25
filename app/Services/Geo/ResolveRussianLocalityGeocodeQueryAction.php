<?php

namespace App\Services\Geo;

use App\Models\Contact;

class ResolveRussianLocalityGeocodeQueryAction
{
    /**
     * @return array{status: 'ready'|'pending'|'unknown'|'out_of_scope', query: ?string, matched_city: ?string}
     */
    public function handle(Contact $contact): array
    {
        $country = $this->normalizeNullableString($contact->country);
        $city = $this->normalizeNullableString($contact->city);
        $region = $this->normalizeNullableString($contact->region);

        if ($country === null || $city === null) {
            return $this->result('pending');
        }

        if (! $this->isRussianCountry($country)) {
            return $this->result('out_of_scope');
        }

        $entry = $this->findEntry($city);

        if ($entry === null) {
            return $this->result('ready', $city.', Россия');
        }

        $matchedCity = $this->normalizeNullableString($entry['city'] ?? null);
        $regions = $this->normalizeRegions($entry['regions'] ?? []);

        if ($matchedCity === null || $regions === []) {
            return $this->result('unknown');
        }

        if (count($regions) === 1) {
            $query = $this->normalizeNullableString($entry['geocode_query'] ?? null) ?? ($matchedCity.', Россия');

            return $this->result('ready', $query, $matchedCity);
        }

        if ($contact->region_status === Contact::REGION_STATUS_CLARIFICATION_PENDING || $region === null) {
            return $this->result('pending', null, $matchedCity);
        }

        $queriesByRegion = $this->normalizeGeocodeQueriesByRegion($entry['geocode_queries_by_region'] ?? []);
        $normalizedRegion = $this->normalizeKey($region);
        $query = $normalizedRegion === null ? null : ($queriesByRegion[$normalizedRegion] ?? null);

        if ($query === null) {
            return $this->result('unknown', null, $matchedCity);
        }

        return $this->result('ready', $query, $matchedCity);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findEntry(string $city): ?array
    {
        $normalizedCity = $this->normalizeKey($city);

        if ($normalizedCity === null) {
            return null;
        }

        $entries = config('russian_region_cities.cities', []);

        if (! is_array($entries)) {
            return null;
        }

        foreach ($entries as $key => $entry) {
            if (! is_string($key) || ! is_array($entry)) {
                continue;
            }

            if ($this->normalizeKey($key) === $normalizedCity) {
                return $entry;
            }
        }

        $matchedEntry = null;

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $aliases = is_array($entry['aliases'] ?? null) ? $entry['aliases'] : [];

            foreach ($aliases as $alias) {
                if ($this->normalizeKey($alias) !== $normalizedCity) {
                    continue;
                }

                if ($matchedEntry !== null) {
                    return null;
                }

                $matchedEntry = $entry;
            }
        }

        return $matchedEntry;
    }

    /**
     * @return list<string>
     */
    private function normalizeRegions(mixed $regions): array
    {
        $normalized = [];

        foreach ((array) $regions as $region) {
            $trimmed = $this->normalizeNullableString($region);

            if ($trimmed === null || in_array($trimmed, $normalized, true)) {
                continue;
            }

            $normalized[] = $trimmed;
        }

        return $normalized;
    }

    /**
     * @return array<string, string>
     */
    private function normalizeGeocodeQueriesByRegion(mixed $queries): array
    {
        if (! is_array($queries)) {
            return [];
        }

        $normalized = [];

        foreach ($queries as $region => $query) {
            $normalizedRegion = $this->normalizeKey($region);
            $normalizedQuery = $this->normalizeNullableString($query);

            if ($normalizedRegion === null || $normalizedQuery === null) {
                continue;
            }

            $normalized[$normalizedRegion] = $normalizedQuery;
        }

        return $normalized;
    }

    /**
     * @return array{status: 'ready'|'pending'|'unknown'|'out_of_scope', query: ?string, matched_city: ?string}
     */
    private function result(string $status, ?string $query = null, ?string $matchedCity = null): array
    {
        return [
            'status' => $status,
            'query' => $query,
            'matched_city' => $matchedCity,
        ];
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function normalizeKey(mixed $value): ?string
    {
        $normalized = $this->normalizeNullableString($value);

        if ($normalized === null) {
            return null;
        }

        $normalized = str_replace('ё', 'е', mb_strtolower($normalized));
        $normalized = preg_replace('/[-‐‑–—]+/u', ' ', $normalized);
        $normalized = preg_replace('/\s+/u', ' ', $normalized);

        if (! is_string($normalized)) {
            return null;
        }

        $normalized = trim($normalized);

        return $normalized === '' ? null : $normalized;
    }

    private function isRussianCountry(string $country): bool
    {
        $normalized = mb_strtolower(trim($country));

        return in_array($normalized, ['ru', 'rus', 'россия', 'российская федерация', 'рф', 'russia'], true);
    }
}
