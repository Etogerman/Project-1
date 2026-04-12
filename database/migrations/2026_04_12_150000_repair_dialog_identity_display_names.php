<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('contacts')
            ->select(['id', 'name'])
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('dialogs')
                    ->whereColumn('dialogs.contact_id', 'contacts.id')
                    ->whereNotNull('dialogs.current_contact_identity_id');
            })
            ->orderBy('id')
            ->chunkById(500, function ($contacts): void {
                foreach ($contacts as $contact) {
                    $contactId = (int) $contact->id;
                    $legacyName = $this->normalizeNullableString($contact->name);
                    $dialogIdentities = $this->loadDialogIdentities($contactId);

                    if ($dialogIdentities === []) {
                        continue;
                    }

                    foreach ($dialogIdentities as $targetIdentity) {
                        if ($targetIdentity['display_name'] !== null) {
                            continue;
                        }

                        $repairLabel = $this->resolveRepairLabel($targetIdentity, $dialogIdentities, $legacyName);

                        if ($repairLabel === null) {
                            continue;
                        }

                        DB::table('contact_identities')
                            ->where('id', $targetIdentity['id'])
                            ->where(function ($query): void {
                                $query->whereNull('display_name')
                                    ->orWhere('display_name', '');
                            })
                            ->update([
                                'display_name' => $repairLabel,
                            ]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Data repair is intentionally irreversible.
    }

    /**
     * @return list<array{
     *     id:int,
     *     platform:?string,
     *     external_user_id:?string,
     *     display_name:?string
     * }>
     */
    private function loadDialogIdentities(int $contactId): array
    {
        return DB::table('dialogs')
            ->join('contact_identities', 'contact_identities.id', '=', 'dialogs.current_contact_identity_id')
            ->where('dialogs.contact_id', $contactId)
            ->whereNotNull('dialogs.current_contact_identity_id')
            ->select([
                'contact_identities.id',
                'contact_identities.platform',
                'contact_identities.external_user_id',
                'contact_identities.display_name',
                'dialogs.last_message_at',
                'dialogs.id as dialog_id',
            ])
            ->orderByRaw('dialogs.last_message_at DESC NULLS LAST')
            ->orderByDesc('dialogs.id')
            ->get()
            ->unique('id')
            ->map(function ($identity): array {
                return [
                    'id' => (int) $identity->id,
                    'platform' => $this->normalizeNullableString($identity->platform),
                    'external_user_id' => $this->normalizeNullableString($identity->external_user_id),
                    'display_name' => $this->normalizeNullableString($identity->display_name),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array{id:int,platform:?string,external_user_id:?string,display_name:?string}  $targetIdentity
     * @param  list<array{id:int,platform:?string,external_user_id:?string,display_name:?string}>  $dialogIdentities
     */
    private function resolveRepairLabel(array $targetIdentity, array $dialogIdentities, ?string $legacyName): ?string
    {
        foreach ($dialogIdentities as $sourceIdentity) {
            if ($sourceIdentity['id'] === $targetIdentity['id']) {
                continue;
            }

            if ($sourceIdentity['display_name'] === null) {
                continue;
            }

            if ($sourceIdentity['platform'] !== $targetIdentity['platform']) {
                continue;
            }

            if ($sourceIdentity['external_user_id'] === null || $targetIdentity['external_user_id'] === null) {
                continue;
            }

            if ($sourceIdentity['external_user_id'] !== $targetIdentity['external_user_id']) {
                continue;
            }

            return $sourceIdentity['display_name'];
        }

        if (count($dialogIdentities) !== 1) {
            return null;
        }

        return $legacyName;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = preg_replace('/\s+/u', ' ', trim((string) $value));

        if (! is_string($normalized)) {
            return null;
        }

        return $normalized === '' ? null : $normalized;
    }
};
