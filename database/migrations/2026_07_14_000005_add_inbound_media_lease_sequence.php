<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_attachments', function (Blueprint $table): void {
            $table->unsignedInteger('media_download_lease_sequence')
                ->default(0)
                ->after('media_download_attempts');
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $this->assertRollbackSafe();

            Schema::table('message_attachments', function (Blueprint $table): void {
                $table->dropColumn('media_download_lease_sequence');
            });
        });
    }

    private function assertRollbackSafe(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('LOCK TABLE message_attachments IN ACCESS EXCLUSIVE MODE');

            foreach (['media_download_storage_ledgers', 'media_download_traffic_ledgers'] as $table) {
                if (Schema::hasTable($table)) {
                    DB::statement("LOCK TABLE {$table} IN ACCESS EXCLUSIVE MODE");
                }
            }
        }

        $hasClaimToken = Schema::hasColumn('message_attachments', 'media_download_claim_token');
        $activeDownloads = DB::table('message_attachments')
            ->where(function ($query) use ($hasClaimToken): void {
                $query->where('download_status', 'downloading');

                if ($hasClaimToken) {
                    $query->orWhereNotNull('media_download_claim_token');
                }
            })
            ->exists();
        $activeStorageReservations = Schema::hasTable('media_download_storage_ledgers')
            && DB::table('media_download_storage_ledgers')->where('status', 'reserved')->exists();
        $activeTrafficReservations = Schema::hasTable('media_download_traffic_ledgers')
            && DB::table('media_download_traffic_ledgers')->where('status', 'reserved')->exists();

        if ($activeDownloads || $activeStorageReservations || $activeTrafficReservations) {
            throw new RuntimeException(
                'Cannot roll back unified inbound media lease sequence while active media work exists.'
            );
        }
    }
};
