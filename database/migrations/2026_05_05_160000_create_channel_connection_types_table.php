<?php

use App\Models\ChannelConnectionType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_connection_types', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->string('platform', 32);
            $table->string('connection_kind', 32);
            $table->boolean('is_active')->default(true);
            $table->boolean('supports_open_lines')->default(false);
            $table->boolean('supports_auto_setup')->default(false);
            $table->json('settings_schema')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(100);
            $table->timestampsTz();

            $table->index(['platform', 'connection_kind']);
            $table->index(['is_active', 'sort_order']);
        });

        ChannelConnectionType::insertDefaultDefinitions(ChannelConnectionType::defaultDefinitions());

        Schema::table('channels', function (Blueprint $table): void {
            $table->foreignId('channel_connection_type_id')
                ->nullable()
                ->after('connection_type')
                ->constrained('channel_connection_types')
                ->nullOnDelete();
        });

        $typeIds = DB::table('channel_connection_types')
            ->get(['id', 'platform', 'connection_kind'])
            ->mapWithKeys(fn (object $type): array => [
                $type->platform.'|'.$type->connection_kind => $type->id,
            ]);

        foreach ($typeIds as $key => $typeId) {
            [$platform, $connectionKind] = explode('|', (string) $key, 2);

            DB::table('channels')
                ->where('platform', $platform)
                ->where('connection_type', $connectionKind)
                ->update([
                    'channel_connection_type_id' => $typeId,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('channel_connection_type_id');
        });

        Schema::dropIfExists('channel_connection_types');
    }
};
