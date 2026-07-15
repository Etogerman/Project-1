<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_download_state_transitions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('message_attachment_id');
            $table->unsignedBigInteger('channel_id')->nullable();
            $table->unsignedBigInteger('previous_transition_id')->nullable();
            $table->unsignedInteger('previous_generation')->nullable();
            $table->unsignedInteger('generation');
            $table->string('actor_type', 32);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action', 64);
            $table->string('old_status', 32)->nullable();
            $table->string('new_status', 32)->nullable();
            $table->string('safe_reason', 128)->nullable();
            $table->unsignedBigInteger('expected_bytes')->nullable();
            $table->unsignedBigInteger('actual_bytes')->nullable();
            $table->string('transport', 32);
            $table->char('correlation_id', 64);
            $table->timestamp('created_at');

            $table->index(
                ['message_attachment_id', 'generation', 'id'],
                'media_state_transitions_attachment_generation_idx',
            );
            $table->index('channel_id', 'media_state_transitions_channel_idx');
            $table->index('previous_transition_id', 'media_state_transitions_previous_idx');
            $table->index('correlation_id', 'media_state_transitions_correlation_idx');
        });

        $this->installAppendOnlyGuard();
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            if (Schema::hasTable('message_attachments')) {
                if (DB::getDriverName() === 'pgsql') {
                    DB::statement('LOCK TABLE message_attachments IN ACCESS EXCLUSIVE MODE');
                }

                $hasClaimToken = Schema::hasColumn(
                    'message_attachments',
                    'media_download_claim_token',
                );
                $activeDownloads = DB::table('message_attachments')
                    ->where(function ($query) use ($hasClaimToken): void {
                        $query->where('download_status', 'downloading');

                        if ($hasClaimToken) {
                            $query->orWhereNotNull('media_download_claim_token');
                        }
                    })
                    ->exists();

                if ($activeDownloads) {
                    throw new RuntimeException(
                        'Cannot roll back unified inbound media tables while active media downloads exist.'
                    );
                }
            }

            if (DB::getDriverName() === 'pgsql') {
                foreach (['media_download_storage_ledgers', 'media_download_traffic_ledgers'] as $table) {
                    if (Schema::hasTable($table)) {
                        DB::statement("LOCK TABLE {$table} IN ACCESS EXCLUSIVE MODE");
                    }
                }
            }

            $activeStorageReservations = Schema::hasTable('media_download_storage_ledgers')
                && DB::table('media_download_storage_ledgers')->where('status', 'reserved')->exists();
            $activeTrafficReservations = Schema::hasTable('media_download_traffic_ledgers')
                && DB::table('media_download_traffic_ledgers')->where('status', 'reserved')->exists();

            if ($activeStorageReservations || $activeTrafficReservations) {
                throw new RuntimeException(
                    'Cannot roll back unified inbound media tables while active quota reservations exist.'
                );
            }

            Schema::dropIfExists('media_download_state_transitions');

            if (DB::getDriverName() === 'pgsql') {
                DB::unprepared('DROP FUNCTION IF EXISTS prevent_media_state_transition_mutation()');
            }
        });
    }

    private function installAppendOnlyGuard(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION prevent_media_state_transition_mutation()
                RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'Inbound media state transition audit is append-only.';
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER media_state_transitions_append_only
                BEFORE UPDATE OR DELETE ON media_download_state_transitions
                FOR EACH ROW EXECUTE FUNCTION prevent_media_state_transition_mutation();
                SQL);

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER media_state_transitions_prevent_update
                BEFORE UPDATE ON media_download_state_transitions
                BEGIN
                    SELECT RAISE(ABORT, 'Inbound media state transition audit is append-only.');
                END;

                CREATE TRIGGER media_state_transitions_prevent_delete
                BEFORE DELETE ON media_download_state_transitions
                BEGIN
                    SELECT RAISE(ABORT, 'Inbound media state transition audit is append-only.');
                END;
                SQL);
        }
    }
};
