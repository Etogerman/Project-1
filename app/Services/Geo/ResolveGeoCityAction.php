<?php

namespace App\Services\Geo;

use App\Models\GeoAlias;
use App\Models\GeoCity;
use App\Models\GeoCountry;
use App\Models\GeoRegion;
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

    private const FUZZY_FOUND_THRESHOLD = 0.88;

    private const FUZZY_MANUAL_THRESHOLD = 0.75;

    /**
     * @var list<string>
     */
    private const STOPWORDS = [
        'я',
        'из',
        'в',
        'во',
        'живу',
        'город',
        'г',
        'рядом',
        'около',
    ];

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
        $normalizedText = $this->normalizeForLookup($text);

        if (trim($normalizedText) === '') {
            return [
                'status' => self::STATUS_NOT_FOUND,
                'source_text' => $text,
                'payload' => ['reason' => 'empty_text'],
            ];
        }

        $context = $this->resolveContext($normalizedText);
        $matches = $this->findCityMatches($text, $searchText, $normalizedText);

        if ($matches->isNotEmpty()) {
            return $this->resultForCityMatches($text, $matches, $context);
        }

        if ($context['countries']->isNotEmpty() || $context['regions']->isNotEmpty()) {
            return $this->manualContextResult($text, $context);
        }

        return $this->fuzzyResult($text, $context);
    }

    /**
     * @return array{countries: Collection<int, GeoCountry>, regions: Collection<int, GeoRegion>}
     */
    private function resolveContext(string $normalizedText): array
    {
        $countries = GeoCountry::query()
            ->where('active', true)
            ->get()
            ->filter(fn (GeoCountry $country): bool => $this->textHasAnyVariant($normalizedText, $this->countryVariants($country)))
            ->values();

        $regions = GeoRegion::query()
            ->with('country')
            ->where('active', true)
            ->get()
            ->filter(function (GeoRegion $region) use ($normalizedText, $countries): bool {
                if (! $this->textHasAnyVariant($normalizedText, $this->regionVariants($region))) {
                    return false;
                }

                return $countries->isEmpty() || $countries->contains(fn (GeoCountry $country): bool => (int) $country->id === (int) $region->country_id);
            })
            ->values();

        return [
            'countries' => $countries,
            'regions' => $regions,
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function findCityMatches(string $sourceText, string $searchText, string $normalizedText): Collection
    {
        return $this->findAliasMatches($sourceText, $searchText)
            ->concat($this->findCityNameMatches($normalizedText))
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function findAliasMatches(string $sourceText, string $searchText): Collection
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

            if (preg_match_all($pattern, $searchText, $found, PREG_OFFSET_CAPTURE) === false) {
                continue;
            }

            foreach ($found[0] ?? [] as $match) {
                $matchedValue = is_string($match[0] ?? null) ? $match[0] : $normalizedAlias;
                $position = is_int($match[1] ?? null) ? $match[1] : 0;

                $matches->push($this->candidateFromAlias(
                    alias: $alias,
                    matchedValue: substr($sourceText, $position, strlen($matchedValue)) ?: $alias->alias,
                    position: $position,
                ));
            }
        }

        return $matches;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function findCityNameMatches(string $normalizedText): Collection
    {
        $cities = GeoCity::query()
            ->with(['region.country', 'country'])
            ->get();

        $matches = collect();

        foreach ($cities as $city) {
            $normalizedName = $this->normalizer->handle($city->normalized_name ?: $city->name_ru);

            if ($normalizedName === '' || ! $this->textHasToken($normalizedText, $normalizedName)) {
                continue;
            }

            $matches->push($this->candidateFromCity(
                city: $city,
                matchedValue: $city->name_ru,
                matchType: 'city_exact',
                confidence: 100,
                score: 1.0,
            ));
        }

        return $matches;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $matches
     * @param  array{countries: Collection<int, GeoCountry>, regions: Collection<int, GeoRegion>}  $context
     * @return array<string, mixed>
     */
    private function resultForCityMatches(string $sourceText, Collection $matches, array $context): array
    {
        $enabledMatches = $matches
            ->filter(fn (array $match): bool => $this->isEnabledMatch($match))
            ->values();

        if ($enabledMatches->isEmpty()) {
            $bestInactive = $this->bestMatch($matches);

            return $this->resultForMatch(self::STATUS_INACTIVE, $sourceText, $bestInactive, [
                'reason' => 'inactive',
                'inactive_matches' => $this->summarizeCandidates($matches),
            ]);
        }

        $contextFiltered = $this->filterByContext($enabledMatches, $context);

        if ($this->hasContext($context) && $contextFiltered->isEmpty()) {
            return [
                'status' => self::STATUS_MANUAL_REQUIRED,
                'source_text' => $sourceText,
                'payload' => [
                    'reason' => 'context_mismatch',
                    'candidates' => $this->summarizeCandidates($enabledMatches),
                    'context' => $this->summarizeContext($context),
                ],
            ];
        }

        $matchesForDecision = $contextFiltered->isNotEmpty() ? $contextFiltered : $enabledMatches;
        $cityIds = $matchesForDecision
            ->pluck('city_id')
            ->unique()
            ->values();

        if ($cityIds->count() > 1) {
            return [
                'status' => self::STATUS_AMBIGUOUS,
                'source_text' => $sourceText,
                'payload' => [
                    'reason' => 'ambiguous_city',
                    'candidates' => $this->summarizeCandidates($matchesForDecision),
                    'context' => $this->summarizeContext($context),
                ],
            ];
        }

        $bestMatch = $this->bestMatch($matchesForDecision);

        if (! (bool) ($bestMatch['auto_apply'] ?? true)) {
            return $this->resultForMatch(self::STATUS_MANUAL_REQUIRED, $sourceText, $bestMatch, [
                'reason' => 'manual_required',
            ]);
        }

        if ((int) ($bestMatch['confidence'] ?? 0) < self::AUTO_APPLY_CONFIDENCE_THRESHOLD) {
            return $this->resultForMatch(self::STATUS_BELOW_THRESHOLD, $sourceText, $bestMatch, [
                'reason' => 'below_threshold',
            ]);
        }

        return $this->resultForMatch(self::STATUS_MATCHED_CITY, $sourceText, $bestMatch);
    }

    /**
     * @param  array{countries: Collection<int, GeoCountry>, regions: Collection<int, GeoRegion>}  $context
     * @return array<string, mixed>
     */
    private function manualContextResult(string $sourceText, array $context): array
    {
        $region = $context['regions']->first();
        $country = $region?->country instanceof GeoCountry
            ? $region->country
            : $context['countries']->first();

        return [
            'status' => self::STATUS_MANUAL_REQUIRED,
            'source_text' => $sourceText,
            'country' => $country?->name_ru,
            'region' => $region?->name_ru,
            'country_id' => $country?->id,
            'region_id' => $region?->id,
            'payload' => [
                'reason' => 'city_required',
                'context' => $this->summarizeContext($context),
                'candidates' => [],
            ],
        ];
    }

    /**
     * @param  array{countries: Collection<int, GeoCountry>, regions: Collection<int, GeoRegion>}  $context
     * @return array<string, mixed>
     */
    private function fuzzyResult(string $sourceText, array $context): array
    {
        $windows = $this->tokenWindows($sourceText);

        if ($windows === []) {
            return [
                'status' => self::STATUS_NOT_FOUND,
                'source_text' => $sourceText,
                'payload' => ['reason' => 'city_not_found'],
            ];
        }

        $candidates = $this->fuzzyCandidates($windows, $context);

        if ($candidates->isEmpty()) {
            return [
                'status' => self::STATUS_NOT_FOUND,
                'source_text' => $sourceText,
                'payload' => ['reason' => 'city_not_found'],
            ];
        }

        $topScore = (float) ($candidates->first()['score'] ?? 0.0);

        if ($topScore < self::FUZZY_MANUAL_THRESHOLD) {
            return [
                'status' => self::STATUS_NOT_FOUND,
                'source_text' => $sourceText,
                'payload' => [
                    'reason' => 'city_not_found',
                    'candidates' => $this->summarizeCandidates($candidates),
                ],
            ];
        }

        $topCandidates = $candidates
            ->filter(fn (array $candidate): bool => abs(((float) ($candidate['score'] ?? 0.0)) - $topScore) < 0.00001)
            ->values();
        $topCityIds = $topCandidates->pluck('city_id')->unique()->values();

        if ($topCityIds->count() > 1) {
            return [
                'status' => self::STATUS_MANUAL_REQUIRED,
                'source_text' => $sourceText,
                'payload' => [
                    'reason' => 'fuzzy_ambiguous',
                    'candidates' => $this->summarizeCandidates($candidates),
                    'context' => $this->summarizeContext($context),
                ],
            ];
        }

        $best = $candidates->first();

        if ($topScore >= self::FUZZY_FOUND_THRESHOLD) {
            return $this->resultForMatch(self::STATUS_MATCHED_CITY, $sourceText, $best, [
                'reason' => 'fuzzy_match',
            ]);
        }

        return $this->resultForMatch(self::STATUS_MANUAL_REQUIRED, $sourceText, $best, [
            'reason' => 'fuzzy_low_score',
            'candidates' => $this->summarizeCandidates($candidates),
            'context' => $this->summarizeContext($context),
        ]);
    }

    /**
     * @param  list<string>  $windows
     * @param  array{countries: Collection<int, GeoCountry>, regions: Collection<int, GeoRegion>}  $context
     * @return Collection<int, array<string, mixed>>
     */
    private function fuzzyCandidates(array $windows, array $context): Collection
    {
        $items = collect();

        GeoCity::query()
            ->with(['region.country', 'country', 'aliases'])
            ->where('active', true)
            ->get()
            ->each(function (GeoCity $city) use ($windows, $context, $items): void {
                if (! $this->cityMatchesContext($city, $context)) {
                    return;
                }

                $names = collect([$city->normalized_name, $city->name_ru])
                    ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
                    ->map(fn (string $value): string => $this->normalizeForFuzzy($value));

                foreach ($city->aliases as $alias) {
                    if (! $alias instanceof GeoAlias || ! $alias->active) {
                        continue;
                    }

                    $names->push($this->normalizeForFuzzy($alias->normalized_alias ?: $alias->alias));
                }

                $best = null;

                foreach ($names->unique()->filter()->values() as $candidateName) {
                    if (mb_strlen($candidateName) < 4) {
                        continue;
                    }

                    foreach ($windows as $window) {
                        $score = $this->similarity($window, $candidateName);

                        if ($best === null || $score > $best['score']) {
                            $best = [
                                'score' => $score,
                                'matched_value' => $window,
                                'candidate_name' => $candidateName,
                            ];
                        }
                    }
                }

                if (! is_array($best) || (float) $best['score'] < self::FUZZY_MANUAL_THRESHOLD) {
                    return;
                }

                $items->push($this->candidateFromCity(
                    city: $city,
                    matchedValue: (string) $best['matched_value'],
                    matchType: 'fuzzy',
                    confidence: (int) round(((float) $best['score']) * 100),
                    score: (float) $best['score'],
                    payload: [
                        'candidate_name' => (string) $best['candidate_name'],
                    ],
                ));
            });

        return $items
            ->sort(function (array $left, array $right): int {
                $score = ((float) ($right['score'] ?? 0.0)) <=> ((float) ($left['score'] ?? 0.0));

                if ($score !== 0) {
                    return $score;
                }

                $population = ((int) ($right['population'] ?? 0)) <=> ((int) ($left['population'] ?? 0));

                if ($population !== 0) {
                    return $population;
                }

                return ((int) ($left['city_id'] ?? 0)) <=> ((int) ($right['city_id'] ?? 0));
            })
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $matches
     * @param  array{countries: Collection<int, GeoCountry>, regions: Collection<int, GeoRegion>}  $context
     * @return Collection<int, array<string, mixed>>
     */
    private function filterByContext(Collection $matches, array $context): Collection
    {
        if (! $this->hasContext($context)) {
            return $matches->values();
        }

        return $matches
            ->filter(fn (array $match): bool => $this->candidateMatchesContext($match, $context))
            ->values();
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array{countries: Collection<int, GeoCountry>, regions: Collection<int, GeoRegion>}  $context
     */
    private function candidateMatchesContext(array $candidate, array $context): bool
    {
        if ($context['regions']->isNotEmpty()) {
            $regionIds = $context['regions']->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

            if (! in_array((int) ($candidate['region_id'] ?? 0), $regionIds, true)) {
                return false;
            }
        }

        if ($context['countries']->isNotEmpty()) {
            $countryIds = $context['countries']->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

            if (! in_array((int) ($candidate['country_id'] ?? 0), $countryIds, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array{countries: Collection<int, GeoCountry>, regions: Collection<int, GeoRegion>}  $context
     */
    private function cityMatchesContext(GeoCity $city, array $context): bool
    {
        return $this->candidateMatchesContext([
            'country_id' => $city->country_id,
            'region_id' => $city->region_id,
        ], $context);
    }

    /**
     * @param  array<string, mixed>  $match
     */
    private function isEnabledMatch(array $match): bool
    {
        return (bool) ($match['active'] ?? false);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $matches
     * @return array<string, mixed>
     */
    private function bestMatch(Collection $matches): array
    {
        return $matches
            ->sort(function (array $left, array $right): int {
                $length = mb_strlen($this->normalizeForLookup((string) ($right['matched_alias'] ?? $right['matched_value'] ?? '')))
                    <=> mb_strlen($this->normalizeForLookup((string) ($left['matched_alias'] ?? $left['matched_value'] ?? '')));

                if ($length !== 0) {
                    return $length;
                }

                $confidence = ((int) ($right['confidence'] ?? 0)) <=> ((int) ($left['confidence'] ?? 0));

                if ($confidence !== 0) {
                    return $confidence;
                }

                return ((int) ($left['position'] ?? 0)) <=> ((int) ($right['position'] ?? 0));
            })
            ->first();
    }

    /**
     * @param  array<string, mixed>  $match
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function resultForMatch(string $status, string $sourceText, array $match, array $payload = []): array
    {
        $basePayload = is_array($match['payload'] ?? null) ? $match['payload'] : [];

        return [
            'status' => $status,
            'source_text' => $sourceText,
            'city' => $match['city'] ?? null,
            'region' => $match['region'] ?? null,
            'country' => $match['country'] ?? null,
            'city_id' => $match['city_id'] ?? null,
            'region_id' => $match['region_id'] ?? null,
            'country_id' => $match['country_id'] ?? null,
            'matched_alias' => $match['matched_alias'] ?? $match['matched_value'] ?? null,
            'geo_alias_id' => $match['geo_alias_id'] ?? null,
            'confidence' => $match['confidence'] ?? null,
            'payload' => array_merge($basePayload, $payload),
        ];
    }

    private function candidateFromAlias(GeoAlias $alias, string $matchedValue, int $position): array
    {
        $city = $alias->city;

        return $this->candidateFromCity(
            city: $city,
            matchedValue: $matchedValue,
            matchType: 'alias',
            confidence: (int) $alias->confidence,
            score: ((int) $alias->confidence) / 100,
            position: $position,
            geoAliasId: $alias->id,
            autoApply: (bool) $alias->auto_apply,
            aliasActive: (bool) $alias->active,
        );
    }

    private function candidateFromCity(
        ?GeoCity $city,
        string $matchedValue,
        string $matchType,
        int $confidence,
        float $score,
        int $position = 0,
        ?int $geoAliasId = null,
        bool $autoApply = true,
        bool $aliasActive = true,
        array $payload = [],
    ): array {
        $region = $city?->region;
        $country = $city?->country;

        return [
            'city_id' => $city?->id,
            'region_id' => $region?->id,
            'country_id' => $country?->id,
            'city' => $city?->name_ru,
            'region' => $region?->name_ru,
            'country' => $country?->name_ru,
            'population' => (int) ($city?->population ?? 0),
            'matched_alias' => $matchedValue,
            'matched_value' => $matchedValue,
            'geo_alias_id' => $geoAliasId,
            'confidence' => $confidence,
            'score' => $score,
            'auto_apply' => $autoApply,
            'active' => $aliasActive
                && $city instanceof GeoCity
                && $region instanceof GeoRegion
                && $country instanceof GeoCountry
                && $city->active
                && $region->active
                && $country->active,
            'position' => $position,
            'match_type' => $matchType,
            'payload' => $payload,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $matches
     * @return list<array<string, mixed>>
     */
    private function summarizeCandidates(Collection $matches): array
    {
        return $matches
            ->unique(fn (array $match): string => implode('|', [
                (string) ($match['city_id'] ?? ''),
                (string) ($match['region_id'] ?? ''),
                (string) ($match['country_id'] ?? ''),
                (string) ($match['matched_alias'] ?? ''),
            ]))
            ->take(5)
            ->map(fn (array $match): array => [
                'geo_alias_id' => $match['geo_alias_id'] ?? null,
                'matched_alias' => $match['matched_alias'] ?? null,
                'city_id' => $match['city_id'] ?? null,
                'city' => $match['city'] ?? null,
                'region_id' => $match['region_id'] ?? null,
                'region' => $match['region'] ?? null,
                'country_id' => $match['country_id'] ?? null,
                'country' => $match['country'] ?? null,
                'confidence' => $match['confidence'] ?? null,
                'score' => $match['score'] ?? null,
                'auto_apply' => $match['auto_apply'] ?? null,
                'active' => $match['active'] ?? null,
                'match_type' => $match['match_type'] ?? null,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array{countries: Collection<int, GeoCountry>, regions: Collection<int, GeoRegion>}  $context
     * @return array<string, mixed>
     */
    private function summarizeContext(array $context): array
    {
        return [
            'countries' => $context['countries']
                ->map(fn (GeoCountry $country): array => [
                    'id' => $country->id,
                    'country' => $country->name_ru,
                    'iso2' => $country->iso2,
                ])
                ->values()
                ->all(),
            'regions' => $context['regions']
                ->map(fn (GeoRegion $region): array => [
                    'id' => $region->id,
                    'region' => $region->name_ru,
                    'country_id' => $region->country_id,
                    'country' => $region->country?->name_ru,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array{countries: Collection<int, GeoCountry>, regions: Collection<int, GeoRegion>}  $context
     */
    private function hasContext(array $context): bool
    {
        return $context['countries']->isNotEmpty() || $context['regions']->isNotEmpty();
    }

    /**
     * @return list<string>
     */
    private function countryVariants(GeoCountry $country): array
    {
        $name = $this->normalizeForLookup($country->name_ru);

        return array_values(array_unique(array_filter([
            $name,
            $this->genitiveCountryVariant($name),
            $this->normalizeForLookup($country->iso2),
            $this->normalizeForLookup($country->iso3),
        ])));
    }

    /**
     * @return list<string>
     */
    private function regionVariants(GeoRegion $region): array
    {
        $name = $this->normalizeForLookup($region->name_ru);
        $variants = [$name];

        if (str_ends_with($name, 'ская область')) {
            $variants[] = mb_substr($name, 0, -mb_strlen('ская область')).'ской области';
        }

        if (str_ends_with($name, 'ая область')) {
            $variants[] = mb_substr($name, 0, -mb_strlen('ая область')).'ой области';
        }

        return array_values(array_unique(array_filter($variants)));
    }

    private function genitiveCountryVariant(string $name): ?string
    {
        if (str_ends_with($name, 'ия')) {
            return mb_substr($name, 0, -1).'и';
        }

        if (str_ends_with($name, 'стан')) {
            return $name.'а';
        }

        if ($name === 'оаэ') {
            return 'оаэ';
        }

        return null;
    }

    /**
     * @param  list<string>  $variants
     */
    private function textHasAnyVariant(string $normalizedText, array $variants): bool
    {
        foreach ($variants as $variant) {
            if ($this->textHasToken($normalizedText, $variant)) {
                return true;
            }
        }

        return false;
    }

    private function textHasToken(string $normalizedText, string $token): bool
    {
        if ($token === '') {
            return false;
        }

        return preg_match('/(?<![\p{L}\p{N}])'.preg_quote($token, '/').'(?![\p{L}\p{N}])/u', $normalizedText) === 1;
    }

    /**
     * @return list<string>
     */
    private function tokenWindows(string $text): array
    {
        $normalized = $this->normalizeForFuzzy($text);
        $tokens = preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = array_values(array_filter(
            $tokens,
            fn (string $token): bool => ! in_array($token, self::STOPWORDS, true),
        ));
        $windows = [];
        $count = count($tokens);

        for ($start = 0; $start < $count; $start++) {
            for ($length = 1; $length <= 3; $length++) {
                if ($start + $length > $count) {
                    continue;
                }

                $windows[] = implode(' ', array_slice($tokens, $start, $length));
            }
        }

        return array_values(array_unique(array_filter($windows)));
    }

    private function normalizeForLookup(?string $value): string
    {
        $normalized = $this->normalizer->handle($value);
        $normalized = str_replace(['-', '‐', '‑', '‒', '–', '—'], ' ', $normalized);
        $normalized = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }

    private function normalizeForFuzzy(?string $value): string
    {
        $normalized = $this->normalizeForLookup($value);
        $normalized = str_replace('moscow', 'москва', $normalized);
        $normalized = str_replace('sankt peterburg', 'санкт петербург', $normalized);
        $normalized = str_replace('saint petersburg', 'санкт петербург', $normalized);

        return trim($normalized);
    }

    private function similarity(string $left, string $right): float
    {
        if ($left === '' || $right === '') {
            return 0.0;
        }

        if ($left === $right) {
            return 1.0;
        }

        $maxLength = max(mb_strlen($left), mb_strlen($right));

        if ($maxLength <= 0) {
            return 0.0;
        }

        return max(0.0, 1 - ($this->multibyteLevenshtein($left, $right) / $maxLength));
    }

    private function multibyteLevenshtein(string $left, string $right): int
    {
        $leftChars = preg_split('//u', $left, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $rightChars = preg_split('//u', $right, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $leftCount = count($leftChars);
        $rightCount = count($rightChars);

        if ($leftCount === 0) {
            return $rightCount;
        }

        if ($rightCount === 0) {
            return $leftCount;
        }

        $previous = range(0, $rightCount);

        for ($i = 1; $i <= $leftCount; $i++) {
            $current = [$i];

            for ($j = 1; $j <= $rightCount; $j++) {
                $cost = $leftChars[$i - 1] === $rightChars[$j - 1] ? 0 : 1;
                $current[$j] = min(
                    $current[$j - 1] + 1,
                    $previous[$j] + 1,
                    $previous[$j - 1] + $cost,
                );
            }

            $previous = $current;
        }

        return (int) $previous[$rightCount];
    }
}
