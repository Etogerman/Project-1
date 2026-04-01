<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bitrix24_connections', function (Blueprint $table): void {
            $table->id();
            $table->string('portal_domain')->unique();
            $table->string('application_name')->nullable();
            $table->string('client_id')->nullable();
            $table->string('member_id')->nullable()->index();
            $table->string('application_token')->nullable()->index();
            $table->string('status', 32)->default('needs_reinstall')->index();
            $table->text('access_token_encrypted')->nullable();
            $table->text('refresh_token_encrypted')->nullable();
            $table->timestampTz('access_token_expires_at')->nullable();
            $table->jsonb('scope')->nullable();
            $table->text('client_endpoint')->nullable();
            $table->text('server_endpoint')->nullable();
            $table->jsonb('install_payload')->nullable();
            $table->timestampTz('installed_at')->nullable();
            $table->timestampTz('last_refreshed_at')->nullable();
            $table->timestampTz('last_install_callback_at')->nullable();
            $table->timestampTz('last_events_callback_at')->nullable();
            $table->timestampTz('last_openlines_callback_at')->nullable();
            $table->timestampTz('last_error_at')->nullable();
            $table->text('last_error_message')->nullable();
            $table->timestampsTz();

            $table->index('portal_domain');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bitrix24_connections');
    }
};
