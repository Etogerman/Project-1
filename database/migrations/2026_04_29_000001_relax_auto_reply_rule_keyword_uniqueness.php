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
        $duplicate = DB::table('auto_reply_rules')
            ->select(['channel_id', 'match_scope', 'normalized_keyword'])
            ->selectRaw('count(*) as duplicate_count')
            ->whereNotNull('channel_id')
            ->whereNotNull('match_scope')
            ->whereNotNull('normalized_keyword')
            ->groupBy('channel_id', 'match_scope', 'normalized_keyword')
            ->havingRaw('count(*) > 1')
            ->first();

        if ($duplicate !== null) {
            throw new \RuntimeException(sprintf(
                'Cannot restore auto_reply_rules_channel_scope_keyword_unique while duplicate auto-reply rules exist for channel_id=%s, match_scope=%s, normalized_keyword=%s. Resolve conditional duplicate rules before rolling this migration back.',
                $duplicate->channel_id,
                $duplicate->match_scope,
                $duplicate->normalized_keyword,
            ));
        }

        DB::statement('drop index if exists "auto_reply_rules_channel_scope_keyword_index"');

        Schema::table('auto_reply_rules', function (Blueprint $table): void {
            $table->unique(
                ['channel_id', 'match_scope', 'normalized_keyword'],
                'auto_reply_rules_channel_scope_keyword_unique',
            );
        });
    }
};
