<?php

namespace App\Jobs;

use App\Models\Contact;
use App\Services\Contacts\SyncContactDistanceToMoscowAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CalculateDistanceToMoscowJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public int $contactId,
        public ?string $city = null,
        public ?string $country = null,
        public ?string $region = null,
        public ?string $regionStatus = null,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(SyncContactDistanceToMoscowAction $syncContactDistanceToMoscowAction): void
    {
        $contact = Contact::query()->find($this->contactId);

        if (! $contact instanceof Contact) {
            return;
        }

        if (
            $this->city !== null
            && $this->normalizeNullableString($contact->city) !== $this->normalizeNullableString($this->city)
        ) {
            return;
        }

        if (
            $this->country !== null
            && $this->normalizeNullableString($contact->country) !== $this->normalizeNullableString($this->country)
        ) {
            return;
        }

        if (
            $this->region !== null
            && $this->normalizeNullableString($contact->region) !== $this->normalizeNullableString($this->region)
        ) {
            return;
        }

        if (
            $this->regionStatus !== null
            && $this->normalizeNullableString($contact->region_status) !== $this->normalizeNullableString($this->regionStatus)
        ) {
            return;
        }

        $syncContactDistanceToMoscowAction->handle($contact);
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
