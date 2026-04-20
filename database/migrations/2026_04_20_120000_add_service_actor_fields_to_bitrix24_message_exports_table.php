<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $hasTransportMethod = Schema::hasColumn('bitrix24_message_exports', 'transport_method');
        $hasResolvedBitrixChatId = Schema::hasColumn('bitrix24_message_exports', 'resolved_bitrix_chat_id');
        $hasBitrixRemoteMessageId = Schema::hasColumn('bitrix24_message_exports', 'bitrix_remote_message_id');
        $hasFailureCode = Schema::hasColumn('bitrix24_message_exports', 'failure_code');
        $hasFailureUncertain = Schema::hasColumn('bitrix24_message_exports', 'failure_uncertain');

        if ($hasTransportMethod && $hasResolvedBitrixChatId && $hasBitrixRemoteMessageId && $hasFailureCode && $hasFailureUncertain) {
            return;
        }

        Schema::table('bitrix24_message_exports', function (Blueprint $table) use (
            $hasTransportMethod,
            $hasResolvedBitrixChatId,
            $hasBitrixRemoteMessageId,
            $hasFailureCode,
            $hasFailureUncertain,
        ): void {
            if (! $hasTransportMethod) {
                $table->string('transport_method', 64)->nullable()->after('export_status');
            }

            if (! $hasResolvedBitrixChatId) {
                $table->string('resolved_bitrix_chat_id')->nullable()->after('transport_method');
            }

            if (! $hasBitrixRemoteMessageId) {
                $table->string('bitrix_remote_message_id')->nullable()->after('resolved_bitrix_chat_id');
            }

            if (! $hasFailureCode) {
                $table->string('failure_code', 64)->nullable()->after('failed_at');
            }

            if (! $hasFailureUncertain) {
                $table->boolean('failure_uncertain')->default(false)->after('failure_code');
            }
        });
    }

    public function down(): void
    {
        $columnsToDrop = array_values(array_filter([
            Schema::hasColumn('bitrix24_message_exports', 'transport_method') ? 'transport_method' : null,
            Schema::hasColumn('bitrix24_message_exports', 'resolved_bitrix_chat_id') ? 'resolved_bitrix_chat_id' : null,
            Schema::hasColumn('bitrix24_message_exports', 'bitrix_remote_message_id') ? 'bitrix_remote_message_id' : null,
            Schema::hasColumn('bitrix24_message_exports', 'failure_code') ? 'failure_code' : null,
            Schema::hasColumn('bitrix24_message_exports', 'failure_uncertain') ? 'failure_uncertain' : null,
        ]));

        if ($columnsToDrop === []) {
            return;
        }

        Schema::table('bitrix24_message_exports', function (Blueprint $table) use ($columnsToDrop): void {
            $table->dropColumn($columnsToDrop);
        });
    }
};
