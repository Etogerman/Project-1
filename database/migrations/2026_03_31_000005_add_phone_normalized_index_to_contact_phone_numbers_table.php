<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS contact_phone_numbers_phone_normalized_index ON contact_phone_numbers (phone_normalized)'
            );

            return;
        }

        Schema::table('contact_phone_numbers', function (Blueprint $table): void {
            $table->index('phone_normalized');
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS contact_phone_numbers_phone_normalized_index');

            return;
        }

        Schema::table('contact_phone_numbers', function (Blueprint $table): void {
            $table->dropIndex('contact_phone_numbers_phone_normalized_index');
        });
    }
};
