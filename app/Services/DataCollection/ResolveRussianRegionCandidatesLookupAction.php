<?php

namespace App\Services\DataCollection;

class ResolveRussianRegionCandidatesLookupAction
{
    /**
     * @return array{matched_city: ?string, candidate_regions: list<string>}
     */
    public function handle(?string $city): array
    {
        $normalizedCity = $this->normalizeKey($city);

        if ($normalizedCity === null) {
            return [
                'matched_city' => null,
                'candidate_regions' => [],
            ];
        }

        $entries = $this->entries();

        if (array_key_exists($normalizedCity, $entries)) {
            return $this->normalizeEntry($entries[$normalizedCity]);
        }

        $matchedEntry = null;

        foreach ($entries as $entry) {
            $aliases = is_array($entry['aliases'] ?? null) ? $entry['aliases'] : [];

            foreach ($aliases as $alias) {
                if ($this->normalizeKey($alias) !== $normalizedCity) {
                    continue;
                }

                if ($matchedEntry !== null) {
                    return [
                        'matched_city' => null,
                        'candidate_regions' => [],
                    ];
                }

                $matchedEntry = $entry;
            }
        }

        return $matchedEntry === null
            ? [
                'matched_city' => null,
                'candidate_regions' => [],
            ]
            : $this->normalizeEntry($matchedEntry);
    }

    /**
     * @return array<string, array{city?: mixed, aliases?: mixed, regions?: mixed}>
     */
    private function entries(): array
    {
        $entries = config('russian_region_cities.cities', []);

        if (! is_array($entries)) {
            return [];
        }

        $normalized = [];

        foreach ($entries as $key => $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $normalizedKey = is_string($key) ? $this->normalizeKey($key) : null;

            if ($normalizedKey === null) {
                continue;
            }

            $normalized[$normalizedKey] = $entry;
        }

        return $normalized;
    }

    /**
     * @param  array{city?: mixed, aliases?: mixed, regions?: mixed}  $entry
     * @return array{matched_city: ?string, candidate_regions: list<string>}
     */
    private function normalizeEntry(array $entry): array
    {
        $city = is_string($entry['city'] ?? null) ? trim($entry['city']) : null;
        $regions = [];

        foreach ((array) ($entry['regions'] ?? []) as $region) {
            if (! is_string($region)) {
                continue;
            }

            $trimmed = trim($region);

            if ($trimmed === '' || in_array($trimmed, $regions, true)) {
                continue;
            }

            $regions[] = $trimmed;
        }

        if ($city === null || $city === '' || $regions === []) {
            return [
                'matched_city' => null,
                'candidate_regions' => [],
            ];
        }

        return [
            'matched_city' => $city,
            'candidate_regions' => $regions,
        ];
    }

    private function normalizeKey(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        if ($normalized === '') {
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
}
