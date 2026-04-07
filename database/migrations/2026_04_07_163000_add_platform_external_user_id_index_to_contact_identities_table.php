<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_identities', function (Blueprint $table): void {
            $table->index(
                ['platform', 'external_user_id'],
                'contact_identities_platform_external_user_id_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('contact_identities', function (Blueprint $table): void {
            $table->dropIndex('contact_identities_platform_external_user_id_index');
        });
    }
};
