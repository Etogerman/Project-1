<?php

namespace App\Services\Contacts;

use App\Models\Contact;
use Illuminate\Support\Carbon;

class UpdateContactProfileAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(Contact $contact, array $attributes): Contact
    {
        $firstName = $this->normalizeNullableString($attributes['first_name'] ?? null);
        $lastName = $this->normalizeNullableString($attributes['last_name'] ?? null);
        $gender = $this->normalizeGender($attributes['gender'] ?? null);
        $country = $this->normalizeNullableString($attributes['country'] ?? null);
        $city = $this->normalizeNullableString($attributes['city'] ?? null);
        $ageRange = $this->normalizeAgeRange($attributes['age_range'] ?? null);
        $birthDate = $this->normalizeBirthDate($attributes['birth_date'] ?? null);
        $ageYears = $birthDate === null
            ? $this->normalizeNullableInt($attributes['age_years'] ?? null)
            : null;

        $contact->forceFill([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'gender' => $gender,
            'birth_date' => $birthDate,
            'age_years' => $ageYears,
            'age_range' => $ageRange,
            'country' => $country,
            'city' => $city,
        ])->save();

        return $contact->fresh();
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function normalizeNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function normalizeBirthDate(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Carbon::parse($value)->toDateString();
    }

    private function normalizeAgeRange(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        return array_key_exists($trimmed, Contact::ageRangeOptions()) ? $trimmed : null;
    }

    private function normalizeGender(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        return array_key_exists($trimmed, Contact::genderOptions()) ? $trimmed : null;
    }
}
