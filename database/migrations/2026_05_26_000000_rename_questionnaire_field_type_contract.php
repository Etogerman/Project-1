<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->rewriteFieldTypes([
            'single_choice' => 'choice',
            'dictionary_lookup' => 'dictionary',
        ]);
    }

    public function down(): void
    {
        $this->rewriteFieldTypes([
            'choice' => 'single_choice',
            'dictionary' => 'dictionary_lookup',
        ]);
    }

    /**
     * @param  array<string, string>  $typeMap
     */
    private function rewriteFieldTypes(array $typeMap): void
    {
        DB::table('questionnaire_template_versions')
            ->select(['id', 'fields_payload'])
            ->orderBy('id')
            ->chunkById(100, function ($versions) use ($typeMap): void {
                foreach ($versions as $version) {
                    $fieldsPayload = $this->decodePayload($version->fields_payload);

                    if ($fieldsPayload === null) {
                        continue;
                    }

                    $rewrittenPayload = $this->rewritePayload($fieldsPayload, $typeMap);

                    if ($rewrittenPayload === $fieldsPayload) {
                        continue;
                    }

                    DB::table('questionnaire_template_versions')
                        ->where('id', $version->id)
                        ->update([
                            'fields_payload' => json_encode($rewrittenPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        ]);
                }
            });
    }

    /**
     * @return list<mixed>|null
     */
    private function decodePayload(mixed $payload): ?array
    {
        if (is_array($payload)) {
            return array_is_list($payload) ? $payload : null;
        }

        if (! is_string($payload) || trim($payload) === '') {
            return null;
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) && array_is_list($decoded) ? $decoded : null;
    }

    /**
     * @param  list<mixed>  $fieldsPayload
     * @param  array<string, string>  $typeMap
     * @return list<mixed>
     */
    private function rewritePayload(array $fieldsPayload, array $typeMap): array
    {
        return array_map(
            static function (mixed $field) use ($typeMap): mixed {
                if (! is_array($field)) {
                    return $field;
                }

                $type = $field['type'] ?? null;

                if (is_string($type) && isset($typeMap[$type])) {
                    $field['type'] = $typeMap[$type];
                }

                return $field;
            },
            $fieldsPayload,
        );
    }
};
