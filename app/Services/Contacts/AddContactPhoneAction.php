<?php

namespace App\Services\Contacts;

use App\Models\Contact;
use App\Models\ContactPhoneNumber;
use Illuminate\Database\QueryException;

class AddContactPhoneAction
{
    public function handle(Contact $contact, string $phoneRaw, string $source): ContactPhoneNumber
    {
        $phoneRaw = trim($phoneRaw);
        $phoneNormalized = static::normalizePhone($phoneRaw);

        $existing = $contact->phoneNumbers()
            ->where('phone_normalized', $phoneNormalized)
            ->first();

        if ($existing instanceof ContactPhoneNumber) {
            return $existing;
        }

        try {
            return $contact->phoneNumbers()->create([
                'phone_raw' => $phoneRaw,
                'phone_normalized' => $phoneNormalized,
                'source' => $source,
                'is_primary' => ! $contact->phoneNumbers()->exists(),
            ]);
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? null) !== '23505') {
                throw $exception;
            }

            return $contact->phoneNumbers()
                ->where('phone_normalized', $phoneNormalized)
                ->firstOrFail();
        }
    }

    public static function normalizePhone(string $phoneRaw): string
    {
        $trimmed = trim($phoneRaw);

        if ($trimmed === '') {
            return '';
        }

        $hasPlusPrefix = str_starts_with($trimmed, '+');
        $digits = preg_replace('/[^\d]/u', '', $trimmed) ?? '';

        return $hasPlusPrefix ? '+'.$digits : $digits;
    }

    public static function maskPhone(string $phoneNormalized): string
    {
        $lastFour = mb_substr($phoneNormalized, -4);
        $prefix = mb_substr($phoneNormalized, 0, max(0, mb_strlen($phoneNormalized) - 4));

        $maskedPrefix = preg_replace('/\d/u', '*', $prefix) ?? $prefix;

        return $maskedPrefix.$lastFour;
    }
}
