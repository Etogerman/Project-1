<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auto_reply_rules', function (Blueprint $table): void {
            $table->string('telegram_button_type')
                ->nullable()
                ->after('reply_text');
        });
    }

    public function down(): void
    {
        Schema::table('auto_reply_rules', function (Blueprint $table): void {
            $table->dropColumn('telegram_button_type');
        });
    }
};
