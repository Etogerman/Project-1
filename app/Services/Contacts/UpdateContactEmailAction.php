<?php

namespace App\Services\Contacts;

use App\Models\ContactEmail;
use App\Services\Bitrix24\QueueBitrix24ContactSyncAction;
use RuntimeException;

class UpdateContactEmailAction
{
    public function __construct(
        private readonly QueueBitrix24ContactSyncAction $queueBitrix24ContactSyncAction,
    ) {}

    public function handle(ContactEmail $email, string $emailRaw): ContactEmail
    {
        $this->guardAgainstMergedContactEmail($email);

        $emailRaw = trim($emailRaw);
        $emailNormalized = ContactEmail::normalizeEmail($emailRaw);

        if (! filter_var($emailNormalized, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Укажите корректный email.');
        }

        $duplicateExists = ContactEmail::query()
            ->where('contact_id', $email->contact_id)
            ->where('email_normalized', $emailNormalized)
            ->whereKeyNot($email->getKey())
            ->exists();

        if ($duplicateExists) {
            throw new RuntimeException('Такой email у этого контакта уже сохранён.');
        }

        $email->forceFill([
            'email_raw' => $emailRaw,
            'email_normalized' => $emailNormalized,
        ])->save();

        $contact = $email->contact()->first();

        if ($contact !== null) {
            $this->queueBitrix24ContactSyncAction->handle($contact);
        }

        return $email->fresh();
    }

    protected function guardAgainstMergedContactEmail(ContactEmail $email): void
    {
        $contact = $email->contact()->first();

        if ($contact?->isMerged()) {
            throw new RuntimeException('Email относится к архивному дублю. Измените email у основного контакта.');
        }
    }
}
