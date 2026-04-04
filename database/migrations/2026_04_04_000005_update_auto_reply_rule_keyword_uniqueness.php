<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auto_reply_rules', function (Blueprint $table): void {
            $table->dropUnique('auto_reply_rules_channel_id_normalized_keyword_unique');
            $table->unique(
                ['channel_id', 'match_scope', 'normalized_keyword'],
                'auto_reply_rules_channel_scope_keyword_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('auto_reply_rules', function (Blueprint $table): void {
            $table->dropUnique('auto_reply_rules_channel_scope_keyword_unique');
            $table->unique(
                ['channel_id', 'normalized_keyword'],
                'auto_reply_rules_channel_id_normalized_keyword_unique',
            );
        });
    }
};
