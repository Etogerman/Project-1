<?php

namespace App\Data\Bitrix24;

final readonly class Bitrix24DuplicateContactLookupResultData
{
    /**
     * @param  list<string>  $checkedPhones
     * @param  array<string, list<string>>  $matchesByPhone
     * @param  list<string>  $uniqueContactIds
     */
    public function __construct(
        public array $checkedPhones,
        public array $matchesByPhone,
        public array $uniqueContactIds,
        public bool $ambiguous,
    ) {}
}
