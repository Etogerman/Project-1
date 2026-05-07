<?php

namespace Tests\Feature;

use App\Models\Bitrix24MessageExport;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class Bitrix24MessageExportResolvedChatVerifiedMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_migration_trusts_only_recent_inbound_client_exports_during_deploy_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-07 12:00:00', 'Europe/Moscow'));

        $migration = require database_path('migrations/2026_05_07_060000_add_resolved_bitrix_chat_verified_to_bitrix24_message_exports_table.php');

        $migration->down();

        $trustedMessage = $this->createMessageForMigration([
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
        ]);
        $oldMessage = $this->createMessageForMigration([
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
        ]);
        $outboundMessage = $this->createMessageForMigration([
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
        ]);
        $pendingMessage = $this->createMessageForMigration([
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
        ]);

        $trustedExportId = $this->createExportForMigration(
            $trustedMessage,
            exportedAt: now()->subMinutes(10),
        );
        $oldExportId = $this->createExportForMigration(
            $oldMessage,
            exportedAt: now()->subMinutes(31),
        );
        $outboundExportId = $this->createExportForMigration(
            $outboundMessage,
            exportedAt: now()->subMinutes(10),
        );
        $pendingExportId = $this->createExportForMigration(
            $pendingMessage,
            exportStatus: Bitrix24MessageExport::STATUS_PENDING,
            exportedAt: null,
        );

        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasColumn('bitrix24_message_exports', 'resolved_bitrix_chat_verified'));
        $this->assertSame(1, $this->resolvedChatVerifiedValue($trustedExportId));
        $this->assertSame(0, $this->resolvedChatVerifiedValue($oldExportId));
        $this->assertSame(0, $this->resolvedChatVerifiedValue($outboundExportId));
        $this->assertSame(0, $this->resolvedChatVerifiedValue($pendingExportId));
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function createMessageForMigration(array $attributes): Message
    {
        return Message::factory()->create($attributes + [
            'text' => 'migration deploy window smoke',
        ]);
    }

    private function createExportForMigration(
        Message $message,
        string $exportStatus = Bitrix24MessageExport::STATUS_EXPORTED,
        ?Carbon $exportedAt = null,
    ): int {
        return (int) DB::table('bitrix24_message_exports')->insertGetId([
            'message_id' => $message->id,
            'contact_id' => $message->contact_id,
            'bitrix24_contact_id' => '9',
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => $exportStatus,
            'transport_method' => Bitrix24MessageExport::TRANSPORT_IMCONNECTOR_SEND_MESSAGES,
            'resolved_bitrix_chat_id' => '23',
            'batch_uuid' => null,
            'bitrix24_timeline_entry_id' => null,
            'exported_at' => $exportedAt,
            'failed_at' => null,
            'failure_reason' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function resolvedChatVerifiedValue(int $exportId): int
    {
        return (int) DB::table('bitrix24_message_exports')
            ->where('id', $exportId)
            ->value('resolved_bitrix_chat_verified');
    }
}
