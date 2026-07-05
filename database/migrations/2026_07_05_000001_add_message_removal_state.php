<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $lastRemoveEventAfter = Schema::hasColumn('messages', 'last_edit_provider_event_key')
            ? 'last_edit_provider_event_key'
            : 'provider_event_key';
        $removedAtAfter = Schema::hasColumn('messages', 'edit_count')
            ? 'edit_count'
            : 'received_at';

        Schema::table('messages', function (Blueprint $table) use ($lastRemoveEventAfter, $removedAtAfter): void {
            $table->string('last_remove_provider_event_key')->nullable()->after($lastRemoveEventAfter);
            $table->timestamp('removed_at')->nullable()->after($removedAtAfter);
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
