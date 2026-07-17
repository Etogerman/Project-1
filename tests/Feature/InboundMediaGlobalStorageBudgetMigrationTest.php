<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\MediaDownloadStorageLedger;
use App\Models\Message;
use App\Models\MessageAttachment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InboundMediaGlobalStorageBudgetMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_restores_missing_global_budget_from_ledgers_idempotently(): void
    {
        $channel = Channel::factory()->create();
        $message = Message::factory()->create(['channel_id' => $channel->id]);
        $reservedAttachment = MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $channel->id,
        ]);
        $usedAttachment = MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $channel->id,
        ]);
        $now = now();

        DB::table('media_download_storage_budgets')
            ->where('scope_type', 'global')
            ->where('scope_id', 0)
            ->delete();
        DB::table('media_download_storage_ledgers')->insert([
            [
                'message_attachment_id' => $reservedAttachment->id,
                'channel_id' => $channel->id,
                'generation' => 1,
                'status' => MediaDownloadStorageLedger::STATUS_RESERVED,
                'reserved_bytes' => 10,
                'used_bytes' => 0,
                'release_reason' => null,
                'expires_at' => $now->copy()->addHour(),
                'released_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'message_attachment_id' => $usedAttachment->id,
                'channel_id' => $channel->id,
                'generation' => 1,
                'status' => MediaDownloadStorageLedger::STATUS_USED,
                'reserved_bytes' => 20,
                'used_bytes' => 20,
                'release_reason' => null,
                'expires_at' => null,
                'released_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $migration = require database_path(
            'migrations/2026_07_17_000001_backfill_inbound_media_global_storage_budget.php',
        );

        $migration->up();
        $migration->up();

        $globalBudgets = DB::table('media_download_storage_budgets')
            ->where('scope_type', 'global')
            ->where('scope_id', 0)
            ->get();

        $this->assertCount(1, $globalBudgets);
        $this->assertSame(10, (int) $globalBudgets->first()->reserved_bytes);
        $this->assertSame(20, (int) $globalBudgets->first()->used_bytes);
    }
}
