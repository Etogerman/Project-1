<?php

namespace App\Services\Bitrix24;

use App\Models\Contact;

class ShouldRunBitrix24DuplicatePhoneDiagnosticAction
{
    public function handle(Contact|int $contact): bool
    {
        return (bool) config('bitrix24.duplicate_phone_diagnostic.enabled');
    }
}
