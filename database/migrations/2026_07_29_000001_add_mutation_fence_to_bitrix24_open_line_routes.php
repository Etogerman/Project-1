<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bitrix24_open_line_routes', function (Blueprint $table): void {
            $table->uuid('mutation_operation_id')->nullable()->after('last_error_at');
            $table->unsignedBigInteger('mutation_state_version')->default(0)->after('mutation_operation_id');
            $table->timestampTz('mutation_lease_expires_at')->nullable()->after('mutation_state_version');
            $table->index(
                ['mutation_operation_id', 'mutation_state_version'],
                'b24_ol_routes_mutation_fence_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('bitrix24_open_line_routes', function (Blueprint $table): void {
            $table->dropIndex('b24_ol_routes_mutation_fence_index');
            $table->dropColumn([
                'mutation_operation_id',
                'mutation_state_version',
                'mutation_lease_expires_at',
            ]);
        });
    }
};
