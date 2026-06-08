<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var list<array{key: string, name: string}>
     */
    private const STARTER_TASKS = [
        ['key' => 'name_resolution', 'name' => 'Распознавание имени'],
        ['key' => 'address_resolution', 'name' => 'Распознавание адреса'],
        ['key' => 'scenario_v3_ai_analysis', 'name' => 'V3 ИИ-анализ'],
        ['key' => 'other', 'name' => 'Другое'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('ai_tasks')) {
            Schema::create('ai_tasks', function (Blueprint $table): void {
                $table->id();
                $table->string('key', 64)->unique();
                $table->string('name');
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['is_active', 'key']);
            });
        }

        foreach (self::STARTER_TASKS as $task) {
            if (DB::table('ai_tasks')->where('key', $task['key'])->exists()) {
                continue;
            }

            DB::table('ai_tasks')->insert([
                'key' => $task['key'],
                'name' => $task['name'],
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_tasks');
    }
};
