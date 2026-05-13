<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            ! Schema::hasTable('bot_constructor_arrow_runs')
            || ! Schema::hasTable('bot_constructor_execution_block_runs')
            || Schema::hasColumn('bot_constructor_arrow_runs', 'source_execution_block_run_id')
        ) {
            return;
        }

        Schema::table('bot_constructor_arrow_runs', function (Blueprint $table): void {
            $table->unsignedBigInteger('source_execution_block_run_id')->nullable()->after('inbound_message_id');
            $table->index('source_execution_block_run_id', 'bot_constructor_arrow_runs_source_block_run_index');
            $table->foreign('source_execution_block_run_id', 'bot_constructor_arrow_runs_source_block_run_fk')
                ->references('id')
                ->on('bot_constructor_execution_block_runs')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (
            ! Schema::hasTable('bot_constructor_arrow_runs')
            || ! Schema::hasColumn('bot_constructor_arrow_runs', 'source_execution_block_run_id')
        ) {
            return;
        }

        Schema::table('bot_constructor_arrow_runs', function (Blueprint $table): void {
            $table->dropForeign('bot_constructor_arrow_runs_source_block_run_fk');
            $table->dropIndex('bot_constructor_arrow_runs_source_block_run_index');
            $table->dropColumn('source_execution_block_run_id');
        });
    }
};
