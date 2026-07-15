<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\MediaDownloadStorageLedger;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Services\Messages\InboundMediaQuotaLedger;
use App\Services\Messages\ReconcileInboundMediaStorageAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InboundMediaStorageReconcilerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        Cache::forget(ReconcileInboundMediaStorageAction::ATTACHMENT_CURSOR_CACHE_KEY);
        Cache::forget(ReconcileInboundMediaStorageAction::ORPHAN_CURSOR_CACHE_KEY);
        config([
            'filesystems.message_attachments_disk' => MessageAttachment::LOCAL_DISK_PRIVATE,
            'inbound_media.orphan_grace_seconds' => 60,
            'inbound_media.attempt_deadline_seconds' => 30,
            'inbound_media.reservation_ttl_buffer_seconds' => 30,
            'inbound_media.storage.enforce' => true,
            'inbound_media.storage.global_limit_bytes' => 10_000,
            'inbound_media.storage.channel_limit_bytes' => 10_000,
            'inbound_media.storage.minimum_free_bytes' => 0,
            'inbound_media.storage.minimum_free_percent' => 0,
            'inbound_media.traffic.enforce' => true,
            'inbound_media.traffic.channel_daily_limit_bytes' => 10_000,
        ]);
    }

    public function test_repair_backfills_used_ledger_from_existing_stable_object(): void
    {
        $attachment = $this->createDownloadedAttachment('existing.mp4', 'hello');

        $stats = app(ReconcileInboundMediaStorageAction::class)->handle(repair: true);

        $ledger = MediaDownloadStorageLedger::query()->firstOrFail();
        $this->assertSame(1, $stats['storage_ledger_drift']);
        $this->assertSame(1, $stats['storage_ledgers_created']);
        $this->assertSame(MediaDownloadStorageLedger::STATUS_USED, $ledger->status);
        $this->assertSame(5, $ledger->used_bytes);
        $this->assertSame(5, (int) DB::table('media_download_storage_budgets')
            ->where('scope_type', 'global')
            ->value('used_bytes'));
        $this->assertSame(0, $stats['remaining_drift_rows']);
    }

    public function test_repair_corrects_used_bytes_to_actual_stable_object_size(): void
    {
        $attachment = $this->createDownloadedAttachment('wrong-size.mp4', 'hello');
        $ledger = app(InboundMediaQuotaLedger::class);
        $ledger->reserveForAttempt($attachment, 1);
        $ledger->completeAttempt($attachment, 1, 3);

        $stats = app(ReconcileInboundMediaStorageAction::class)->handle(repair: true);

        $this->assertSame(1, $stats['storage_ledgers_corrected']);
        $this->assertSame(5, MediaDownloadStorageLedger::query()->firstOrFail()->used_bytes);
        $this->assertSame(5, (int) DB::table('media_download_storage_budgets')
            ->where('scope_type', 'global')
            ->value('used_bytes'));
    }

    public function test_repair_marks_missing_stable_object_deleted_before_releasing_usage(): void
    {
        $attachment = $this->createDownloadedAttachment('missing.mp4');
        $ledger = app(InboundMediaQuotaLedger::class);
        $ledger->reserveForAttempt($attachment, 1);
        $ledger->completeAttempt($attachment, 1, 5);

        $stats = app(ReconcileInboundMediaStorageAction::class)->handle(repair: true);
        $attachment->refresh();

        $this->assertSame(1, $stats['missing_files']);
        $this->assertSame(1, $stats['attachments_marked_deleted']);
        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_DELETED_LOCAL, $attachment->download_status);
        $this->assertNull($attachment->local_disk);
        $this->assertNull($attachment->local_path);
        $this->assertSame(ReconcileInboundMediaStorageAction::REASON_LOCAL_FILE_MISSING, $attachment->safe_error_code);
        $this->assertSame(
            MediaDownloadStorageLedger::STATUS_RELEASED,
            MediaDownloadStorageLedger::query()->firstOrFail()->status,
        );
        $this->assertSame(0, (int) DB::table('media_download_storage_budgets')->sum('used_bytes'));
    }

    public function test_repair_releases_usage_when_downloaded_attachment_has_no_local_path(): void
    {
        $attachment = $this->createDownloadedAttachment('lost-reference.mp4', 'hello');
        $ledger = app(InboundMediaQuotaLedger::class);
        $ledger->reserveForAttempt($attachment, 1);
        $ledger->completeAttempt($attachment, 1, 5);
        $attachment->forceFill([
            'local_disk' => null,
            'local_path' => null,
        ])->save();

        $stats = app(ReconcileInboundMediaStorageAction::class)->handle(repair: true);
        $attachment->refresh();

        $this->assertSame(1, $stats['invalid_references']);
        $this->assertSame(1, $stats['attachments_marked_deleted']);
        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_DELETED_LOCAL, $attachment->download_status);
        $this->assertSame(ReconcileInboundMediaStorageAction::REASON_LOCAL_FILE_MISSING, $attachment->safe_error_code);
        $this->assertSame(
            MediaDownloadStorageLedger::STATUS_RELEASED,
            MediaDownloadStorageLedger::query()->firstOrFail()->status,
        );
        $this->assertSame(0, (int) DB::table('media_download_storage_budgets')->sum('used_bytes'));
    }

    public function test_repair_quarantines_unknown_disk_without_releasing_usage(): void
    {
        $attachment = $this->createDownloadedAttachment('retired-disk.mp4', 'hello');
        $ledger = app(InboundMediaQuotaLedger::class);
        $ledger->reserveForAttempt($attachment, 1);
        $ledger->completeAttempt($attachment, 1, 5);
        $attachment->forceFill(['local_disk' => 'retired-private-disk'])->save();

        $stats = app(ReconcileInboundMediaStorageAction::class)->handle(repair: true);
        $attachment->refresh();

        $this->assertSame(1, $stats['invalid_references_quarantined']);
        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED, $attachment->download_status);
        $this->assertSame(ReconcileInboundMediaStorageAction::REASON_INVALID_LOCAL_REFERENCE, $attachment->safe_error_code);
        $this->assertSame(
            MediaDownloadStorageLedger::STATUS_USED,
            MediaDownloadStorageLedger::query()->firstOrFail()->status,
        );
        $this->assertSame(5, (int) DB::table('media_download_storage_budgets')
            ->where('scope_type', 'global')
            ->value('used_bytes'));
    }

    public function test_repair_quarantines_unsafe_path_without_deleting_or_releasing_usage(): void
    {
        $attachment = $this->createDownloadedAttachment('unsafe-reference.mp4', 'hello');
        $ledger = app(InboundMediaQuotaLedger::class);
        $ledger->reserveForAttempt($attachment, 1);
        $ledger->completeAttempt($attachment, 1, 5);
        $attachment->forceFill(['local_path' => '../outside.mp4'])->save();

        $stats = app(ReconcileInboundMediaStorageAction::class)->handle(repair: true);
        $attachment->refresh();

        $this->assertSame(1, $stats['invalid_references_quarantined']);
        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED, $attachment->download_status);
        $this->assertSame(ReconcileInboundMediaStorageAction::REASON_INVALID_LOCAL_REFERENCE, $attachment->safe_error_code);
        $this->assertSame(
            MediaDownloadStorageLedger::STATUS_USED,
            MediaDownloadStorageLedger::query()->firstOrFail()->status,
        );
        $this->assertSame(5, (int) DB::table('media_download_storage_budgets')
            ->where('scope_type', 'global')
            ->value('used_bytes'));
    }

    public function test_repair_deletes_only_orphans_older_than_the_safety_window(): void
    {
        $storage = Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE);
        $stalePath = MessageAttachment::LOCAL_PATH_PREFIX.'/orphans/stale.bin';
        $freshPath = MessageAttachment::LOCAL_PATH_PREFIX.'/orphans/fresh.bin';
        $storage->put($stalePath, 'old');
        $storage->put($freshPath, 'new');
        touch($storage->path($stalePath), now()->subMinutes(2)->timestamp);

        $stats = app(ReconcileInboundMediaStorageAction::class)->handle(repair: true);

        $this->assertSame(2, $stats['orphan_files']);
        $this->assertSame(1, $stats['orphan_files_deleted']);
        $this->assertSame(1, $stats['orphan_files_retained']);
        $storage->assertMissing($stalePath);
        $storage->assertExists($freshPath);
    }

    public function test_dry_run_reports_drift_without_mutating_storage_or_ledger(): void
    {
        $attachment = $this->createDownloadedAttachment('dry-run.mp4', 'hello');
        $orphanPath = MessageAttachment::LOCAL_PATH_PREFIX.'/orphans/stale.bin';
        $storage = Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE);
        $storage->put($orphanPath, 'old');
        touch($storage->path($orphanPath), now()->subMinutes(2)->timestamp);

        $stats = app(ReconcileInboundMediaStorageAction::class)->handle();

        $this->assertSame(1, $stats['storage_ledger_drift']);
        $this->assertSame(1, $stats['orphan_files']);
        $this->assertSame(0, MediaDownloadStorageLedger::query()->count());
        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED, $attachment->fresh()->download_status);
        $storage->assertExists($orphanPath);
    }

    public function test_attachment_cursor_advances_past_an_unchanged_first_row(): void
    {
        $first = $this->createDownloadedAttachment('first.mp4', 'a');
        $second = $this->createDownloadedAttachment('second.mp4', 'bb');
        $ledger = app(InboundMediaQuotaLedger::class);
        $ledger->reserveForAttempt($first, 1);
        $ledger->completeAttempt($first, 1, 1);

        $firstRun = app(ReconcileInboundMediaStorageAction::class)->handle(
            attachmentLimit: 1,
            orphanLimit: 1,
        );
        $secondRun = app(ReconcileInboundMediaStorageAction::class)->handle(
            attachmentLimit: 1,
            orphanLimit: 1,
        );

        $this->assertSame(0, $firstRun['storage_ledger_drift']);
        $this->assertSame(1, $secondRun['storage_ledger_drift']);
        $this->assertSame($second->id, Cache::get(
            ReconcileInboundMediaStorageAction::ATTACHMENT_CURSOR_CACHE_KEY,
        ));
    }

    public function test_orphan_cursor_advances_past_a_retained_first_object(): void
    {
        $storage = Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE);
        $freshPath = MessageAttachment::LOCAL_PATH_PREFIX.'/orphans/a-fresh.bin';
        $stalePath = MessageAttachment::LOCAL_PATH_PREFIX.'/orphans/b-stale.bin';
        $storage->put($freshPath, 'new');
        $storage->put($stalePath, 'old');
        touch($storage->path($stalePath), now()->subMinutes(2)->timestamp);

        $firstRun = app(ReconcileInboundMediaStorageAction::class)->handle(
            repair: true,
            attachmentLimit: 1,
            orphanLimit: 1,
        );
        $secondRun = app(ReconcileInboundMediaStorageAction::class)->handle(
            repair: true,
            attachmentLimit: 1,
            orphanLimit: 1,
        );

        $this->assertSame(1, $firstRun['orphan_files_retained']);
        $storage->assertExists($freshPath);
        $this->assertSame(1, $secondRun['orphan_files_deleted']);
        $storage->assertMissing($stalePath);
    }

    private function createDownloadedAttachment(string $filename, ?string $contents = null): MessageAttachment
    {
        $channel = Channel::factory()->create();
        $message = Message::factory()->create(['channel_id' => $channel->id]);
        $attachment = MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $channel->id,
            'provider' => MessageAttachment::PROVIDER_TELEGRAM_BOT,
            'media_kind' => MessageAttachment::MEDIA_KIND_VIDEO,
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED,
            'file_size_bytes' => $contents === null ? 5 : strlen($contents),
            'media_download_max_bytes' => 500,
            'media_download_generation' => 1,
            'media_download_attempts' => 1,
        ]);
        $path = MessageAttachment::LOCAL_PATH_PREFIX.'/'.$message->id.'/'.$filename;
        $attachment->forceFill([
            'local_disk' => MessageAttachment::LOCAL_DISK_PRIVATE,
            'local_path' => $path,
        ])->save();

        if ($contents !== null) {
            Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->put($path, $contents);
        }

        return $attachment->fresh();
    }
}
