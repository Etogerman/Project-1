<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dialogs', function (Blueprint $table): void {
            $table->string('bot_subscription_status')->nullable()->after('pending_auto_reply_source_message_id');
            $table->timestamp('bot_subscription_changed_at')->nullable()->after('bot_subscription_status');
            $table->foreignId('bot_subscription_source_message_id')
                ->nullable()
                ->after('bot_subscription_changed_at')
                ->constrained('messages')
                ->nullOnDelete();

            $table->index('bot_subscription_status');
            $table->index('bot_subscription_changed_at');
        });
    }

    public function down(): void
    {
        Schema::table('dialogs', function (Blueprint $table): void {
            $table->dropIndex(['bot_subscription_status']);
            $table->dropIndex(['bot_subscription_changed_at']);
            $table->dropConstrainedForeignId('bot_subscription_source_message_id');
            $table->dropColumn([
                'bot_subscription_status',
                'bot_subscription_changed_at',
            ]);
        });
    }
};
