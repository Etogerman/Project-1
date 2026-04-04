<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scenario_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scenario_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('status', 20)->index();
            $table->jsonb('schema_payload')->default(DB::raw("'{}'::jsonb"));
            $table->timestamps();

            $table->unique(['scenario_id', 'version_number'], 'scenario_versions_scenario_version_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scenario_versions');
    }
};
