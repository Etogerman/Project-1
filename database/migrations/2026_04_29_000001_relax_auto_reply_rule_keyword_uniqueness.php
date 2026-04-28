<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table "auto_reply_rules" drop constraint if exists "auto_reply_rules_channel_scope_keyword_unique"');
        DB::statement('drop index if exists "auto_reply_rules_channel_scope_keyword_unique"');

        Schema::table('auto_reply_rules', function (Blueprint $table): void {
            $table->index(
                ['channel_id', 'match_scope', 'normalized_keyword'],
                'auto_reply_rules_channel_scope_keyword_index',
            );
        });
    }

    public function down(): void
    {
        DB::statement('drop index if exists "auto_reply_rules_channel_scope_keyword_index"');

        Schema::table('auto_reply_rules', function (Blueprint $table): void {
            $table->unique(
                ['channel_id', 'match_scope', 'normalized_keyword'],
                'auto_reply_rules_channel_scope_keyword_unique',
            );
        });
    }
};
