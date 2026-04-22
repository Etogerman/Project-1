<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bitrix24_webhook_events', function (Blueprint $table): void {
            $table->text('callback_base_url')
                ->nullable()
                ->after('connection_id');
        });

        DB::table('bitrix24_webhook_events')
            ->select(['id', 'connection_id'])
            ->whereNotNull('connection_id')
            ->orderBy('id')
            ->chunkById(100, function ($rows): void {
                $connectionIds = collect($rows)
                    ->pluck('connection_id')
                    ->filter()
                    ->unique()
                    ->map(fn (mixed $value): int => (int) $value)
                    ->all();

                if ($connectionIds === []) {
                    return;
                }

                $callbackBaseUrlsByConnection = DB::table('bitrix24_connections')
                    ->join('bitrix24_profiles', 'bitrix24_profiles.id', '=', 'bitrix24_connections.profile_id')
                    ->whereIn('bitrix24_connections.id', $connectionIds)
                    ->pluck('bitrix24_profiles.callback_base_url', 'bitrix24_connections.id');

                foreach ($rows as $row) {
                    $callbackBaseUrl = $callbackBaseUrlsByConnection[(int) $row->connection_id] ?? null;

                    if (! is_string($callbackBaseUrl) || trim($callbackBaseUrl) === '') {
                        continue;
                    }

                    DB::table('bitrix24_webhook_events')
                        ->where('id', $row->id)
                        ->update([
                            'callback_base_url' => $callbackBaseUrl,
                        ]);
                }
            });

        Schema::table('bitrix24_webhook_events', function (Blueprint $table): void {
            $table->dropUnique('bitrix24_webhook_events_dedupe_unique');
            $table->unique(
                ['callback_base_url', 'callback_type', 'event_name', 'member_id', 'payload_hash'],
                'bitrix24_webhook_events_callback_dedupe_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('bitrix24_webhook_events', function (Blueprint $table): void {
            $table->dropUnique('bitrix24_webhook_events_callback_dedupe_unique');
            $table->dropColumn('callback_base_url');
            $table->unique(
                ['callback_type', 'event_name', 'member_id', 'payload_hash'],
                'bitrix24_webhook_events_dedupe_unique',
            );
        });
    }
};
