<?php

namespace App\Services\Geo;

use App\Models\GeoAlias;
use Illuminate\Support\Collection;
use Throwable;

class ResolveGeoCityAction
{
    public const STATUS_MATCHED_CITY = 'matched_city';

    public const STATUS_AMBIGUOUS = 'ambiguous';

    public const STATUS_NOT_FOUND = 'not_found';

    public const STATUS_MANUAL_REQUIRED = 'manual_required';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_BELOW_THRESHOLD = 'below_threshold';

    public const STATUS_FAILED = 'failed';

    public const AUTO_APPLY_CONFIDENCE_THRESHOLD = 90;

    public function __construct(
        private readonly GeoTextNormalizer $normalizer,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(string $text): array
    {
        try {
            return $this->resolve($text);
        } catch (Throwable $throwable) {
            return [
                'status' => self::STATUS_FAILED,
                'source_text' => $text,
                'payload' => [
                    'exception_class' => $throwable::class,
                    'error' => $throwable->getMessage(),
                ],
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function resolve(string $text): array
    {
        $searchText = $this->normalizer->forMatching($text);

        if (trim($searchText) === '') {
            return [
                'status' => self::STATUS_NOT_FOUND,
                'source_text' => $text,
                'payload' => ['reason' => 'empty_text'],
            ];
        }

        $matches = $this->findMatches($text, $searchText);

        if ($matches->isEmpty()) {
            return [
                'status' => self::STATUS_NOT_FOUND,
                'source_text' => $text,
            ];
        }

        $enabledMatches = $matches
            ->filter(fn (array $match): bool => $this->isEnabledMatch($match))
            ->values();

        if ($enabledMatches->isEmpty()) {
            $bestInactive = $this->bestMatch($matches);

            return $this->resultForMatch(self::STATUS_INACTIVE, $text, $bestInactive, [
                'inactive_matches' => $this->summarizeMatches($matches),
            ]);
        }

        $cityIds = $enabledMatches
            ->pluck('alias.city_id')
            ->unique()
            ->values();

        if ($cityIds->count() > 1) {
            return [
                'status' => self::STATUS_AMBIGUOUS,
                'source_text' => $text,
                'payload' => [
                    'candidates' => $this->summarizeMatches($enabledMatches),
                ],
            ];
        }

        $bestMatch = $this->bestMatch($enabledMatches);

        if (! $bestMatch['alias']->auto_apply) {
            return $this->resultForMatch(self::STATUS_MANUAL_REQUIRED, $text, $bestMatch);
        }

        if ($bestMatch['alias']->confidence < self::AUTO_APPLY_CONFIDENCE_THRESHOLD) {
            return $this->resultForMatch(self::STATUS_BELOW_THRESHOLD, $text, $bestMatch);
        }

        return $this->resultForMatch(self::STATUS_MATCHED_CITY, $text, $bestMatch);
    }

    /**
     * @return Collection<int, array{alias: GeoAlias, position: int, matched_alias: string}>
     */
    private function findMatches(string $sourceText, string $searchText): Collection
    {
        $aliases = GeoAlias::query()
            ->with(['city.region.country'])
            ->orderByDesc('confidence')
            ->get();

        $matches = collect();

        foreach ($aliases as $alias) {
            $normalizedAlias = $this->normalizer->handle($alias->normalized_alias);

            if ($normalizedAlias === '') {
                continue;
            }

            $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($normalizedAlias, '/').'(?![\p{L}\p{N}])/u';

            if (preg_match_all($pattern, $searchText, $found, PREG_OFFSET_CAPTURE) !== false) {
                foreach ($found[0] ?? [] as $match) {
                    $matchedValue = is_string($match[0] ?? null) ? $match[0] : $normalizedAlias;
                    $position = is_int($match[1] ?? null) ? $match[1] : 0;

                    $matches->push([
                        'alias' => $alias,
                        'position' => $position,
                        'matched_alias' => substr($sourceText, $position, strlen($matchedValue)) ?: $alias->alias,
                    ]);
                }
            }
        }

        return $matches;
    }

    /**
     * @param  array{alias: GeoAlias, position: int, matched_alias: string}  $match
     */
    private function isEnabledMatch(array $match): bool
    {
        $alias = $match['alias'];
        $city = $alias->city;
        $region = $city?->region;
        $country = $city?->country;

        return $alias->active
            && $city !== null
            && $region !== null
            && $country !== null
            && $city->active
            && $region->active
            && $country->active;
    }

    /**
     * @param  Collection<int, array{alias: GeoAlias, position: int, matched_alias: string}>  $matches
     * @return array{alias: GeoAlias, position: int, matched_alias: string}
     */
    private function bestMatch(Collection $matches): array
    {
        return $matches
            ->sort(function (array $left, array $right): int {
                $confidence = $right['alias']->confidence <=> $left['alias']->confidence;

                if ($confidence !== 0) {
                    return $confidence;
                }

                $length = mb_strlen($this->normalizer->handle($right['alias']->normalized_alias))
                    <=> mb_strlen($this->normalizer->handle($left['alias']->normalized_alias));

                if ($length !== 0) {
                    return $length;
                }

                return $left['position'] <=> $right['position'];
            })
            ->first();
    }

    /**
     * @param  array{alias: GeoAlias, position: int, matched_alias: string}  $match
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function resultForMatch(string $status, string $sourceText, array $match, array $payload = []): array
    {
        $alias = $match['alias'];
        $city = $alias->city;
        $region = $city?->region;
        $country = $city?->country;

        return [
            'status' => $status,
            'source_text' => $sourceText,
            'city' => $city?->name_ru,
            'region' => $region?->name_ru,
            'country' => $country?->name_ru,
            'city_id' => $city?->id,
            'region_id' => $region?->id,
            'country_id' => $country?->id,
            'matched_alias' => $match['matched_alias'],
            'geo_alias_id' => $alias->id,
            'confidence' => $alias->confidence,
            'payload' => $payload,
        ];
    }

    /**
     * @param  Collection<int, array{alias: GeoAlias, position: int, matched_alias: string}>  $matches
     * @return list<array<string, mixed>>
     */
    private function summarizeMatches(Collection $matches): array
    {
        return $matches
            ->map(function (array $match): array {
                $alias = $match['alias'];
                $city = $alias->city;

                return [
                    'geo_alias_id' => $alias->id,
                    'matched_alias' => $match['matched_alias'],
                    'city_id' => $city?->id,
                    'city' => $city?->name_ru,
                    'confidence' => $alias->confidence,
                    'auto_apply' => $alias->auto_apply,
                    'active' => $alias->active,
                ];
            })
            ->values()
            ->all();
    }
}
