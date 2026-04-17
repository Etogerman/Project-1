<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_identities', function (Blueprint $table): void {
            $table->string('display_name')->nullable()->after('external_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('contact_identities', function (Blueprint $table): void {
            $table->dropColumn('display_name');
        });
    }
};
