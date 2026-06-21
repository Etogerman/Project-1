<?php

namespace App\Data\Contacts;

final readonly class ContactDataCollectionCompletionResult
{
    /**
     * @param  list<string>  $missingRequirements
     */
    public function __construct(
        public string $status,
        public bool $completed,
        public ?int $rootContactId,
        public array $missingRequirements = [],
        public ?string $reason = null,
    ) {}
}
