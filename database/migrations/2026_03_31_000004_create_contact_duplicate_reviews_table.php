<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_duplicate_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->string('phone_normalized', 32);
            $table->string('review_type', 50);
            $table->jsonb('candidate_root_contact_ids')->nullable();
            $table->foreignId('trigger_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->string('status', 20)->default('open');
            $table->text('reason')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['contact_id', 'status']);
        });

        DB::statement(
            "CREATE UNIQUE INDEX contact_duplicate_reviews_open_unique
            ON contact_duplicate_reviews (contact_id, review_type, phone_normalized)
            WHERE status = 'open'"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS contact_duplicate_reviews_open_unique');

        Schema::dropIfExists('contact_duplicate_reviews');
    }
};
