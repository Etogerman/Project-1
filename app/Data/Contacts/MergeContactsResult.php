<?php

namespace App\Data\Contacts;

final readonly class MergeContactsResult
{
    /**
     * @param  array<string, mixed>  $fieldsCopied
     * @param  array<string, mixed>  $fieldsConflicted
     */
    public function __construct(
        public int $primaryContactId,
        public int $secondaryContactId,
        public bool $wasMerged,
        public bool $wasNoopSameRoot,
        public int $messagesMovedCount,
        public int $identitiesMovedCount,
        public int $phonesMovedCount,
        public array $fieldsCopied,
        public array $fieldsConflicted,
        public ?int $mergeLogId,
    ) {}
}
