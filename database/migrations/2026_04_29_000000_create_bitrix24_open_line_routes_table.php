<?php

use App\Services\Bitrix24\BackfillBitrix24OpenLineRoutesAction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bitrix24_open_line_routes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bitrix24_profile_id')->constrained('bitrix24_profiles')->restrictOnDelete();
            $table->foreignId('channel_id')->constrained()->restrictOnDelete();
            $table->string('portal_domain');
            $table->string('profile_key');
            $table->string('channel_type', 32);
            $table->string('connector_code', 64)->nullable();
            $table->string('line_id', 64)->nullable();
            $table->string('line_owner_key', 512)->nullable()->unique();
            $table->string('source_id', 64)->nullable();
            $table->string('status', 32)->default('inactive');
            $table->string('last_error_message', 1024)->nullable();
            $table->timestampTz('last_error_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['bitrix24_profile_id', 'channel_id']);
            $table->index(['portal_domain', 'line_id']);
            $table->index(['connector_code', 'line_id']);
            $table->index('status');
        });

        Schema::table('dialogs', function (Blueprint $table): void {
            $table->foreignId('bitrix24_open_line_route_id')
                ->nullable()
                ->after('bitrix24_live_status')
                ->constrained('bitrix24_open_line_routes')
                ->restrictOnDelete();
        });

        app(BackfillBitrix24OpenLineRoutesAction::class)->handle();
    }

    public function down(): void
    {
        Schema::table('dialogs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('bitrix24_open_line_route_id');
        });

        Schema::dropIfExists('bitrix24_open_line_routes');
    }
};
