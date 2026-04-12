<?php

use App\Models\Contact;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('contacts')
            ->select(['id', 'name', 'first_name', 'first_name_source'])
            ->orderBy('id')
            ->chunkById(500, function ($contacts): void {
                foreach ($contacts as $contact) {
                    $contactId = (int) $contact->id;
                    $legacyName = $this->normalizeNullableString($contact->name);
                    $firstName = $this->normalizeNullableString($contact->first_name);
                    $firstNameSource = $this->normalizeNullableString($contact->first_name_source);

                    if ($firstName !== null && $firstNameSource === null) {
                        DB::table('contacts')
                            ->where('id', $contactId)
                            ->whereNull('first_name_source')
                            ->update([
                                'first_name_source' => Contact::FIRST_NAME_SOURCE_CONTACT_CONFIRMED,
                            ]);

                        $firstNameSource = Contact::FIRST_NAME_SOURCE_CONTACT_CONFIRMED;
                    }

                    if ($firstName === null && $legacyName !== null) {
                        DB::table('contacts')
                            ->where('id', $contactId)
                            ->where(function ($query): void {
                                $query->whereNull('first_name')
                                    ->orWhere('first_name', '');
                            })
                            ->whereNull('first_name_source')
                            ->update([
                                'first_name' => $legacyName,
                                'first_name_source' => Contact::FIRST_NAME_SOURCE_AUTO,
                            ]);
                    }

                    if ($legacyName === null) {
                        continue;
                    }

                    $targetIdentityId = DB::table('dialogs')
                        ->where('contact_id', $contactId)
                        ->whereNotNull('current_contact_identity_id')
                        ->orderByRaw('last_message_at DESC NULLS LAST')
                        ->orderByDesc('id')
                        ->value('current_contact_identity_id');

                    if ($targetIdentityId === null) {
                        $targetIdentityId = DB::table('contact_identities')
                            ->where('contact_id', $contactId)
                            ->orderBy('id')
                            ->value('id');
                    }

                    if ($targetIdentityId === null) {
                        continue;
                    }

                    DB::table('contact_identities')
                        ->where('id', (int) $targetIdentityId)
                        ->where(function ($query): void {
                            $query->whereNull('display_name')
                                ->orWhere('display_name', '');
                        })
                        ->update([
                            'display_name' => $legacyName,
                        ]);
                }
            });
    }

    public function down(): void
    {
        // Data backfill is intentionally irreversible.
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
