<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24ActiveDealLookupResultData;
use App\Models\Contact;
use App\Services\Contacts\ResolveRootContactAction;

class FindActiveBitrix24DealsForContactAction
{
    /**
     * @var list<string>
     */
    private const DEAL_SELECT_FIELDS = [
        'ID',
        'TITLE',
        'CATEGORY_ID',
        'STAGE_ID',
        'CLOSED',
        'ASSIGNED_BY_ID',
        'SOURCE_ID',
    ];

    public function __construct(
        private readonly ResolveRootContactAction $resolveRootContactAction,
        private readonly Bitrix24ApiClient $apiClient,
    ) {}

    public function handle(Contact|int $contact): Bitrix24ActiveDealLookupResultData
    {
        $rootContact = $this->resolveRootContactAction->handle($contact);
        $bitrix24ContactId = $this->requireBitrix24ContactId($rootContact);

        $start = null;
        $pagesFetched = 0;
        $deals = [];

        do {
            $response = $this->apiClient->call('crm.deal.list', $this->buildLookupParams($bitrix24ContactId, $start));

            if (! $response->successful || ! is_array($response->result)) {
                throw new Bitrix24ApiException(
                    sprintf('Bitrix24 active deal lookup failed for contact #%d.', $rootContact->id),
                );
            }

            foreach ($response->result as $deal) {
                if (! is_array($deal)) {
                    throw new Bitrix24ApiException(
                        sprintf('Bitrix24 active deal lookup returned a malformed deal payload for contact #%d.', $rootContact->id),
                    );
                }

                $deals[] = $this->normalizeDeal($deal, $rootContact);
            }

            $pagesFetched++;
            $start = $this->extractNextStart($response->raw, $rootContact);
        } while ($start !== null);

        $dealIds = array_map(
            static fn (array $deal): string => $deal['id'],
            $deals,
        );

        return new Bitrix24ActiveDealLookupResultData(
            contactId: $rootContact->id,
            bitrix24ContactId: $bitrix24ContactId,
            deals: $deals,
            dealIds: $dealIds,
            smallestDealId: $this->resolveSmallestDealId($dealIds),
            pagesFetched: $pagesFetched,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildLookupParams(string $bitrix24ContactId, ?int $start): array
    {
        $params = [
            'filter' => [
                'CONTACT_ID' => $bitrix24ContactId,
                'CLOSED' => 'N',
            ],
            'select' => self::DEAL_SELECT_FIELDS,
            'order' => [
                'ID' => 'ASC',
            ],
        ];

        if ($start !== null) {
            $params['start'] = $start;
        }

        return $params;
    }

    /**
     * @param  array<string, mixed>  $deal
     * @return array{id: string, title: ?string, category_id: ?int, stage_id: ?string, closed: bool, assigned_user_id: ?int, source_id: ?string}
     */
    private function normalizeDeal(array $deal, Contact $contact): array
    {
        $id = $this->normalizeRequiredNumericString($deal['ID'] ?? null);

        if ($id === null) {
            throw new Bitrix24ApiException(
                sprintf('Bitrix24 active deal lookup returned a deal without a valid ID for contact #%d.', $contact->id),
            );
        }

        $closed = $this->normalizeClosedValue($deal['CLOSED'] ?? null);

        if ($closed === null || $closed === true) {
            throw new Bitrix24ApiException(
                sprintf('Bitrix24 active deal lookup returned a malformed CLOSED flag for deal `%s` and contact #%d.', $id, $contact->id),
            );
        }

        return [
            'id' => $id,
            'title' => $this->nullableString($deal['TITLE'] ?? null),
            'category_id' => $this->nullableInteger($deal['CATEGORY_ID'] ?? null),
            'stage_id' => $this->nullableString($deal['STAGE_ID'] ?? null),
            'closed' => false,
            'assigned_user_id' => $this->nullableInteger($deal['ASSIGNED_BY_ID'] ?? null),
            'source_id' => $this->nullableString($deal['SOURCE_ID'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function extractNextStart(array $raw, Contact $contact): ?int
    {
        if (! array_key_exists('next', $raw)) {
            return null;
        }

        $next = $raw['next'];

        if ($next === null) {
            return null;
        }

        if (! is_scalar($next)) {
            throw new Bitrix24ApiException(
                sprintf('Bitrix24 active deal lookup returned a malformed pagination cursor for contact #%d.', $contact->id),
            );
        }

        $normalized = trim((string) $next);

        if ($normalized === '') {
            return null;
        }

        if (! ctype_digit($normalized)) {
            throw new Bitrix24ApiException(
                sprintf('Bitrix24 active deal lookup returned a non-numeric pagination cursor for contact #%d.', $contact->id),
            );
        }

        return (int) $normalized;
    }

    private function requireBitrix24ContactId(Contact $contact): string
    {
        $bitrix24ContactId = $this->normalizeRequiredNumericString($contact->bitrix24_contact_id);

        if ($bitrix24ContactId === null) {
            throw new Bitrix24ApiException(
                sprintf('Bitrix24 active deal lookup requires a linked Bitrix24 contact for contact #%d.', $contact->id),
            );
        }

        return $bitrix24ContactId;
    }

    private function resolveSmallestDealId(array $dealIds): ?string
    {
        if ($dealIds === []) {
            return null;
        }

        usort($dealIds, static fn (string $left, string $right): int => (int) $left <=> (int) $right);

        return $dealIds[0];
    }

    private function normalizeRequiredNumericString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        if ($normalized === '' || ! ctype_digit($normalized)) {
            return null;
        }

        return $normalized;
    }

    private function nullableInteger(mixed $value): ?int
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        if ($normalized === '' || ! preg_match('/^-?\d+$/', $normalized)) {
            return null;
        }

        return (int) $normalized;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeClosedValue(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (! is_scalar($value)) {
            return null;
        }

        $normalized = strtoupper(trim((string) $value));

        return match ($normalized) {
            'Y', 'YES', 'TRUE', '1' => true,
            'N', 'NO', 'FALSE', '0' => false,
            default => null,
        };
    }
}
