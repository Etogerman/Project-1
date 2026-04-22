<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24DuplicateContactLookupResultData;
use App\Models\Bitrix24Connection;

class FindBitrix24DuplicateContactsByPhonesAction
{
    public function __construct(
        private readonly Bitrix24ApiClient $apiClient,
    ) {}

    /**
     * @param  list<string>  $phones
     */
    public function handle(array $phones, ?Bitrix24Connection $connection = null): Bitrix24DuplicateContactLookupResultData
    {
        $checkedPhones = [];
        $matchesByPhone = [];
        $uniqueContactIds = [];
        $ambiguous = false;

        foreach ($phones as $phone) {
            $checkedPhones[] = $phone;

            $response = $this->apiClient->call('crm.duplicate.findbycomm', [
                'entity_type' => 'CONTACT',
                'type' => 'PHONE',
                'values' => [$phone],
            ], $connection);

            if (! $response->successful) {
                throw new Bitrix24ApiException(
                    sprintf('Bitrix24 duplicate search failed for phone `%s`: %s', $phone, $response->errorMessage ?? 'Unknown error.'),
                );
            }

            $matchedContactIds = $this->extractContactIds($response->result);

            if (count($matchedContactIds) >= 20) {
                $ambiguous = true;
            }

            $matchesByPhone[$phone] = $matchedContactIds;

            foreach ($matchedContactIds as $contactId) {
                $uniqueContactIds[$contactId] = $contactId;
            }
        }

        return new Bitrix24DuplicateContactLookupResultData(
            checkedPhones: $checkedPhones,
            matchesByPhone: $matchesByPhone,
            uniqueContactIds: array_values($uniqueContactIds),
            ambiguous: $ambiguous,
        );
    }

    /**
     * @param  mixed  $result
     * @return list<string>
     */
    private function extractContactIds(mixed $result): array
    {
        if (! is_array($result)) {
            return [];
        }

        $values = $result['CONTACT'] ?? $result;

        if (! is_array($values)) {
            return [];
        }

        $contactIds = [];

        foreach ($values as $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $contactId = trim((string) $value);

            if ($contactId === '') {
                continue;
            }

            $contactIds[$contactId] = $contactId;
        }

        return array_values($contactIds);
    }
}
