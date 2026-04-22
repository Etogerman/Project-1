<?php

use App\Services\Bitrix24\BackfillBitrix24ProfileRoutingFieldsAction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bitrix24_profiles', function (Blueprint $table): void {
            $table->string('telegram_source_id')->nullable()->after('callback_base_url');
            $table->string('max_source_id')->nullable()->after('telegram_source_id');
            $table->string('telegram_connector_code')->nullable()->after('max_source_id');
            $table->string('max_connector_code')->nullable()->after('telegram_connector_code');
            $table->string('telegram_line_id')->nullable()->after('max_connector_code');
            $table->string('max_line_id')->nullable()->after('telegram_line_id');
        });

        app(BackfillBitrix24ProfileRoutingFieldsAction::class)->handle();
    }

    public function down(): void
    {
        Schema::table('bitrix24_profiles', function (Blueprint $table): void {
            $table->dropColumn([
                'telegram_source_id',
                'max_source_id',
                'telegram_connector_code',
                'max_connector_code',
                'telegram_line_id',
                'max_line_id',
            ]);
        });
    }
};
