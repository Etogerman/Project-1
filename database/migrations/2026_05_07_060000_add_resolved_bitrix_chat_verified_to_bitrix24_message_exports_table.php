<?php

use App\Models\Bitrix24MessageExport;
use App\Models\Message;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SUCCESSFUL_SEND_EXPECTED_REPLY_WINDOW_SECONDS = 1800;

    public function up(): void
    {
        if (! Schema::hasTable('bitrix24_message_exports')) {
            return;
        }

        if (! Schema::hasColumn('bitrix24_message_exports', 'resolved_bitrix_chat_verified')) {
            Schema::table('bitrix24_message_exports', function (Blueprint $table): void {
                $table->boolean('resolved_bitrix_chat_verified')
                    ->default(false)
                    ->after('resolved_bitrix_chat_id');
            });
        }

        if (! Schema::hasColumn('bitrix24_message_exports', 'resolved_bitrix_chat_verified')) {
            return;
        }

        if (
            ! Schema::hasTable('messages')
            || ! Schema::hasColumns('messages', ['direction', 'sent_by_type', 'message_kind'])
        ) {
            return;
        }

        DB::table('bitrix24_message_exports')
            ->where('export_mode', Bitrix24MessageExport::MODE_LIVE)
            ->where('export_status', Bitrix24MessageExport::STATUS_EXPORTED)
            ->where('transport_method', Bitrix24MessageExport::TRANSPORT_IMCONNECTOR_SEND_MESSAGES)
            ->whereNotNull('resolved_bitrix_chat_id')
            ->where('resolved_bitrix_chat_id', '<>', '')
            ->where('exported_at', '>=', now()->subSeconds(self::SUCCESSFUL_SEND_EXPECTED_REPLY_WINDOW_SECONDS))
            ->whereIn('message_id', function ($query): void {
                $query
                    ->select('id')
                    ->from('messages')
                    ->where('direction', Message::DIRECTION_INBOUND)
                    ->where('sent_by_type', Message::SENT_BY_TYPE_CONTACT)
                    ->whereIn('message_kind', [
                        Message::KIND_INBOUND_USER,
                        Message::KIND_INBOUND_CONTACT_SHARE,
                    ]);
            })
            ->update([
                'resolved_bitrix_chat_verified' => true,
            ]);
    }

    public function down(): void
    {
        if (
            Schema::hasTable('bitrix24_message_exports')
            && Schema::hasColumn('bitrix24_message_exports', 'resolved_bitrix_chat_verified')
        ) {
            Schema::table('bitrix24_message_exports', function (Blueprint $table): void {
                $table->dropColumn('resolved_bitrix_chat_verified');
            });
        }
    }
};
