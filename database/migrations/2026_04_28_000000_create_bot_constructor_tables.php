<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_constructor_blocks', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->boolean('is_active')->default(false)->index();
            $table->string('match_type', 50)->index();
            $table->jsonb('match_values')->default(DB::raw("'[]'::jsonb"));
            $table->text('response_text')->nullable();
            $table->unsignedInteger('x')->default(64);
            $table->unsignedInteger('y')->default(64);
            $table->timestamps();
        });

        Schema::create('bot_constructor_block_channels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bot_constructor_block_id')
                ->constrained('bot_constructor_blocks')
                ->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(
                ['bot_constructor_block_id', 'channel_id'],
                'bot_constructor_block_channels_unique',
            );
            $table->index(['channel_id', 'bot_constructor_block_id'], 'bot_constructor_block_channels_channel_index');
        });

        Schema::create('bot_constructor_block_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inbound_message_id')
                ->constrained('messages')
                ->cascadeOnDelete();
            $table->foreignId('bot_constructor_block_id')
                ->constrained('bot_constructor_blocks')
                ->cascadeOnDelete();
            $table->foreignId('outbound_message_id')
                ->nullable()
                ->constrained('messages')
                ->nullOnDelete();
            $table->string('status', 30)->index();
            $table->string('error_message', 1000)->nullable();
            $table->timestamps();

            $table->unique(
                ['inbound_message_id', 'bot_constructor_block_id'],
                'bot_constructor_block_runs_unique_message_block',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_constructor_block_runs');
        Schema::dropIfExists('bot_constructor_block_channels');
        Schema::dropIfExists('bot_constructor_blocks');
    }
};
