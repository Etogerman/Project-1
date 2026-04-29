<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            $table->string('connection_status', 32)->default('not_connected')->after('is_active');
            $table->string('webhook_status', 32)->default('not_installed')->after('connection_status');
            $table->timestamp('connection_checked_at')->nullable()->after('webhook_status');
            $table->string('connection_error_message', 1000)->nullable()->after('connection_checked_at');
            $table->string('provider_webhook_url', 2048)->nullable()->after('connection_error_message');
            $table->string('expected_webhook_url', 2048)->nullable()->after('provider_webhook_url');
        });

        DB::table('channels')
            ->where('platform', 'telegram')
            ->where('connection_type', 'bot')
            ->update([
                'connection_status' => 'not_connected',
                'webhook_status' => 'not_installed',
                'connection_checked_at' => null,
                'connection_error_message' => 'Проверка ещё не выполнялась',
                'provider_webhook_url' => null,
                'expected_webhook_url' => null,
            ]);

        DB::table('channels')
            ->where(fn ($query) => $query
                ->where('platform', '!=', 'telegram')
                ->orWhere('connection_type', '!=', 'bot'))
            ->update([
                'connection_status' => 'unsupported',
                'webhook_status' => 'unsupported',
                'connection_checked_at' => null,
                'connection_error_message' => 'Проверка подключения для этого типа канала пока не поддерживается',
                'provider_webhook_url' => null,
                'expected_webhook_url' => null,
            ]);
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            $table->dropColumn([
                'connection_status',
                'webhook_status',
                'connection_checked_at',
                'connection_error_message',
                'provider_webhook_url',
                'expected_webhook_url',
            ]);
        });
    }
};
