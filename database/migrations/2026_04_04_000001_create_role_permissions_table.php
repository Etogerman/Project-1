<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('role', 20);
            $table->string('permission_key', 120);
            $table->boolean('granted');
            $table->timestamps();

            $table->unique(['role', 'permission_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
    }
};
