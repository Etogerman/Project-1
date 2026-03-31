<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->foreignId('merged_into_contact_id')
                ->nullable()
                ->after('assigned_user_id')
                ->constrained('contacts')
                ->restrictOnDelete();
            $table->timestamp('merged_at')->nullable()->after('merged_into_contact_id');
            $table->string('merge_reason', 50)->nullable()->after('merged_at');
            $table->string('merge_trigger_phone', 32)->nullable()->after('merge_reason');
            $table->string('duplicate_review_status', 20)
                ->default('none')
                ->after('merge_trigger_phone');

            $table->index('merged_into_contact_id');
            $table->index('duplicate_review_status');
        });

        DB::statement(
            'ALTER TABLE contacts ADD CONSTRAINT contacts_merged_into_contact_id_not_self CHECK (merged_into_contact_id IS NULL OR merged_into_contact_id <> id)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE contacts DROP CONSTRAINT IF EXISTS contacts_merged_into_contact_id_not_self');

        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropColumn([
                'duplicate_review_status',
                'merge_trigger_phone',
                'merge_reason',
                'merged_at',
            ]);
            $table->dropConstrainedForeignId('merged_into_contact_id');
        });
    }
};
