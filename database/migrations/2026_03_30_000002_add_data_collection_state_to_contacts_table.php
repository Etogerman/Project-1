<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->string('data_collection_status')->nullable()->after('city');
            $table->string('data_collection_current_field')->nullable()->after('data_collection_status');
            $table->timestamp('data_collection_started_at')->nullable()->after('data_collection_current_field');
            $table->timestamp('data_collection_completed_at')->nullable()->after('data_collection_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropColumn([
                'data_collection_status',
                'data_collection_current_field',
                'data_collection_started_at',
                'data_collection_completed_at',
            ]);
        });
    }
};
