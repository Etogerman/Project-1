<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->string('bitrix24_contact_id')->nullable()->after('duplicate_review_status');
            $table->string('bitrix24_sync_status')
                ->default('not_synced')
                ->after('bitrix24_contact_id');
            $table->timestamp('bitrix24_last_synced_at')->nullable()->after('bitrix24_sync_status');
            $table->timestamp('bitrix24_linked_at')->nullable()->after('bitrix24_last_synced_at');
            $table->boolean('bitrix24_sync_pending')->default(false)->after('bitrix24_linked_at');

            $table->index('bitrix24_contact_id');
            $table->index('bitrix24_sync_status');
            $table->index('bitrix24_sync_pending');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropIndex(['bitrix24_contact_id']);
            $table->dropIndex(['bitrix24_sync_status']);
            $table->dropIndex(['bitrix24_sync_pending']);

            $table->dropColumn([
                'bitrix24_contact_id',
                'bitrix24_sync_status',
                'bitrix24_last_synced_at',
                'bitrix24_linked_at',
                'bitrix24_sync_pending',
            ]);
        });
    }
};
