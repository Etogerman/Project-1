<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->foreignId('dialog_id')
                ->nullable()
                ->after('contact_id')
                ->constrained('dialogs')
                ->nullOnDelete();
            $table->string('sent_by_type', 20)->nullable()->after('message_kind');
            $table->foreignId('sent_by_user_id')
                ->nullable()
                ->after('sent_by_type')
                ->constrained('users')
                ->nullOnDelete();
            $table->string('sent_by_system_code', 64)->nullable()->after('sent_by_user_id');

            $table->index(['dialog_id', 'received_at']);
            $table->index('sent_by_type');
            $table->index('sent_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropIndex(['dialog_id', 'received_at']);
            $table->dropIndex(['sent_by_type']);
            $table->dropIndex(['sent_by_user_id']);

            $table->dropConstrainedForeignId('sent_by_user_id');
            $table->dropColumn('sent_by_system_code');
            $table->dropColumn('sent_by_type');
            $table->dropConstrainedForeignId('dialog_id');
        });
    }
};
