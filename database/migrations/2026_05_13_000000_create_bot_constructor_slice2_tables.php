<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_constructor_constants', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('value_type', 30);
            $table->string('value');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        DB::table('bot_constructor_constants')->updateOrInsert(
            ['key' => 'bot_constructor_arrow_pass_limit'],
            [
                'name' => 'Лимит переходов клиента по стрелке',
                'value_type' => 'integer',
                'value' => '10',
                'description' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        Schema::create('bot_constructor_arrows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_block_id')
                ->constrained('bot_constructor_blocks')
                ->restrictOnDelete();
            $table->foreignId('target_block_id')
                ->constrained('bot_constructor_blocks')
                ->restrictOnDelete();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('delay_value')->default(0);
            $table->string('delay_unit', 20)->default('seconds');
            $table->boolean('cancel_if_left_source_block')->default(false);
            $table->string('condition_match_type', 30)->default('always');
            $table->string('condition_value')->nullable();
            $table->integer('priority')->default(100)->index();
            $table->string('pass_limit_mode', 30)->default('constant');
            $table->unsignedInteger('pass_limit_value')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index('source_block_id');
            $table->index('target_block_id');
            $table->index('deleted_at');
        });

        Schema::create('bot_constructor_executions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('root_inbound_message_id')
                ->nullable()
                ->constrained('messages')
                ->nullOnDelete();
            $table->foreignId('parent_execution_id')
                ->nullable()
                ->constrained('bot_constructor_executions')
                ->restrictOnDelete();
            $table->unsignedBigInteger('started_by_arrow_run_id')->nullable()->index();
            $table->foreignId('dialog_id')
                ->constrained('dialogs')
                ->restrictOnDelete();
            $table->foreignId('channel_id')
                ->constrained('channels')
                ->restrictOnDelete();
            $table->string('trigger_type', 30)->index();
            $table->unsignedInteger('auto_transition_count')->default(0);
            $table->unsignedInteger('next_sequence_number')->default(1);
            $table->string('status', 30)->index();
            $table->timestamps();

            $table->index('root_inbound_message_id');
            $table->index('parent_execution_id');
            $table->index('dialog_id');
        });

        Schema::create('bot_constructor_dialog_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dialog_id')
                ->unique()
                ->constrained('dialogs')
                ->cascadeOnDelete();
            $table->foreignId('current_block_id')
                ->nullable()
                ->constrained('bot_constructor_blocks')
                ->nullOnDelete();
            $table->foreignId('last_execution_id')
                ->nullable()
                ->constrained('bot_constructor_executions')
                ->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('bot_constructor_arrow_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bot_constructor_execution_id')
                ->constrained('bot_constructor_executions')
                ->restrictOnDelete();
            $table->foreignId('bot_constructor_arrow_id')
                ->constrained('bot_constructor_arrows')
                ->restrictOnDelete();
            $table->foreignId('dialog_id')
                ->constrained('dialogs')
                ->restrictOnDelete();
            $table->foreignId('source_block_id')
                ->constrained('bot_constructor_blocks')
                ->restrictOnDelete();
            $table->foreignId('target_block_id')
                ->constrained('bot_constructor_blocks')
                ->restrictOnDelete();
            $table->foreignId('inbound_message_id')
                ->nullable()
                ->constrained('messages')
                ->nullOnDelete();
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->string('status', 30)->index();
            $table->string('error_message', 1000)->nullable();
            $table->timestamps();

            $table->index('bot_constructor_execution_id');
            $table->index(['bot_constructor_arrow_id', 'dialog_id', 'status'], 'bot_constructor_arrow_runs_arrow_dialog_status_index');
            $table->index(['status', 'scheduled_for'], 'bot_constructor_arrow_runs_status_scheduled_index');
        });

        Schema::table('bot_constructor_executions', function (Blueprint $table): void {
            $table->foreign('started_by_arrow_run_id', 'bot_constructor_executions_started_by_arrow_run_fk')
                ->references('id')
                ->on('bot_constructor_arrow_runs')
                ->nullOnDelete();
        });

        Schema::create('bot_constructor_execution_block_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bot_constructor_execution_id')
                ->constrained('bot_constructor_executions')
                ->restrictOnDelete();
            $table->foreignId('bot_constructor_block_id')
                ->constrained('bot_constructor_blocks')
                ->restrictOnDelete();
            $table->foreignId('bot_constructor_arrow_run_id')
                ->nullable()
                ->constrained('bot_constructor_arrow_runs')
                ->nullOnDelete();
            $table->foreignId('dialog_id')
                ->constrained('dialogs')
                ->restrictOnDelete();
            $table->foreignId('channel_id')
                ->constrained('channels')
                ->restrictOnDelete();
            $table->unsignedInteger('sequence_number');
            $table->string('status', 30)->index();
            $table->foreignId('outbound_message_id')
                ->nullable()
                ->constrained('messages')
                ->nullOnDelete();
            $table->timestamp('processing_started_at')->nullable();
            $table->string('error_message', 1000)->nullable();
            $table->timestamps();

            $table->unique(
                ['bot_constructor_execution_id', 'sequence_number'],
                'bot_constructor_execution_block_runs_sequence_unique',
            );
            $table->index('bot_constructor_execution_id');
            $table->index('dialog_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_constructor_execution_block_runs');
        Schema::dropIfExists('bot_constructor_dialog_states');

        if (Schema::hasTable('bot_constructor_executions')) {
            Schema::table('bot_constructor_executions', function (Blueprint $table): void {
                $table->dropForeign('bot_constructor_executions_started_by_arrow_run_fk');
            });
        }

        Schema::dropIfExists('bot_constructor_arrow_runs');
        Schema::dropIfExists('bot_constructor_executions');
        Schema::dropIfExists('bot_constructor_arrows');
        Schema::dropIfExists('bot_constructor_constants');
    }
};
