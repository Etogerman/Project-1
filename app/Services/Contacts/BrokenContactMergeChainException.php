<?php

namespace App\Services\Contacts;

use RuntimeException;

class BrokenContactMergeChainException extends RuntimeException
{
    public static function cycleDetected(int $contactId): self
    {
        return new self("Detected a merge cycle while resolving root contact for contact [{$contactId}].");
    }

    public static function missingMergedParent(int $contactId, int $mergedIntoContactId): self
    {
        return new self("Contact [{$contactId}] points to missing merged parent [{$mergedIntoContactId}].");
    }
}
