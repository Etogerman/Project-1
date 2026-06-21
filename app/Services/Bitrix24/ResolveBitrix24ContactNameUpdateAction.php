<?php

namespace App\Services\Bitrix24;

use App\Models\Contact;

class ResolveBitrix24ContactNameUpdateAction
{
    /**
     * @var list<string>
     */
    private const LEGACY_AUTOMATIC_SOURCE_IDS = ['7178'];

    /**
     * @var list<string>
     */
    private const LEGACY_SELF_REPORTED_SOURCE_IDS = ['7179'];

    /**
     * @var list<string>
     */
    private const LEGACY_TRAINING_VERIFIED_SOURCE_IDS = ['7180'];

    public function __construct(
        private readonly ResolveBitrix24ProfileSchemaAction $resolveProfileSchemaAction,
    ) {}

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
        $localFirstNameSource = $this->nullableString($contact->first_name_source);
        $remoteFirstName = $this->nullableString($remoteSnapshot['name'] ?? null);
        $remoteLastName = $this->nullableString($remoteSnapshot['last_name'] ?? null);
        $nameSourceId = $this->nullableString($remoteSnapshot['name_source_id'] ?? null);
        $schemaFields = $this->resolveProfileSchemaAction->fields();
        $values = $this->resolveProfileSchemaAction->values();

        $automaticId = (string) $values['name_source']['automatic_information_id'];
        $selfReportedId = (string) $values['name_source']['self_reported_id'];
        $trainingVerifiedId = (string) $values['name_source']['training_verified_id'];
        $remoteSemanticSourceId = $this->resolveRemoteSemanticSourceId(
            $nameSourceId,
            $automaticId,
            $selfReportedId,
            $trainingVerifiedId,
        );
        $resolvedLocalNameSourceId = $this->resolveLocalNameSourceId($localFirstNameSource);

        $canOverwriteName = $this->canOverwriteRemoteName(
            $localFirstNameSource,
            $nameSourceId,
            $remoteSemanticSourceId,
            $automaticId,
            $selfReportedId,
        );
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

            if ($resolvedLocalNameSourceId !== null && ($fields !== [] || $nameSourceId !== $resolvedLocalNameSourceId)) {
                $fields[$schemaFields['name_source']] = $resolvedLocalNameSourceId;
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

        $hasTrainingVerifiedConflict = $remoteSemanticSourceId === $trainingVerifiedId
            && (($localFirstName !== null && $localFirstName !== $remoteFirstName)
                || ($localLastName !== null && $localLastName !== $remoteLastName));

        if ($remoteSemanticSourceId === $trainingVerifiedId && $nameSourceId !== $trainingVerifiedId) {
            $fields[$schemaFields['name_source']] = (int) $trainingVerifiedId;
        }

        if ($hasTrainingVerifiedConflict) {
            $altFirstName = $localFirstName;
            $altLastName = $localLastName;

            if ($altFirstName !== null) {
                $fields[$schemaFields['alt_first_name']] = $altFirstName;
            }

            if ($altLastName !== null) {
                $fields[$schemaFields['alt_last_name']] = $altLastName;
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

    private function resolveLocalNameSourceId(?string $source): ?int
    {
        $values = $this->resolveProfileSchemaAction->values();

        return match ($source) {
            Contact::FIRST_NAME_SOURCE_AUTO => $values['name_source']['automatic_information_id'],
            Contact::FIRST_NAME_SOURCE_CONTACT_CONFIRMED => $values['name_source']['self_reported_id'],
            Contact::FIRST_NAME_SOURCE_MANUAL => $values['name_source']['training_verified_id'],
            default => null,
        };
    }

    private function canOverwriteRemoteName(
        ?string $localSource,
        ?string $remoteSourceId,
        ?string $remoteSemanticSourceId,
        string $automaticId,
        string $selfReportedId,
    ): bool {
        if ($remoteSourceId !== null && $remoteSourceId !== '' && $remoteSemanticSourceId === null) {
            return true;
        }

        return match ($localSource) {
            Contact::FIRST_NAME_SOURCE_AUTO => $remoteSourceId === null
                || $remoteSourceId === ''
                || $remoteSemanticSourceId === $automaticId,
            Contact::FIRST_NAME_SOURCE_CONTACT_CONFIRMED => $remoteSourceId === null
                || $remoteSourceId === ''
                || in_array($remoteSemanticSourceId, [$automaticId, $selfReportedId], true),
            Contact::FIRST_NAME_SOURCE_MANUAL => true,
            default => false,
        };
    }

    private function resolveRemoteSemanticSourceId(
        ?string $remoteSourceId,
        string $automaticId,
        string $selfReportedId,
        string $trainingVerifiedId,
    ): ?string {
        if ($remoteSourceId === null || $remoteSourceId === '') {
            return null;
        }

        if ($remoteSourceId === $automaticId || in_array($remoteSourceId, self::LEGACY_AUTOMATIC_SOURCE_IDS, true)) {
            return $automaticId;
        }

        if ($remoteSourceId === $selfReportedId || in_array($remoteSourceId, self::LEGACY_SELF_REPORTED_SOURCE_IDS, true)) {
            return $selfReportedId;
        }

        if ($remoteSourceId === $trainingVerifiedId || in_array($remoteSourceId, self::LEGACY_TRAINING_VERIFIED_SOURCE_IDS, true)) {
            return $trainingVerifiedId;
        }

        return null;
    }
}
