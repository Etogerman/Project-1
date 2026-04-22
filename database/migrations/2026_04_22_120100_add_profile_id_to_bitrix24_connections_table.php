<?php

use App\Services\Bitrix24\BackfillBitrix24ConnectionProfilesAction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bitrix24_connections', function (Blueprint $table): void {
            $table->foreignId('profile_id')
                ->nullable()
                ->after('id')
                ->constrained('bitrix24_profiles')
                ->nullOnDelete();

            $table->dropUnique('bitrix24_connections_portal_domain_unique');
        });

        app(BackfillBitrix24ConnectionProfilesAction::class)->handle();
    }

    public function down(): void
    {
        Schema::table('bitrix24_connections', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('profile_id');
            $table->unique('portal_domain');
        });
    }
};
