<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $hasResolvedCrmEntityType = Schema::hasColumn('bitrix24_message_exports', 'resolved_crm_entity_type');
        $hasResolvedCrmEntityId = Schema::hasColumn('bitrix24_message_exports', 'resolved_crm_entity_id');

        if ($hasResolvedCrmEntityType && $hasResolvedCrmEntityId) {
            return;
        }

        Schema::table('bitrix24_message_exports', function (Blueprint $table) use (
            $hasResolvedCrmEntityType,
            $hasResolvedCrmEntityId,
        ): void {
            if (! $hasResolvedCrmEntityType) {
                $table->string('resolved_crm_entity_type', 32)->nullable()->after('resolved_bitrix_chat_id');
            }

            if (! $hasResolvedCrmEntityId) {
                $table->string('resolved_crm_entity_id')->nullable()->after('resolved_crm_entity_type');
            }
        });
    }

    public function down(): void
    {
        $columnsToDrop = array_values(array_filter([
            Schema::hasColumn('bitrix24_message_exports', 'resolved_crm_entity_type') ? 'resolved_crm_entity_type' : null,
            Schema::hasColumn('bitrix24_message_exports', 'resolved_crm_entity_id') ? 'resolved_crm_entity_id' : null,
        ]));

        if ($columnsToDrop === []) {
            return;
        }

        Schema::table('bitrix24_message_exports', function (Blueprint $table) use ($columnsToDrop): void {
            $table->dropColumn($columnsToDrop);
        });
    }
};
