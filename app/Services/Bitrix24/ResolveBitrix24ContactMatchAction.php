<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24ContactMatchResultData;
use App\Data\Bitrix24\Bitrix24DuplicateContactLookupResultData;

class ResolveBitrix24ContactMatchAction
{
    public function handle(Bitrix24DuplicateContactLookupResultData $lookupResult): Bitrix24ContactMatchResultData
    {
        if ($lookupResult->ambiguous || count($lookupResult->uniqueContactIds) > 1) {
            return new Bitrix24ContactMatchResultData(
                type: Bitrix24ContactMatchResultData::TYPE_CONFLICT,
                matchedContactId: null,
                candidateContactIds: $lookupResult->uniqueContactIds,
                checkedPhones: $lookupResult->checkedPhones,
                ambiguous: $lookupResult->ambiguous,
            );
        }

        if ($lookupResult->uniqueContactIds === []) {
            return new Bitrix24ContactMatchResultData(
                type: Bitrix24ContactMatchResultData::TYPE_NO_MATCH,
                matchedContactId: null,
                candidateContactIds: [],
                checkedPhones: $lookupResult->checkedPhones,
                ambiguous: false,
            );
        }

        return new Bitrix24ContactMatchResultData(
            type: Bitrix24ContactMatchResultData::TYPE_SINGLE_MATCH,
            matchedContactId: $lookupResult->uniqueContactIds[0],
            candidateContactIds: $lookupResult->uniqueContactIds,
            checkedPhones: $lookupResult->checkedPhones,
            ambiguous: false,
        );
    }
}
