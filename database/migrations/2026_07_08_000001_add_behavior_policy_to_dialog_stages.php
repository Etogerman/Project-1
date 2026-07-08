<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dialog_stages') || Schema::hasColumn('dialog_stages', 'behavior_policy')) {
            return;
        }

        Schema::table('dialog_stages', function (Blueprint $table): void {
            $table->string('behavior_policy', 32)
                ->default('standard')
                ->after('is_seeded')
                ->index();
        });

        DB::table('dialog_stages')
            ->whereNull('behavior_policy')
            ->orWhere('behavior_policy', '')
            ->update(['behavior_policy' => 'standard']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('dialog_stages') || ! Schema::hasColumn('dialog_stages', 'behavior_policy')) {
            return;
        }

        Schema::table('dialog_stages', function (Blueprint $table): void {
            $table->dropColumn('behavior_policy');
        });
    }
};
