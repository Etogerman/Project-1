<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbound_media_admission_locks', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->timestamps();
        });

        DB::table('inbound_media_admission_locks')->insert([
            'id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::create('inbound_media_queue_cursors', function (Blueprint $table): void {
            $table->unsignedBigInteger('channel_id')->primary();
            $table->unsignedTinyInteger('manual_claim_streak')->default(0);
            $table->timestamps();
            $table->foreign('channel_id')->references('id')->on('channels')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $this->assertRollbackSafe();

            Schema::dropIfExists('inbound_media_queue_cursors');
            Schema::dropIfExists('inbound_media_admission_locks');
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
                'Cannot roll back unified inbound media admission tables while active media work exists.'
            );
        }
    }
};
