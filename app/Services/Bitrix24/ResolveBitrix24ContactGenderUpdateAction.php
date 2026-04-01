<?php

namespace App\Services\Bitrix24;

use App\Models\Contact;

class ResolveBitrix24ContactGenderUpdateAction
{
    /**
     * @param  array<string, mixed>  $remoteSnapshot
     * @return array{
     *     fields: array<string, mixed>,
     *     warnings: list<string>,
     *     resolved_gender_id: ?string
     * }
     */
    public function handle(Contact $contact, array $remoteSnapshot): array
    {
        $remoteGenderId = $this->nullableString($remoteSnapshot['gender_id'] ?? null);
        $localGenderId = $this->resolveLocalGenderId($contact->gender);
        $unknownGenderId = (string) config('bitrix24.values.gender.unknown_id');

        if ($localGenderId === null) {
            return [
                'fields' => [],
                'warnings' => [],
                'resolved_gender_id' => $remoteGenderId,
            ];
        }

        if ($remoteGenderId === null || $remoteGenderId === '' || $remoteGenderId === $unknownGenderId) {
            return [
                'fields' => [
                    config('bitrix24.fields.gender') => (int) $localGenderId,
                ],
                'warnings' => [],
                'resolved_gender_id' => $localGenderId,
            ];
        }

        if ($remoteGenderId !== $localGenderId) {
            return [
                'fields' => [],
                'warnings' => ['gender_preserved'],
                'resolved_gender_id' => $remoteGenderId,
            ];
        }

        return [
            'fields' => [],
            'warnings' => [],
            'resolved_gender_id' => $remoteGenderId,
        ];
    }

    private function resolveLocalGenderId(?string $gender): ?string
    {
        return match ($gender) {
            'male' => (string) config('bitrix24.values.gender.male_id'),
            'female' => (string) config('bitrix24.values.gender.female_id'),
            'unknown' => (string) config('bitrix24.values.gender.unknown_id'),
            default => null,
        };
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
