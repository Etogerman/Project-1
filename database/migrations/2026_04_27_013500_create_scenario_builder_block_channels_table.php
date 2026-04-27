<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('scenario_builder_block_channels')) {
            Schema::create('scenario_builder_block_channels', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('scenario_builder_block_id')
                    ->constrained('scenario_builder_blocks')
                    ->cascadeOnDelete();
                $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
                $table->timestamps();

                $table->unique(
                    ['scenario_builder_block_id', 'channel_id'],
                    'scenario_builder_block_channels_unique',
                );
                $table->index(['channel_id', 'scenario_builder_block_id'], 'scenario_builder_block_channels_channel_index');
            });
        }

        if (! Schema::hasColumn('scenario_builder_blocks', 'channel_id')) {
            return;
        }

        $now = now();
        $rows = DB::table('scenario_builder_blocks')
            ->whereNotNull('channel_id')
            ->get(['id', 'channel_id'])
            ->map(fn (object $block): array => [
                'scenario_builder_block_id' => (int) $block->id,
                'channel_id' => (int) $block->channel_id,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all();

        if ($rows !== []) {
            DB::table('scenario_builder_block_channels')->upsert(
                $rows,
                ['scenario_builder_block_id', 'channel_id'],
                ['updated_at'],
            );
        }

        Schema::table('scenario_builder_blocks', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('channel_id');
        });
    }

    public function down(): void
    {
        Schema::table('scenario_builder_blocks', function (Blueprint $table): void {
            if (! Schema::hasColumn('scenario_builder_blocks', 'channel_id')) {
                $table->foreignId('channel_id')
                    ->nullable()
                    ->after('scenario_version_id')
                    ->constrained()
                    ->nullOnDelete();
            }
        });

        if (Schema::hasTable('scenario_builder_block_channels')) {
            DB::table('scenario_builder_block_channels')
                ->orderBy('id')
                ->get(['scenario_builder_block_id', 'channel_id'])
                ->each(function (object $binding): void {
                    DB::table('scenario_builder_blocks')
                        ->where('id', (int) $binding->scenario_builder_block_id)
                        ->whereNull('channel_id')
                        ->update(['channel_id' => (int) $binding->channel_id]);
                });
        }

        Schema::dropIfExists('scenario_builder_block_channels');
    }
};
