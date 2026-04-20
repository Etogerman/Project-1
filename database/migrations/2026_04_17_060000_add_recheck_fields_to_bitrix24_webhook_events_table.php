<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bitrix24_webhook_events', function (Blueprint $table): void {
            $table->timestampTz('recheck_scheduled_at')->nullable()->after('failure_reason');
            $table->timestampTz('recheck_attempted_at')->nullable()->after('recheck_scheduled_at');
        });
    }

    public function down(): void
    {
        Schema::table('bitrix24_webhook_events', function (Blueprint $table): void {
            $table->dropColumn([
                'recheck_scheduled_at',
                'recheck_attempted_at',
            ]);
        });
    }
};
