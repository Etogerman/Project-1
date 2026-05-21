<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('contacts') || ! Schema::hasColumn('contacts', 'gender')) {
            return;
        }

        $cleared = [];

        DB::table('contacts')
            ->select(['id', 'gender'])
            ->whereNotNull('gender')
            ->orderBy('id')
            ->chunkById(500, function ($contacts) use (&$cleared): void {
                foreach ($contacts as $contact) {
                    $previous = $contact->gender;
                    $normalized = $this->normalizeContactGender($previous);

                    if ($previous === $normalized) {
                        continue;
                    }

                    if ($normalized === null && trim((string) $previous) !== '') {
                        $cleared[] = [
                            'contact_id' => $contact->id,
                            'previous_gender' => (string) $previous,
                        ];
                    }

                    DB::table('contacts')
                        ->where('id', $contact->id)
                        ->update(['gender' => $normalized]);
                }
            });

        if ($cleared !== []) {
            Log::warning('contacts.gender_noncanonical_values_cleared', [
                'count' => count($cleared),
                'examples' => array_slice($cleared, 0, 20),
            ]);
        }
    }

    public function down(): void
    {
        // Normalization is intentionally not reversible.
    }

    private function normalizeContactGender(mixed $gender): ?string
    {
        $value = Str::of(is_scalar($gender) ? (string) $gender : '')
            ->trim()
            ->lower()
            ->replace('ё', 'е')
            ->toString();

        return match ($value) {
            '' => null,
            'male', 'мужской', 'муж', 'м' => 'male',
            'female', 'женский', 'жен', 'ж' => 'female',
            'unknown', 'непонятно', 'неизвестно', 'неизвестный' => 'unknown',
            default => null,
        };
    }
};
