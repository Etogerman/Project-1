<?php

namespace App\Services\Contacts;

class NormalizePhoneNumberAction
{
    public function handle(string $phoneRaw): string
    {
        $trimmed = trim($phoneRaw);

        if ($trimmed === '') {
            return '';
        }

        $digits = preg_replace('/[^\d]/u', '', $trimmed) ?? '';

        if ($digits === '') {
            return '';
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '8')) {
            return '+7'.substr($digits, 1);
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '7')) {
            return '+'.$digits;
        }

        if (str_starts_with($trimmed, '+')) {
            return '+'.$digits;
        }

        return $digits;
    }
}
