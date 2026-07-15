<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\MediaDownloadStorageLedger;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

class InboundMediaStateTransitionAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_chained_state_transitions_for_every_transport_without_secrets(): void
    {
        $operator = User::factory()->create();
        $this->actingAs($operator);

        foreach ([
            MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT,
            MessageAttachment::PROVIDER_TELEGRAM_BOT,
            MessageAttachment::PROVIDER_MAX_BOT,
        ] as $provider) {
            $attachment = $this->createAttachment($provider);

            $initialized = $attachment->stateTransitions()->sole();
            $this->assertSame('initialized', $initialized->action);
            $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD, $initialized->new_status);
            $this->assertSame($provider, $initialized->transport);
            $this->assertSame('system', $initialized->actor_type);
            $this->assertNull($initialized->actor_id);
            $this->assertSame(56_000_000, $initialized->expected_bytes);
            $this->assertNull($initialized->actual_bytes);

            $attachment->forceFill([
                'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING,
                'manual_download_requested_at' => now(),
                'manual_download_requested_by_user_id' => $operator->id,
                'media_download_claim_token' => 'private-claim-token',
            ])->save();

            $claimed = $attachment->stateTransitions()->latest('id')->firstOrFail();
            $this->assertSame($initialized->id, $claimed->previous_transition_id);
            $this->assertSame('download_claimed', $claimed->action);
            $this->assertSame('operator', $claimed->actor_type);
            $this->assertSame($operator->id, $claimed->actor_id);
            $this->assertSame($initialized->correlation_id, $claimed->correlation_id);

            $attachment->forceFill([
                'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED,
                'media_download_upload_size_bytes' => 55_500_000,
                'local_disk' => MessageAttachment::LOCAL_DISK_PRIVATE,
                'local_path' => 'message-attachments/safe-path/video.mp4',
                'safe_error_code' => null,
            ])->save();

            $downloaded = $attachment->stateTransitions()->latest('id')->firstOrFail();
            $this->assertSame($claimed->id, $downloaded->previous_transition_id);
            $this->assertSame('download_succeeded', $downloaded->action);
            $this->assertSame(55_500_000, $downloaded->actual_bytes);
            $this->assertSame('system', $downloaded->actor_type);
            $this->assertSame($claimed->correlation_id, $downloaded->correlation_id);

            $attachment->forceFill([
                'download_status' => MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD,
                'media_download_generation' => 2,
                'media_download_upload_size_bytes' => null,
                'local_disk' => null,
                'local_path' => null,
            ])->save();

            $regenerated = $attachment->stateTransitions()->latest('id')->firstOrFail();
            $this->assertSame('generation_started', $regenerated->action);
            $this->assertSame(1, $regenerated->previous_generation);
            $this->assertSame(2, $regenerated->generation);
            $this->assertNotSame($downloaded->correlation_id, $regenerated->correlation_id);

            $serialized = json_encode($attachment->stateTransitions()->get()->toArray());
            $this->assertIsString($serialized);
            $this->assertStringNotContainsString('private-claim-token', $serialized);
            $this->assertStringNotContainsString('safe-path', $serialized);
            $this->assertStringNotContainsString('provider-file-reference', $serialized);
        }
    }

    public function test_it_does_not_record_non_state_metadata_updates(): void
    {
        $attachment = $this->createAttachment(MessageAttachment::PROVIDER_TELEGRAM_BOT);
        $transitionId = $attachment->stateTransitions()->sole()->id;

        $attachment->forceFill(['mime_type' => 'video/quicktime'])->save();

        $this->assertSame([$transitionId], $attachment->stateTransitions()->pluck('id')->all());
    }

    public function test_model_rejects_mutating_an_existing_transition(): void
    {
        $transition = $this->createAttachment(MessageAttachment::PROVIDER_MAX_BOT)
            ->stateTransitions()
            ->sole();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('append-only');

        $transition->forceFill(['action' => 'tampered'])->save();
    }

    public function test_database_rejects_deleting_an_existing_transition(): void
    {
        $transition = $this->createAttachment(MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT)
            ->stateTransitions()
            ->sole();

        $this->expectException(QueryException::class);

        DB::table('media_download_state_transitions')->whereKey($transition->id)->delete();
    }

    public function test_latest_migration_refuses_rollback_before_any_schema_is_dropped_for_active_download(): void
    {
        $attachment = $this->createAttachment(MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT);
        $attachment->forceFill([
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING,
            'media_download_claim_token' => null,
        ])->save();

        $migration = require database_path(
            'migrations/2026_07_14_000006_create_inbound_media_state_transitions.php'
        );

        try {
            $migration->down();
            $this->fail('Rollback must stop while a media download is active.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('active media downloads', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasTable('media_download_state_transitions'));
        $this->assertTrue(Schema::hasTable('media_download_storage_ledgers'));
        $this->assertTrue(Schema::hasColumn('message_attachments', 'media_download_lease_sequence'));
    }

    public function test_latest_migration_refuses_rollback_before_any_schema_is_dropped_for_active_reservation(): void
    {
        $attachment = $this->createAttachment(MessageAttachment::PROVIDER_TELEGRAM_BOT);
        MediaDownloadStorageLedger::query()->create([
            'message_attachment_id' => $attachment->id,
            'channel_id' => $attachment->channel_id,
            'generation' => 1,
            'status' => MediaDownloadStorageLedger::STATUS_RESERVED,
            'reserved_bytes' => 56_000_000,
            'used_bytes' => 0,
            'expires_at' => now()->addHour(),
        ]);

        $migration = require database_path(
            'migrations/2026_07_14_000006_create_inbound_media_state_transitions.php'
        );

        try {
            $migration->down();
            $this->fail('Rollback must stop while a quota reservation is active.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('active quota reservations', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasTable('media_download_state_transitions'));
        $this->assertTrue(Schema::hasTable('media_download_storage_ledgers'));
        $this->assertTrue(Schema::hasColumn('message_attachments', 'media_download_lease_sequence'));
    }

    public function test_lease_sequence_migration_rechecks_rollback_safety_for_active_download(): void
    {
        $attachment = $this->createAttachment(MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT);
        $attachment->forceFill([
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING,
            'media_download_claim_token' => null,
        ])->save();

        $migration = require database_path(
            'migrations/2026_07_14_000005_add_inbound_media_lease_sequence.php'
        );

        try {
            $migration->down();
            $this->fail('Lease sequence rollback must stop while a media download is active.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('active media work', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('message_attachments', 'media_download_lease_sequence'));
    }

    public function test_admission_migration_rechecks_rollback_safety_for_active_download(): void
    {
        $attachment = $this->createAttachment(MessageAttachment::PROVIDER_TELEGRAM_BOT);
        $attachment->forceFill([
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING,
            'media_download_claim_token' => 'active-claim',
        ])->save();

        $migration = require database_path(
            'migrations/2026_07_14_000004_add_inbound_media_admission_control.php'
        );

        try {
            $migration->down();
            $this->fail('Admission rollback must stop while a media download is active.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('active media work', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasTable('inbound_media_admission_locks'));
        $this->assertTrue(Schema::hasTable('inbound_media_queue_cursors'));
    }

    public function test_quota_migration_rechecks_rollback_safety_for_active_reservation(): void
    {
        $attachment = $this->createAttachment(MessageAttachment::PROVIDER_MAX_BOT);
        MediaDownloadStorageLedger::query()->create([
            'message_attachment_id' => $attachment->id,
            'channel_id' => $attachment->channel_id,
            'generation' => 1,
            'status' => MediaDownloadStorageLedger::STATUS_RESERVED,
            'reserved_bytes' => 56_000_000,
            'used_bytes' => 0,
            'expires_at' => now()->addHour(),
        ]);

        $migration = require database_path(
            'migrations/2026_07_14_000003_add_inbound_media_quota_ledgers.php'
        );

        try {
            $migration->down();
            $this->fail('Quota rollback must stop while a reservation is active.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('active media work', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasTable('media_download_storage_ledgers'));
        $this->assertTrue(Schema::hasTable('media_download_traffic_ledgers'));
        $this->assertTrue(Schema::hasColumn('message_attachments', 'media_download_generation'));
    }

    private function createAttachment(string $provider): MessageAttachment
    {
        $channel = Channel::factory()->create([
            'platform' => $provider === MessageAttachment::PROVIDER_MAX_BOT
                ? Channel::PLATFORM_MAX
                : Channel::PLATFORM_TELEGRAM,
        ]);
        $message = Message::factory()->create(['channel_id' => $channel->id]);

        return MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $channel->id,
            'provider' => $provider,
            'provider_file_id' => 'provider-file-id',
            'provider_file_reference' => 'provider-file-reference',
            'media_kind' => MessageAttachment::MEDIA_KIND_VIDEO,
            'mime_type' => 'video/mp4',
            'file_size_bytes' => 56_000_000,
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD,
            'media_download_generation' => 1,
        ]);
    }
}
