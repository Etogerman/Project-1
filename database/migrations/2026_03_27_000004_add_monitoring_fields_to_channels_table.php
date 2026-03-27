<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            $table->timestamp('last_webhook_received_at')->nullable()->after('bot_profile_url');
            $table->timestamp('last_reply_sent_at')->nullable()->after('last_webhook_received_at');
            $table->timestamp('last_error_at')->nullable()->after('last_reply_sent_at');
            $table->text('last_error_message')->nullable()->after('last_error_at');
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            $table->dropColumn([
                'last_webhook_received_at',
                'last_reply_sent_at',
                'last_error_at',
                'last_error_message',
            ]);
        });
    }
};
