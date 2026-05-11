<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bitrix24_profiles', function (Blueprint $table): void {
            $table->text('openlines_route_registry_secret_encrypted')->nullable()->after('application_code');
            $table->string('openlines_route_registry_last_status', 32)->nullable()->after('openlines_route_registry_secret_encrypted');
            $table->text('openlines_route_registry_last_error')->nullable()->after('openlines_route_registry_last_status');
            $table->timestampTz('openlines_route_registry_last_checked_at')->nullable()->after('openlines_route_registry_last_error');
            $table->timestampTz('openlines_route_registry_last_published_at')->nullable()->after('openlines_route_registry_last_checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('bitrix24_profiles', function (Blueprint $table): void {
            $table->dropColumn([
                'openlines_route_registry_secret_encrypted',
                'openlines_route_registry_last_status',
                'openlines_route_registry_last_error',
                'openlines_route_registry_last_checked_at',
                'openlines_route_registry_last_published_at',
            ]);
        });
    }
};
