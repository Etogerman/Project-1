<?php

namespace App\Services\Contacts;

use App\Models\ContactPhoneNumber;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DeleteContactPhoneAction
{
    public function handle(ContactPhoneNumber $phoneNumber): void
    {
        $this->guardAgainstMergedContactPhone($phoneNumber);

        DB::transaction(function () use ($phoneNumber): void {
            $contactId = $phoneNumber->contact_id;
            $wasPrimary = $phoneNumber->is_primary;

            $phoneNumber->delete();

            if (! $wasPrimary) {
                return;
            }

            ContactPhoneNumber::query()
                ->where('contact_id', $contactId)
                ->update(['is_primary' => false]);

            $nextPrimary = ContactPhoneNumber::query()
                ->where('contact_id', $contactId)
                ->orderBy('id')
                ->first();

            if ($nextPrimary === null) {
                return;
            }

            $nextPrimary->forceFill([
                'is_primary' => true,
            ])->save();
        });
    }

    protected function guardAgainstMergedContactPhone(ContactPhoneNumber $phoneNumber): void
    {
        $contact = $phoneNumber->contact()->first();

        if ($contact?->isMerged()) {
            throw new RuntimeException('Номер относится к архивному дублю. Удалите номер у основного контакта.');
        }
    }
}
