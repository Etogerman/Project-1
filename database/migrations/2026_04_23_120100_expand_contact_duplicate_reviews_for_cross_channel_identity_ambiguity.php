<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS contact_duplicate_reviews_open_unique');
        DB::statement('ALTER TABLE contact_duplicate_reviews ALTER COLUMN phone_normalized DROP NOT NULL');

        Schema::table('contact_duplicate_reviews', function (Blueprint $table): void {
            $table->string('identity_key')->nullable()->after('phone_normalized');
            $table->foreignId('routed_contact_id')->nullable()->after('contact_id')->constrained('contacts')->nullOnDelete();
            $table->jsonb('context_payload')->nullable()->after('candidate_root_contact_ids');
        });

        DB::statement(
            "CREATE UNIQUE INDEX contact_duplicate_reviews_phone_open_unique
            ON contact_duplicate_reviews (contact_id, review_type, phone_normalized)
            WHERE status = 'open' AND phone_normalized IS NOT NULL"
        );

        DB::statement(
            "CREATE UNIQUE INDEX contact_duplicate_reviews_identity_open_unique
            ON contact_duplicate_reviews (review_type, identity_key)
            WHERE status = 'open' AND identity_key IS NOT NULL"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS contact_duplicate_reviews_identity_open_unique');
        DB::statement('DROP INDEX IF EXISTS contact_duplicate_reviews_phone_open_unique');

        // Old schema cannot represent reviews without phone_normalized.
        DB::table('contact_duplicate_reviews')
            ->whereNull('phone_normalized')
            ->delete();

        Schema::table('contact_duplicate_reviews', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('routed_contact_id');
            $table->dropColumn([
                'identity_key',
                'context_payload',
            ]);
        });

        DB::statement('ALTER TABLE contact_duplicate_reviews ALTER COLUMN phone_normalized SET NOT NULL');

        DB::statement(
            "CREATE UNIQUE INDEX contact_duplicate_reviews_open_unique
            ON contact_duplicate_reviews (contact_id, review_type, phone_normalized)
            WHERE status = 'open'"
        );
    }
};
