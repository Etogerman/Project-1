<?php

namespace App\Services\Bitrix24;

use App\Models\Contact;

class ResolveBitrix24ContactGenderUpdateAction
{
    public function __construct(
        private readonly ResolveBitrix24ProfileSchemaAction $resolveProfileSchemaAction,
    ) {}

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
        $fields = $this->resolveProfileSchemaAction->fields();
        $values = $this->resolveProfileSchemaAction->values();
        $unknownGenderId = (string) $values['gender']['unknown_id'];

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
                    $fields['gender'] => (int) $localGenderId,
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
        $values = $this->resolveProfileSchemaAction->values();

        return match ($gender) {
            'male' => (string) $values['gender']['male_id'],
            'female' => (string) $values['gender']['female_id'],
            'unknown' => (string) $values['gender']['unknown_id'],
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
