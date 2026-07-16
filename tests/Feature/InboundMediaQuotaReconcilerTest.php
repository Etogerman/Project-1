<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Services\Messages\InboundMediaQuotaLedger;
use App\Services\Messages\ReconcileInboundMediaQuotaBudgetsAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InboundMediaQuotaReconcilerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'inbound_media.storage.enforce' => true,
            'inbound_media.storage.global_limit_bytes' => 10_000,
            'inbound_media.storage.channel_limit_bytes' => 10_000,
            'inbound_media.storage.minimum_free_bytes' => 0,
            'inbound_media.storage.minimum_free_percent' => 0,
            'inbound_media.traffic.enforce' => true,
            'inbound_media.traffic.channel_daily_limit_bytes' => 10_000,
        ]);
    }

    public function test_dry_run_reports_budget_drift_without_mutating_rows(): void
    {
        $attachment = $this->createAttachment();
        app(InboundMediaQuotaLedger::class)->reserveForAttempt($attachment, 1);
        DB::table('media_download_storage_budgets')->update([
            'reserved_bytes' => 999,
            'used_bytes' => 777,
        ]);
        DB::table('media_download_traffic_budgets')->update([
            'reserved_bytes' => 888,
            'consumed_bytes' => 666,
        ]);

        $stats = app(ReconcileInboundMediaQuotaBudgetsAction::class)->handle();

        $this->assertSame(2, $stats['storage_drift_rows']);
        $this->assertSame(1, $stats['traffic_drift_rows']);
        $this->assertSame(3, $stats['remaining_drift_rows']);
        $this->assertSame(999, (int) DB::table('media_download_storage_budgets')->first()->reserved_bytes);
        $this->assertSame(888, (int) DB::table('media_download_traffic_budgets')->first()->reserved_bytes);
    }

    public function test_repair_rebuilds_storage_and_traffic_budgets_from_ledgers(): void
    {
        $attachment = $this->createAttachment();
        $ledger = app(InboundMediaQuotaLedger::class);
        $ledger->reserveForAttempt($attachment, 1);
        $ledger->completeAttempt($attachment, 1, 80);
        DB::table('media_download_storage_budgets')->update([
            'reserved_bytes' => 999,
            'used_bytes' => 777,
        ]);
        DB::table('media_download_traffic_budgets')->update([
            'reserved_bytes' => 888,
            'consumed_bytes' => 666,
        ]);

        $stats = app(ReconcileInboundMediaQuotaBudgetsAction::class)->handle(repair: true);

        $this->assertSame(3, $stats['repaired_rows']);
        $this->assertSame(0, $stats['remaining_drift_rows']);
        $global = DB::table('media_download_storage_budgets')
            ->where('scope_type', 'global')
            ->first();
        $channel = DB::table('media_download_storage_budgets')
            ->where('scope_type', 'channel')
            ->where('scope_id', $attachment->channel_id)
            ->first();
        $traffic = DB::table('media_download_traffic_budgets')->first();
        $this->assertSame(0, (int) $global->reserved_bytes);
        $this->assertSame(80, (int) $global->used_bytes);
        $this->assertSame(80, (int) $channel->used_bytes);
        $this->assertSame(0, (int) $traffic->reserved_bytes);
        $this->assertSame(80, (int) $traffic->consumed_bytes);
    }

    public function test_repair_zeroes_stale_budget_without_supporting_ledger(): void
    {
        $now = now();
        DB::table('media_download_storage_budgets')->insert([
            'scope_type' => 'channel',
            'scope_id' => 999,
            'reserved_bytes' => 123,
            'used_bytes' => 456,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('media_download_traffic_budgets')->insert([
            'channel_id' => Channel::factory()->create()->id,
            'period_date' => now()->toDateString(),
            'reserved_bytes' => 123,
            'consumed_bytes' => 456,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $stats = app(ReconcileInboundMediaQuotaBudgetsAction::class)->handle(repair: true);

        $this->assertSame(0, $stats['remaining_drift_rows']);
        $this->assertSame(0, (int) DB::table('media_download_storage_budgets')
            ->where('scope_type', 'channel')
            ->where('scope_id', 999)
            ->value('reserved_bytes'));
        $this->assertSame(0, (int) DB::table('media_download_traffic_budgets')->value('consumed_bytes'));
    }

    private function createAttachment(): MessageAttachment
    {
        $channel = Channel::factory()->create();
        $message = Message::factory()->create(['channel_id' => $channel->id]);

        return MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $channel->id,
            'provider' => MessageAttachment::PROVIDER_TELEGRAM_BOT,
            'media_kind' => MessageAttachment::MEDIA_KIND_VIDEO,
            'file_size_bytes' => 100,
            'media_download_max_bytes' => 500,
            'media_download_generation' => 1,
            'media_download_attempts' => 1,
        ]);
    }
}
