<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->string('text_format')->default('plain_text')->after('text');
            $table->text('source_text')->nullable()->after('text_format');
        });

        DB::table('messages')
            ->whereNull('text_format')
            ->update([
                'text_format' => 'plain_text',
                'source_text' => null,
            ]);
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropColumn(['text_format', 'source_text']);
        });
    }
};
