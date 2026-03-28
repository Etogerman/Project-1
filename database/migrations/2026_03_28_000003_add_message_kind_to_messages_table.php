<?php

use App\Models\Message;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->string('message_kind')->nullable()->after('direction');
            $table->index('message_kind');
        });

        DB::table('messages')
            ->where('direction', Message::DIRECTION_INBOUND)
            ->update([
                'message_kind' => Message::KIND_INBOUND_USER,
            ]);
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropIndex(['message_kind']);
            $table->dropColumn('message_kind');
        });
    }
};
