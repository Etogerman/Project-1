<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_timeline_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->string('event_type');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body')->nullable();
            $table->jsonb('payload')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['contact_id', 'occurred_at', 'id'], 'contact_timeline_events_contact_occurred_idx');
            $table->index(['contact_id', 'event_type'], 'contact_timeline_events_contact_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_timeline_events');
    }
};
