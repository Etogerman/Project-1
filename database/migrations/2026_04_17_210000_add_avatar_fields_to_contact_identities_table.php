<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_identities', function (Blueprint $table): void {
            $table->string('avatar_path')->nullable()->after('external_username');
            $table->timestamp('avatar_updated_at')->nullable()->after('avatar_path');
        });
    }

    public function down(): void
    {
        Schema::table('contact_identities', function (Blueprint $table): void {
            $table->dropColumn(['avatar_path', 'avatar_updated_at']);
        });
    }
};
