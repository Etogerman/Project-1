<?php

namespace App\Data\Contacts;

final readonly class FoundDuplicateContactRootsResult
{
    /**
     * @param  list<int>  $matchedRootContactIds
     */
    public function __construct(
        public string $phoneNormalized,
        public ?int $currentRootContactId,
        public array $matchedRootContactIds,
        public int $matchedRootCount,
        public bool $hasMatches,
        public bool $hasSingleOtherRoot,
        public bool $hasMultipleOtherRoots,
    ) {}
}
