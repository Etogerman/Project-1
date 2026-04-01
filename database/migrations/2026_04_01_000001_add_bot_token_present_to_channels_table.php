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
            $table->boolean('bot_token_present')->default(false)->after('credentials');
        });

        Channel::query()
            ->select(['id', 'credentials'])
            ->chunkById(100, function ($channels): void {
                foreach ($channels as $channel) {
                    DB::table('channels')
                        ->where('id', $channel->id)
                        ->update([
                            'bot_token_present' => filled($channel->getToken()),
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            $table->dropColumn('bot_token_present');
        });
    }
};
