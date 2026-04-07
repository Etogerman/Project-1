<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('auto_reply_rules', 'category')) {
            return;
        }

        Schema::table('auto_reply_rules', function (Blueprint $table): void {
            $table->dropColumn('category');
        });
    }

    public function down(): void
    {
        //
    }
};
