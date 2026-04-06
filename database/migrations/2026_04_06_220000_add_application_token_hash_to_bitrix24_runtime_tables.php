<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bitrix24_connections', function (Blueprint $table): void {
            $table->string('application_token_hash', 64)->nullable()->after('application_token');
            $table->index(
                ['status', 'member_id', 'application_token_hash'],
                'bitrix24_connections_status_member_token_hash_idx',
            );
        });

        Schema::table('bitrix24_webhook_events', function (Blueprint $table): void {
            $table->string('application_token_hash', 64)->nullable()->after('application_token')->index();
        });

        $this->backfillConnections();
        $this->backfillWebhookEvents();
    }

    public function down(): void
    {
        Schema::table('bitrix24_webhook_events', function (Blueprint $table): void {
            $table->dropColumn('application_token_hash');
        });

        Schema::table('bitrix24_connections', function (Blueprint $table): void {
            $table->dropIndex('bitrix24_connections_status_member_token_hash_idx');
            $table->dropColumn('application_token_hash');
        });
    }

    private function backfillConnections(): void
    {
        DB::table('bitrix24_connections')
            ->select(['id', 'application_token', 'install_payload'])
            ->orderBy('id')
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    $token = is_string($row->application_token) ? $row->application_token : null;
                    $installPayload = $this->decodeJsonPayload($row->install_payload ?? null);

                    DB::table('bitrix24_connections')
                        ->where('id', $row->id)
                        ->update([
                            'application_token_hash' => $this->hashToken($token),
                            'application_token' => null,
                            'install_payload' => $installPayload === null
                                ? $row->install_payload
                                : json_encode($this->sanitizePayload($installPayload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        ]);
                }
            });
    }

    private function backfillWebhookEvents(): void
    {
        DB::table('bitrix24_webhook_events')
            ->select(['id', 'application_token', 'payload'])
            ->orderBy('id')
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    $token = is_string($row->application_token) ? $row->application_token : null;
                    $payload = $this->decodeJsonPayload($row->payload ?? null);

                    DB::table('bitrix24_webhook_events')
                        ->where('id', $row->id)
                        ->update([
                            'application_token_hash' => $this->hashToken($token),
                            'application_token' => '',
                            'payload' => $payload === null
                                ? $row->payload
                                : json_encode($this->sanitizePayload($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        ]);
                }
            });
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJsonPayload(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function sanitizePayload(array $payload): array
    {
        $sanitized = [];

        foreach ($payload as $key => $value) {
            $normalizedKey = is_string($key)
                ? mb_strtolower(str_replace('_', '', $key))
                : null;

            if ($normalizedKey === 'applicationtoken') {
                $hash = $this->hashToken($value);

                if ($hash !== null) {
                    $sanitized['application_token_hash'] = $hash;
                }

                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizePayload($value);

                continue;
            }

            $sanitized[$key] = $value;
        }

        return $sanitized;
    }

    private function hashToken(mixed $token): ?string
    {
        if (! is_scalar($token)) {
            return null;
        }

        $normalized = trim((string) $token);

        if ($normalized === '') {
            return null;
        }

        return hash_hmac('sha256', $normalized, (string) config('app.key'));
    }
};
