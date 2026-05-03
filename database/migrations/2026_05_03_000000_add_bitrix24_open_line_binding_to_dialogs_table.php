<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dialogs', function (Blueprint $table): void {
            $table->string('bitrix24_open_line_user_code_override')->nullable()->after('bitrix24_open_line_route_id')->index();
            $table->string('bitrix24_open_line_resolved_chat_id_override')->nullable()->after('bitrix24_open_line_user_code_override')->index();
            $table->timestamp('bitrix24_open_line_binding_verified_at')->nullable()->after('bitrix24_open_line_resolved_chat_id_override');
        });
    }

    public function down(): void
    {
        Schema::table('dialogs', function (Blueprint $table): void {
            $table->dropIndex(['bitrix24_open_line_user_code_override']);
            $table->dropIndex(['bitrix24_open_line_resolved_chat_id_override']);
            $table->dropColumn([
                'bitrix24_open_line_user_code_override',
                'bitrix24_open_line_resolved_chat_id_override',
                'bitrix24_open_line_binding_verified_at',
            ]);
        });
    }
};
