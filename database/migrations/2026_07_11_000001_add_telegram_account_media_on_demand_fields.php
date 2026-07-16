<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            $table->unsignedBigInteger('telegram_account_media_auto_download_max_bytes')
                ->nullable()
                ->after('sync_external_outgoing_enabled');
        });

        Schema::table('message_attachments', function (Blueprint $table): void {
            $table->timestamp('manual_download_requested_at')->nullable()->after('download_status');
            $table->unsignedBigInteger('manual_download_requested_by_user_id')
                ->nullable()
                ->after('manual_download_requested_at');
            $table->string('media_download_claim_token', 64)
                ->nullable()
                ->after('manual_download_requested_by_user_id');
            $table->unsignedBigInteger('media_download_upload_size_bytes')
                ->nullable()
                ->after('media_download_claim_token');
            $table->timestamp('media_download_next_retry_at')
                ->nullable()
                ->after('media_download_upload_size_bytes');
            $table->unsignedBigInteger('media_download_max_bytes')
                ->nullable()
                ->after('media_download_next_retry_at');
            $table->index(
                ['channel_id', 'provider', 'download_status', 'media_download_next_retry_at'],
                'message_attachments_media_download_claim_idx',
            );
        });

        DB::table('message_attachments')
            ->where('provider', 'telegram_account')
            ->whereNull('media_download_max_bytes')
            ->update([
                'media_download_max_bytes' => max(
                    0,
                    (int) config('bots.telegram_account.media_download_max_bytes', 20 * 1024 * 1024),
                ),
            ]);
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('LOCK TABLE message_attachments IN ACCESS EXCLUSIVE MODE');
            }

            if (DB::table('message_attachments')->whereNotNull('media_download_claim_token')->exists()) {
                throw new RuntimeException(
                    'Cannot roll back Telegram Account media on-demand fields while active media claims exist.'
                );
            }

            DB::table('message_attachments')
                ->where(function ($query): void {
                    $query
                        ->where('download_status', 'available_on_demand')
                        ->orWhere(function ($manualQuery): void {
                            $manualQuery
                                ->whereNotNull('manual_download_requested_at')
                                ->whereIn('download_status', ['pending_download', 'downloading']);
                        });
                })
                ->update([
                    'download_status' => 'download_failed',
                    'safe_error_code' => 'file_too_large',
                    'safe_error_message' => 'Telegram Account media file is larger than the automatic download limit.',
                ]);

            Schema::table('message_attachments', function (Blueprint $table): void {
                $table->dropIndex('message_attachments_media_download_claim_idx');
                $table->dropColumn([
                    'manual_download_requested_at',
                    'manual_download_requested_by_user_id',
                    'media_download_claim_token',
                    'media_download_upload_size_bytes',
                    'media_download_next_retry_at',
                    'media_download_max_bytes',
                ]);
            });

            Schema::table('channels', function (Blueprint $table): void {
                $table->dropColumn('telegram_account_media_auto_download_max_bytes');
            });
        });
    }
};
