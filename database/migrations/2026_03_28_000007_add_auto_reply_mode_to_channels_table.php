<?php

use App\Models\Channel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            $table->string('auto_reply_mode')
                ->default(Channel::AUTO_REPLY_MODE_LEGACY_DEFAULT)
                ->after('bot_profile_url');
        });

        DB::table('channels')
            ->whereNull('auto_reply_mode')
            ->update([
                'auto_reply_mode' => Channel::AUTO_REPLY_MODE_LEGACY_DEFAULT,
            ]);
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            $table->dropColumn('auto_reply_mode');
        });
    }
};
