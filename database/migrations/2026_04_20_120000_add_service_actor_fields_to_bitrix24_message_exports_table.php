<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bitrix24_message_exports', function (Blueprint $table): void {
            $table->string('transport_method', 64)->nullable()->after('export_status');
            $table->string('resolved_bitrix_chat_id')->nullable()->after('transport_method');
            $table->string('bitrix_remote_message_id')->nullable()->after('resolved_bitrix_chat_id');
            $table->string('failure_code', 64)->nullable()->after('failed_at');
            $table->boolean('failure_uncertain')->default(false)->after('failure_code');
        });
    }

    public function down(): void
    {
        Schema::table('bitrix24_message_exports', function (Blueprint $table): void {
            $table->dropColumn([
                'transport_method',
                'resolved_bitrix_chat_id',
                'bitrix_remote_message_id',
                'failure_code',
                'failure_uncertain',
            ]);
        });
    }
};
