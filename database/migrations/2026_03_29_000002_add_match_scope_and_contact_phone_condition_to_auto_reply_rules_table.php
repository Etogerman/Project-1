<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auto_reply_rules', function (Blueprint $table): void {
            $table->string('match_scope')
                ->default('exact_keyword')
                ->after('normalized_keyword');
            $table->string('contact_phone_condition')
                ->nullable()
                ->after('match_scope');
        });

        DB::statement('ALTER TABLE auto_reply_rules ALTER COLUMN keyword DROP NOT NULL');
        DB::statement('ALTER TABLE auto_reply_rules ALTER COLUMN normalized_keyword DROP NOT NULL');
    }

    public function down(): void
    {
        DB::statement(
            "UPDATE auto_reply_rules
             SET keyword = '__rollback_any_inbound_' || id,
                 normalized_keyword = '__rollback_any_inbound_' || id
             WHERE keyword IS NULL OR normalized_keyword IS NULL"
        );

        DB::statement('ALTER TABLE auto_reply_rules ALTER COLUMN keyword SET NOT NULL');
        DB::statement('ALTER TABLE auto_reply_rules ALTER COLUMN normalized_keyword SET NOT NULL');

        Schema::table('auto_reply_rules', function (Blueprint $table): void {
            $table->dropColumn(['match_scope', 'contact_phone_condition']);
        });
    }
};
