<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auto_reply_rules', function (Blueprint $table): void {
            $table->string('max_button_type')
                ->nullable()
                ->after('telegram_button_type');
        });
    }

    public function down(): void
    {
        Schema::table('auto_reply_rules', function (Blueprint $table): void {
            $table->dropColumn('max_button_type');
        });
    }
};
