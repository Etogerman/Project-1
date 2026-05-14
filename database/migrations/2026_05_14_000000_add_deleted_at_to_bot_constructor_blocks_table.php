<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_constructor_blocks', function (Blueprint $table): void {
            if (! Schema::hasColumn('bot_constructor_blocks', 'deleted_at')) {
                $table->softDeletes()->after('updated_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bot_constructor_blocks', function (Blueprint $table): void {
            if (Schema::hasColumn('bot_constructor_blocks', 'deleted_at')) {
                $table->dropSoftDeletes();
            }
        });
    }
};
