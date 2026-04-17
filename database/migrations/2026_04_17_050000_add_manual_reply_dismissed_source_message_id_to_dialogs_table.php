<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dialogs', function (Blueprint $table): void {
            $table->foreignId('manual_reply_dismissed_source_message_id')
                ->nullable()
                ->after('pending_auto_reply_source_message_id')
                ->constrained('messages')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('dialogs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('manual_reply_dismissed_source_message_id');
        });
    }
};
