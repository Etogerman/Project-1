<?php

use App\Models\ScenarioRun;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scenario_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dialog_id')->constrained()->cascadeOnDelete();
            $table->string('scenario_code', 100);
            $table->string('status', 20)->index();
            $table->string('current_step', 100)->nullable();
            $table->jsonb('state_payload')->default(DB::raw("'{}'::jsonb"));
            $table->string('exit_outcome', 100)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        DB::statement(
            "CREATE UNIQUE INDEX scenario_runs_active_dialog_unique
            ON scenario_runs (dialog_id)
            WHERE status = '".ScenarioRun::STATUS_ACTIVE."'"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS scenario_runs_active_dialog_unique');

        Schema::dropIfExists('scenario_runs');
    }
};
