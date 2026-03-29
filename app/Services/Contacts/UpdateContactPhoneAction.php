<?php

namespace App\Services\Contacts;

use App\Models\ContactPhoneNumber;
use RuntimeException;

class UpdateContactPhoneAction
{
    public function handle(ContactPhoneNumber $phoneNumber, string $phoneRaw): ContactPhoneNumber
    {
        $phoneRaw = trim($phoneRaw);
        $phoneNormalized = AddContactPhoneAction::normalizePhone($phoneRaw);

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
}
