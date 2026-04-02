<?php

namespace App\Jobs;

use App\Models\Contact;
use App\Services\Bitrix24\DedupeBitrix24ContactPhonesAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DedupeBitrix24ContactPhonesJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $contactId,
    ) {}

    public function handle(DedupeBitrix24ContactPhonesAction $dedupeBitrix24ContactPhonesAction): void
    {
        $contact = Contact::query()->find($this->contactId);

        if (! $contact instanceof Contact) {
            return;
        }

        $dedupeBitrix24ContactPhonesAction->handle($contact);
    }
}
