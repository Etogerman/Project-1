<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->string('last_remove_provider_event_key')->nullable()->after('last_edit_provider_event_key');
            $table->timestamp('removed_at')->nullable()->after('edit_count');
            $table->unsignedInteger('remove_count')->default(0)->after('removed_at');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropColumn([
                'last_remove_provider_event_key',
                'removed_at',
                'remove_count',
            ]);
        });
    }
};
