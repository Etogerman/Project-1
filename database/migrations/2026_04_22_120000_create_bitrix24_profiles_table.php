<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bitrix24_profiles', function (Blueprint $table): void {
            $table->id();
            $table->string('portal_domain');
            $table->string('profile_key');
            $table->string('profile_type', 32);
            $table->string('display_name');
            $table->string('client_id')->nullable();
            $table->string('application_code')->nullable();
            $table->text('callback_base_url');
            $table->timestampsTz();

            $table->unique(['portal_domain', 'profile_key']);
            $table->unique('callback_base_url');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bitrix24_profiles');
    }
};
