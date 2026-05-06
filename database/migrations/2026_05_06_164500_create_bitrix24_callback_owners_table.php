<?php

use App\Models\Bitrix24CallbackOwner;
use App\Models\Bitrix24Profile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bitrix24_callback_owners', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bitrix24_profile_id')->constrained('bitrix24_profiles')->cascadeOnDelete();
            $table->string('owner_key', 64);
            $table->string('display_name', 160)->nullable();
            $table->string('callback_base_url', 1024);
            $table->string('status', 32)->default(Bitrix24CallbackOwner::STATUS_ACTIVE);
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampsTz();

            $table->unique(['bitrix24_profile_id', 'owner_key']);
            $table->unique('callback_base_url');
            $table->index('status');
        });

        Schema::table('bitrix24_open_line_routes', function (Blueprint $table): void {
            $table->foreignId('callback_owner_id')
                ->nullable()
                ->after('line_owner_key')
                ->constrained('bitrix24_callback_owners')
                ->nullOnDelete();
        });

        $this->backfillCallbackOwners();
    }

    public function down(): void
    {
        Schema::table('bitrix24_open_line_routes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('callback_owner_id');
        });

        Schema::dropIfExists('bitrix24_callback_owners');
    }

    private function backfillCallbackOwners(): void
    {
        $now = now();

        foreach (DB::table('bitrix24_profiles')->orderBy('id')->get() as $profile) {
            $callbackBaseUrl = Bitrix24Profile::normalizeCallbackBaseUrl($profile->callback_base_url ?? null)
                ?? (string) ($profile->callback_base_url ?? '');

            if ($callbackBaseUrl === '') {
                continue;
            }

            $isLocal = $this->isLocalCallbackBaseUrl($callbackBaseUrl);
            $ownerKey = $isLocal
                ? Bitrix24CallbackOwner::DEFAULT_LOCAL_OWNER_KEY
                : (string) ($profile->profile_key ?: 'default');
            $displayName = $isLocal
                ? 'Локалка 1'
                : (string) ($profile->display_name ?: $ownerKey);

            $ownerId = DB::table('bitrix24_callback_owners')->insertGetId([
                'bitrix24_profile_id' => $profile->id,
                'owner_key' => $ownerKey,
                'display_name' => $displayName,
                'callback_base_url' => $callbackBaseUrl,
                'status' => Bitrix24CallbackOwner::STATUS_ACTIVE,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('bitrix24_open_line_routes')
                ->where('bitrix24_profile_id', $profile->id)
                ->whereNull('callback_owner_id')
                ->update([
                    'callback_owner_id' => $ownerId,
                    'updated_at' => $now,
                ]);
        }
    }

    private function isLocalCallbackBaseUrl(string $callbackBaseUrl): bool
    {
        $host = parse_url($callbackBaseUrl, PHP_URL_HOST);

        if (! is_string($host)) {
            return false;
        }

        $host = mb_strtolower($host);

        return in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || str_contains($host, 'ngrok')
            || str_contains($host, 'loca.lt');
    }
};
