<?php

namespace App\Data\Bitrix24;

final readonly class Bitrix24ContactMatchResultData
{
    public const TYPE_NO_MATCH = 'no_match';

    public const TYPE_SINGLE_MATCH = 'single_match';

    public const TYPE_CONFLICT = 'conflict';

    /**
     * @param  list<string>  $candidateContactIds
     * @param  list<string>  $checkedPhones
     */
    public function __construct(
        public string $type,
        public ?string $matchedContactId,
        public array $candidateContactIds,
        public array $checkedPhones,
        public bool $ambiguous,
    ) {}
}
