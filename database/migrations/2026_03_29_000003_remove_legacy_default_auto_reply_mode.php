<?php

use App\Models\Channel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('channels')
            ->whereNull('auto_reply_mode')
            ->orWhere('auto_reply_mode', Channel::AUTO_REPLY_MODE_LEGACY_DEFAULT)
            ->update([
                'auto_reply_mode' => Channel::AUTO_REPLY_MODE_RULES_ONLY,
            ]);

        DB::statement(
            "ALTER TABLE channels ALTER COLUMN auto_reply_mode SET DEFAULT '".Channel::AUTO_REPLY_MODE_RULES_ONLY."'"
        );
    }

    public function down(): void
    {
        DB::statement(
            "ALTER TABLE channels ALTER COLUMN auto_reply_mode SET DEFAULT '".Channel::AUTO_REPLY_MODE_LEGACY_DEFAULT."'"
        );
    }
};
