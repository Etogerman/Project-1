<?php

namespace App\Services\Bitrix24;

use App\Models\Contact;

class ResolveBitrix24ContactNameUpdateAction
{
    /**
     * @param  array<string, mixed>  $remoteSnapshot
     * @return array{
     *     fields: array<string, mixed>,
     *     warnings: list<string>,
     *     resolved_first_name: ?string,
     *     resolved_last_name: ?string,
     *     alt_first_name: ?string,
     *     alt_last_name: ?string
     * }
     */
    public function handle(Contact $contact, array $remoteSnapshot): array
    {
        $localFirstName = $this->nullableString($contact->first_name);
        $localLastName = $this->nullableString($contact->last_name);
        $remoteFirstName = $this->nullableString($remoteSnapshot['name'] ?? null);
        $remoteLastName = $this->nullableString($remoteSnapshot['last_name'] ?? null);
        $nameSourceId = $this->nullableString($remoteSnapshot['name_source_id'] ?? null);

        $automaticId = (string) config('bitrix24.values.name_source.automatic_information_id');
        $selfReportedId = (string) config('bitrix24.values.name_source.self_reported_id');
        $trainingVerifiedId = (string) config('bitrix24.values.name_source.training_verified_id');

        $canOverwriteName = in_array($nameSourceId, [null, '', $automaticId, $selfReportedId], true);
        $fields = [];
        $warnings = [];
        $resolvedFirstName = $remoteFirstName;
        $resolvedLastName = $remoteLastName;
        $altFirstName = null;
        $altLastName = null;

        if ($canOverwriteName) {
            if ($localFirstName !== null) {
                $fields['NAME'] = $localFirstName;
                $resolvedFirstName = $localFirstName;
            }

            if ($localLastName !== null) {
                $fields['LAST_NAME'] = $localLastName;
                $resolvedLastName = $localLastName;
            }

            if ($fields !== [] || $nameSourceId !== $selfReportedId) {
                $fields[config('bitrix24.fields.name_source')] = (int) config('bitrix24.values.name_source.self_reported_id');
            }

            return [
                'fields' => $fields,
                'warnings' => [],
                'resolved_first_name' => $resolvedFirstName,
                'resolved_last_name' => $resolvedLastName,
                'alt_first_name' => null,
                'alt_last_name' => null,
            ];
        }

        $hasTrainingVerifiedConflict = $nameSourceId === $trainingVerifiedId
            && (($localFirstName !== null && $localFirstName !== $remoteFirstName)
                || ($localLastName !== null && $localLastName !== $remoteLastName));

        if ($hasTrainingVerifiedConflict) {
            $altFirstName = $localFirstName;
            $altLastName = $localLastName;

            if ($altFirstName !== null) {
                $fields[config('bitrix24.fields.alt_first_name')] = $altFirstName;
            }

            if ($altLastName !== null) {
                $fields[config('bitrix24.fields.alt_last_name')] = $altLastName;
            }

            $warnings[] = 'training_verified_name_preserved';
        }

        return [
            'fields' => $fields,
            'warnings' => $warnings,
            'resolved_first_name' => $resolvedFirstName,
            'resolved_last_name' => $resolvedLastName,
            'alt_first_name' => $altFirstName,
            'alt_last_name' => $altLastName,
        ];
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
