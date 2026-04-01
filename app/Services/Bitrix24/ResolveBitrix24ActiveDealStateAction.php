<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24ActiveDealLookupResultData;
use App\Data\Bitrix24\Bitrix24ActiveDealStateResultData;

class ResolveBitrix24ActiveDealStateAction
{
    public function handle(Bitrix24ActiveDealLookupResultData $lookupResult): Bitrix24ActiveDealStateResultData
    {
        $activeDealIds = array_values(array_unique($lookupResult->dealIds));

        return match (count($activeDealIds)) {
            0 => new Bitrix24ActiveDealStateResultData(
                type: Bitrix24ActiveDealStateResultData::TYPE_NO_ACTIVE_DEAL,
                selectedDealId: null,
                activeDealIds: [],
            ),
            1 => new Bitrix24ActiveDealStateResultData(
                type: Bitrix24ActiveDealStateResultData::TYPE_SINGLE_ACTIVE_DEAL,
                selectedDealId: $activeDealIds[0],
                activeDealIds: $activeDealIds,
            ),
            default => new Bitrix24ActiveDealStateResultData(
                type: Bitrix24ActiveDealStateResultData::TYPE_MULTIPLE_ACTIVE_DEALS,
                selectedDealId: $lookupResult->smallestDealId,
                activeDealIds: $activeDealIds,
            ),
        };
    }
}
