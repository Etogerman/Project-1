<?php

namespace App\Services\Contacts;

use App\Models\ContactEmail;
use App\Services\Bitrix24\QueueBitrix24ContactSyncAction;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DeleteContactEmailAction
{
    public function __construct(
        private readonly QueueBitrix24ContactSyncAction $queueBitrix24ContactSyncAction,
    ) {}

    public function handle(ContactEmail $email): void
    {
        $this->guardAgainstMergedContactEmail($email);
        $contact = $email->contact()->first();

        DB::transaction(function () use ($email): void {
            $contactId = $email->contact_id;
            $wasPrimary = $email->is_primary;

            $email->delete();

            if (! $wasPrimary) {
                return;
            }

            ContactEmail::query()
                ->where('contact_id', $contactId)
                ->update(['is_primary' => false]);

            $nextPrimary = ContactEmail::query()
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

    protected function guardAgainstMergedContactEmail(ContactEmail $email): void
    {
        $contact = $email->contact()->first();

        if ($contact?->isMerged()) {
            throw new RuntimeException('Email относится к архивному дублю. Удалите email у основного контакта.');
        }
    }
}
