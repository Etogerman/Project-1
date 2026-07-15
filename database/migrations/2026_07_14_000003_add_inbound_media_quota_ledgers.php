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
            $table->unsignedInteger('media_download_generation')->default(1)->after('media_download_max_bytes');
            $table->unsignedSmallInteger('media_download_attempts')->default(0)->after('media_download_generation');
            $table->string('media_download_trigger', 16)->nullable()->after('media_download_attempts');
            $table->timestamp('media_download_claimed_at')->nullable()->after('media_download_trigger');
            $table->timestamp('media_download_heartbeat_at')->nullable()->after('media_download_claimed_at');
            $table->timestamp('media_download_attempt_deadline_at')->nullable()->after('media_download_heartbeat_at');
            $table->index(
                ['channel_id', 'download_status', 'media_download_heartbeat_at'],
                'message_attachments_media_download_lease_idx',
            );
        });

        Schema::create('media_download_storage_budgets', function (Blueprint $table): void {
            $table->id();
            $table->string('scope_type', 16);
            $table->unsignedBigInteger('scope_id')->default(0);
            $table->unsignedBigInteger('reserved_bytes')->default(0);
            $table->unsignedBigInteger('used_bytes')->default(0);
            $table->timestamps();
            $table->unique(['scope_type', 'scope_id'], 'media_storage_budgets_scope_unique');
        });

        Schema::create('media_download_storage_ledgers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_attachment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('generation');
            $table->string('status', 16);
            $table->unsignedBigInteger('reserved_bytes')->default(0);
            $table->unsignedBigInteger('used_bytes')->default(0);
            $table->string('release_reason', 64)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->unique(
                ['message_attachment_id', 'generation'],
                'media_storage_ledgers_attachment_generation_unique',
            );
            $table->index(['status', 'expires_at'], 'media_storage_ledgers_reaper_idx');
        });

        Schema::create('media_download_traffic_budgets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->date('period_date');
            $table->unsignedBigInteger('reserved_bytes')->default(0);
            $table->unsignedBigInteger('consumed_bytes')->default(0);
            $table->timestamps();
            $table->unique(['channel_id', 'period_date'], 'media_traffic_budgets_channel_period_unique');
        });

        Schema::create('media_download_traffic_ledgers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_attachment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('generation');
            $table->unsignedSmallInteger('attempt_number');
            $table->date('period_date');
            $table->string('status', 16);
            $table->unsignedBigInteger('reserved_bytes')->default(0);
            $table->unsignedBigInteger('consumed_bytes')->default(0);
            $table->unsignedBigInteger('checkpoint_bytes')->default(0);
            $table->string('release_reason', 64)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->unique(
                ['message_attachment_id', 'generation', 'attempt_number'],
                'media_traffic_ledgers_attachment_attempt_unique',
            );
            $table->index(['status', 'expires_at'], 'media_traffic_ledgers_reaper_idx');
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $this->assertRollbackSafe();

            Schema::dropIfExists('media_download_traffic_ledgers');
            Schema::dropIfExists('media_download_traffic_budgets');
            Schema::dropIfExists('media_download_storage_ledgers');
            Schema::dropIfExists('media_download_storage_budgets');

            Schema::table('message_attachments', function (Blueprint $table): void {
                $table->dropIndex('message_attachments_media_download_lease_idx');
                $table->dropColumn([
                    'media_download_generation',
                    'media_download_attempts',
                    'media_download_trigger',
                    'media_download_claimed_at',
                    'media_download_heartbeat_at',
                    'media_download_attempt_deadline_at',
                ]);
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
                'Cannot roll back unified inbound media quota tables while active media work exists.'
            );
        }
    }
};
