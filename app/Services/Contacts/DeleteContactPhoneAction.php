<?php

namespace App\Services\Contacts;

use App\Models\ContactPhoneNumber;
use App\Services\Bitrix24\QueueBitrix24ContactSyncAction;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DeleteContactPhoneAction
{
    public function __construct(
        private readonly QueueBitrix24ContactSyncAction $queueBitrix24ContactSyncAction,
    ) {}

    public function handle(ContactPhoneNumber $phoneNumber): void
    {
        $this->guardAgainstMergedContactPhone($phoneNumber);
        $contact = $phoneNumber->contact()->first();

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

        if ($contact !== null) {
            $this->queueBitrix24ContactSyncAction->handle($contact);
        }
    }

    protected function guardAgainstMergedContactPhone(ContactPhoneNumber $phoneNumber): void
    {
        $contact = $phoneNumber->contact()->first();

        if ($contact?->isMerged()) {
            throw new RuntimeException('Номер относится к архивному дублю. Удалите номер у основного контакта.');
        }
    }
}
