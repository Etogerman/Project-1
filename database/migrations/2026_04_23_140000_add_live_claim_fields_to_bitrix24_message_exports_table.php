<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $hasLiveBatchUuid = Schema::hasColumn('bitrix24_message_exports', 'live_batch_uuid');
        $hasLiveClaimUuid = Schema::hasColumn('bitrix24_message_exports', 'live_claim_uuid');
        $hasLiveClaimedAt = Schema::hasColumn('bitrix24_message_exports', 'live_claimed_at');
        $hasLiveClaimExpiresAt = Schema::hasColumn('bitrix24_message_exports', 'live_claim_expires_at');

        if ($hasLiveBatchUuid && $hasLiveClaimUuid && $hasLiveClaimedAt && $hasLiveClaimExpiresAt) {
            return;
        }

        Schema::table('bitrix24_message_exports', function (Blueprint $table) use (
            $hasLiveBatchUuid,
            $hasLiveClaimUuid,
            $hasLiveClaimedAt,
            $hasLiveClaimExpiresAt,
        ): void {
            if (! $hasLiveBatchUuid) {
                $table->uuid('live_batch_uuid')->nullable()->after('export_status')->index();
            }

            if (! $hasLiveClaimUuid) {
                $table->uuid('live_claim_uuid')->nullable()->after('live_batch_uuid')->index();
            }

            if (! $hasLiveClaimedAt) {
                $table->timestampTz('live_claimed_at')->nullable()->after('live_claim_uuid');
            }

            if (! $hasLiveClaimExpiresAt) {
                $table->timestampTz('live_claim_expires_at')->nullable()->after('live_claimed_at');
            }
        });
    }

    public function down(): void
    {
        $columnsToDrop = array_values(array_filter([
            Schema::hasColumn('bitrix24_message_exports', 'live_batch_uuid') ? 'live_batch_uuid' : null,
            Schema::hasColumn('bitrix24_message_exports', 'live_claim_uuid') ? 'live_claim_uuid' : null,
            Schema::hasColumn('bitrix24_message_exports', 'live_claimed_at') ? 'live_claimed_at' : null,
            Schema::hasColumn('bitrix24_message_exports', 'live_claim_expires_at') ? 'live_claim_expires_at' : null,
        ]));

        if ($columnsToDrop === []) {
            return;
        }

        Schema::table('bitrix24_message_exports', function (Blueprint $table) use ($columnsToDrop): void {
            $table->dropColumn($columnsToDrop);
        });
    }
};
