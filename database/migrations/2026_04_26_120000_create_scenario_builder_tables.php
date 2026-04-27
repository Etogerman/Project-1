<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scenario_builder_blocks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scenario_version_id')->constrained()->cascadeOnDelete();
            $table->string('type', 50)->index();
            $table->string('title');
            $table->unsignedInteger('position_x')->default(64);
            $table->unsignedInteger('position_y')->default(64);
            $table->jsonb('settings_payload')->default(DB::raw("'{}'::jsonb"));
            $table->timestamps();

            $table->index(['scenario_version_id', 'type'], 'scenario_builder_blocks_version_type_index');
        });

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

        Schema::create('scenario_builder_conditions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scenario_builder_block_id')
                ->constrained('scenario_builder_blocks')
                ->cascadeOnDelete();
            $table->string('type', 50)->index();
            $table->string('match_operator', 50)->default('exact');
            $table->string('variable', 100);
            $table->text('value');
            $table->unsignedInteger('sort_order')->default(1);
            $table->timestamps();

            $table->unique(
                ['scenario_builder_block_id', 'type', 'variable', 'value'],
                'scenario_builder_conditions_unique_value',
            );
        });

        Schema::create('scenario_builder_edges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scenario_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_scenario_builder_block_id')
                ->constrained('scenario_builder_blocks')
                ->cascadeOnDelete();
            $table->foreignId('to_scenario_builder_block_id')
                ->nullable()
                ->constrained('scenario_builder_blocks')
                ->nullOnDelete();
            $table->string('to_runtime_block_id', 100)->nullable();
            $table->jsonb('condition_payload')->default(DB::raw("'{}'::jsonb"));
            $table->unsignedInteger('sort_order')->default(1);
            $table->timestamps();

            $table->index(['scenario_version_id', 'from_scenario_builder_block_id'], 'scenario_builder_edges_version_from_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scenario_builder_edges');
        Schema::dropIfExists('scenario_builder_conditions');
        Schema::dropIfExists('scenario_builder_block_channels');
        Schema::dropIfExists('scenario_builder_blocks');
    }
};
