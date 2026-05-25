<?php

namespace App\Services\DataCollection;

use App\Models\Contact;
use App\Services\AI\GeminiApiService;

class ResolveRussianRegionAction
{
    public function __construct(
        private readonly GeminiApiService $geminiApiService,
        private readonly ResolveRussianRegionCandidatesLookupAction $resolveRussianRegionCandidatesLookupAction,
    ) {}

    /**
     * @return array{status: string, region: ?string, candidate_regions: list<string>}
     */
    public function handle(?string $country, ?string $city): array
    {
        $normalizedCountry = $this->normalizeNullableString($country);
        $normalizedCity = $this->normalizeNullableString($city);

        if (! filled($normalizedCountry) || ! filled($normalizedCity)) {
            return [
                'status' => Contact::REGION_STATUS_UNKNOWN,
                'region' => null,
                'candidate_regions' => [],
            ];
        }

        if (! $this->isRussianCountry($normalizedCountry)) {
            return [
                'status' => Contact::REGION_STATUS_OUT_OF_SCOPE,
                'region' => null,
                'candidate_regions' => [],
            ];
        }

        $allowedRegions = array_values(array_keys(Contact::russianRegionOptions()));

        if ($allowedRegions === []) {
            return [
                'status' => Contact::REGION_STATUS_UNKNOWN,
                'region' => null,
                'candidate_regions' => [],
            ];
        }

        $lookupCandidateRegions = $this->lookupCandidateRegions($normalizedCity, $allowedRegions);

        if (count($lookupCandidateRegions) === 1) {
            return [
                'status' => Contact::REGION_STATUS_RESOLVED,
                'region' => $lookupCandidateRegions[0],
                'candidate_regions' => [],
            ];
        }

        if (count($lookupCandidateRegions) >= 2 && count($lookupCandidateRegions) <= 4) {
            return [
                'status' => Contact::REGION_STATUS_CLARIFICATION_PENDING,
                'region' => null,
                'candidate_regions' => $lookupCandidateRegions,
            ];
        }

        if (count($lookupCandidateRegions) >= 5) {
            return [
                'status' => Contact::REGION_STATUS_AMBIGUOUS,
                'region' => null,
                'candidate_regions' => $lookupCandidateRegions,
            ];
        }

        $schema = [
            'type' => 'object',
            'required' => ['status', 'region', 'candidate_regions'],
            'additionalProperties' => false,
            'properties' => [
                'status' => [
                    'type' => 'string',
                    'enum' => [
                        Contact::REGION_STATUS_RESOLVED,
                        Contact::REGION_STATUS_CLARIFICATION_PENDING,
                        Contact::REGION_STATUS_AMBIGUOUS,
                        Contact::REGION_STATUS_UNKNOWN,
                    ],
                ],
                'region' => [
                    'type' => ['string', 'null'],
                    'enum' => array_merge($allowedRegions, [null]),
                ],
                'candidate_regions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                        'enum' => $allowedRegions,
                    ],
                ],
            ],
        ];

        $systemPrompt = <<<'PROMPT'
You classify a Russian contact into exactly one canonical business region.

Rules:
- Work only for Russia. If the country is not Russia, this task should never be called.
- Use only the exact canonical region names from the allowed list.
- Do not invent new region names.
- If you can confidently map the city to one canonical region, return status "resolved".
- If the city can belong to 2, 3, or 4 Russian regions, return status "clarification_pending", region null, and include only those candidate regions.
- If there are 5 or more plausible regions, return status "ambiguous" and region null.
- If you are not confident and cannot narrow it to 2-4 candidates, return status "unknown" and region null.
- Prefer the project's business mapping over administrative geography when the examples say so.
- Project-specific business rule example: "Москва" must map to "Московская область".
PROMPT;

        $userPrompt = sprintf(
            "Country: %s\nCity: %s\nAllowed regions:\n- %s",
            $normalizedCountry,
            $normalizedCity,
            implode("\n- ", $allowedRegions),
        );

        $response = $this->geminiApiService->generateStructured($systemPrompt, $userPrompt, $schema);
        $status = $this->normalizeStatus($response['status'] ?? null);
        $region = $this->normalizeRegion($response['region'] ?? null, $allowedRegions);
        if ($status === Contact::REGION_STATUS_RESOLVED && $region !== null) {
            return [
                'status' => Contact::REGION_STATUS_RESOLVED,
                'region' => $region,
                'candidate_regions' => [],
            ];
        }

        return [
            'status' => $status === Contact::REGION_STATUS_CLARIFICATION_PENDING
                ? Contact::REGION_STATUS_UNKNOWN
                : $status,
            'region' => null,
            'candidate_regions' => [],
        ];
    }

    private function isRussianCountry(string $country): bool
    {
        $normalized = mb_strtolower(trim($country));

        return in_array($normalized, ['ru', 'россия', 'российская федерация', 'рф', 'russia'], true);
    }

    private function normalizeStatus(mixed $value): string
    {
        if (! is_string($value)) {
            return Contact::REGION_STATUS_UNKNOWN;
        }

        $normalized = trim($value);

        return in_array($normalized, [
            Contact::REGION_STATUS_RESOLVED,
            Contact::REGION_STATUS_CLARIFICATION_PENDING,
            Contact::REGION_STATUS_AMBIGUOUS,
            Contact::REGION_STATUS_UNKNOWN,
        ], true) ? $normalized : Contact::REGION_STATUS_UNKNOWN;
    }

    /**
     * @param  list<string>  $allowedRegions
     */
    private function normalizeRegion(mixed $value, array $allowedRegions): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return in_array($normalized, $allowedRegions, true) ? $normalized : null;
    }

    /**
     * @param  list<string>  $allowedRegions
     * @return list<string>
     */
    private function lookupCandidateRegions(string $city, array $allowedRegions): array
    {
        $lookup = $this->resolveRussianRegionCandidatesLookupAction->handle($city);
        $rawCandidates = $lookup['candidate_regions'] ?? null;

        if (! is_array($rawCandidates)) {
            return [];
        }

        $normalizedCandidates = [];

        foreach ($rawCandidates as $candidate) {
            if (! is_string($candidate)) {
                return [];
            }

            $trimmed = trim($candidate);

            if ($trimmed === '') {
                return [];
            }

            if (! in_array($trimmed, $allowedRegions, true)) {
                return [];
            }

            $key = $this->normalizeComparableText($trimmed);

            if (! array_key_exists($key, $normalizedCandidates)) {
                $normalizedCandidates[$key] = $trimmed;
            }
        }

        $values = array_values($normalizedCandidates);

        usort($values, fn (string $left, string $right): int => strnatcasecmp(
            $this->normalizeComparableText($left),
            $this->normalizeComparableText($right),
        ));

        return $values;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function normalizeComparableText(string $value): string
    {
        $normalized = mb_strtolower(trim($value));
        $normalized = str_replace('ё', 'е', $normalized);
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }
}
