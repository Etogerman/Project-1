<?php

namespace App\Services\Contacts;

use App\Models\ContactPhoneNumber;
use RuntimeException;

class UpdateContactPhoneAction
{
    public function __construct(
        private readonly NormalizePhoneNumberAction $normalizePhoneNumberAction,
    ) {}

    public function handle(ContactPhoneNumber $phoneNumber, string $phoneRaw): ContactPhoneNumber
    {
        $this->guardAgainstMergedContactPhone($phoneNumber);

        $phoneRaw = trim($phoneRaw);
        $phoneNormalized = $this->normalizePhoneNumberAction->handle($phoneRaw);

        if ($phoneNormalized === '') {
            throw new RuntimeException('Укажите корректный номер телефона.');
        }

        $duplicateExists = ContactPhoneNumber::query()
            ->where('contact_id', $phoneNumber->contact_id)
            ->where('phone_normalized', $phoneNormalized)
            ->whereKeyNot($phoneNumber->getKey())
            ->exists();

        if ($duplicateExists) {
            throw new RuntimeException('Такой номер у этого контакта уже сохранён.');
        }

        $phoneNumber->forceFill([
            'phone_raw' => $phoneRaw,
            'phone_normalized' => $phoneNormalized,
        ])->save();

        return $phoneNumber->fresh();
    }

    protected function guardAgainstMergedContactPhone(ContactPhoneNumber $phoneNumber): void
    {
        $contact = $phoneNumber->contact()->first();

        if ($contact?->isMerged()) {
            throw new RuntimeException('Номер относится к архивному дублю. Измените номер у основного контакта.');
        }
    }
}
