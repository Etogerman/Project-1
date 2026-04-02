<?php

namespace App\Jobs;

use App\Models\Contact;
use App\Services\Bitrix24\DedupeBitrix24ContactPhonesAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DedupeBitrix24ContactPhonesJob implements ShouldQueue
{
    use Queueable;

    private const MAX_ATTEMPTS = 2;

    private const RETRY_DELAY_SECONDS = 30;

    public function __construct(
        public readonly int $contactId,
        public readonly int $attempt = 1,
    ) {}

    public function handle(DedupeBitrix24ContactPhonesAction $dedupeBitrix24ContactPhonesAction): void
    {
        $contact = Contact::query()->find($this->contactId);

        if (! $contact instanceof Contact) {
            return;
        }

        if (! filled($contact->bitrix24_contact_id)) {
            return;
        }

        $updated = $dedupeBitrix24ContactPhonesAction->handle($contact);

        if ($updated || $this->attempt >= self::MAX_ATTEMPTS) {
            return;
        }

        self::dispatch($contact->id, $this->attempt + 1)
            ->delay(now()->addSeconds(self::RETRY_DELAY_SECONDS))
            ->afterCommit();
    }
}
