<?php

namespace App\Data\Bitrix24;

final readonly class Bitrix24ActiveDealStateResultData
{
    public const TYPE_NO_ACTIVE_DEAL = 'no_active_deal';

    public const TYPE_SINGLE_ACTIVE_DEAL = 'single_active_deal';

    public const TYPE_MULTIPLE_ACTIVE_DEALS = 'multiple_active_deals';

    /**
     * @param  list<string>  $activeDealIds
     */
    public function __construct(
        public string $type,
        public ?string $selectedDealId,
        public array $activeDealIds,
    ) {}
}
