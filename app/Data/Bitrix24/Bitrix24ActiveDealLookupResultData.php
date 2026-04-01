<?php

namespace App\Data\Bitrix24;

final readonly class Bitrix24ActiveDealLookupResultData
{
    /**
     * @param  list<array{id: string, title: ?string, category_id: ?int, stage_id: ?string, closed: bool, assigned_user_id: ?int, source_id: ?string}>  $deals
     * @param  list<string>  $dealIds
     */
    public function __construct(
        public int $contactId,
        public string $bitrix24ContactId,
        public array $deals,
        public array $dealIds,
        public ?string $smallestDealId,
        public int $pagesFetched,
    ) {}
}
