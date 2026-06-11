<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_tasks')) {
            return;
        }

        $now = now();
        $exists = DB::table('ai_tasks')->where('key', 'scenario_v3_ai_analysis')->exists();

        DB::table('ai_tasks')->updateOrInsert(
            ['key' => 'scenario_v3_ai_analysis'],
            $exists
                ? [
                    'name' => 'V3 ИИ-анализ',
                    'is_active' => true,
                    'updated_at' => $now,
                ]
                : [
                    'name' => 'V3 ИИ-анализ',
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_tasks')) {
            return;
        }

        DB::table('ai_tasks')
            ->where('key', 'scenario_v3_ai_analysis')
            ->delete();
    }
};
