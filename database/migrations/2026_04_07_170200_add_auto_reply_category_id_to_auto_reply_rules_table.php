<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auto_reply_rules', function (Blueprint $table): void {
            $table->foreignId('auto_reply_category_id')
                ->nullable()
                ->after('name')
                ->constrained('auto_reply_categories');
        });
    }

    public function down(): void
    {
        Schema::table('auto_reply_rules', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('auto_reply_category_id');
        });
    }
};
