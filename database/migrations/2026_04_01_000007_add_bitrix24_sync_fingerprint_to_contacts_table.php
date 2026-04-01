<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->string('bitrix24_sync_fingerprint', 64)
                ->nullable()
                ->after('bitrix24_sync_pending')
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropIndex(['bitrix24_sync_fingerprint']);
            $table->dropColumn('bitrix24_sync_fingerprint');
        });
    }
};
