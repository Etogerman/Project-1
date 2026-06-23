<?php

namespace Tests\Feature;

use App\Jobs\EnsureBitrix24DealJob;
use App\Jobs\SyncChatHistoryToBitrix24Job;
use App\Jobs\SyncContactToBitrix24Job;
use App\Models\Bitrix24SyncLog;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\ContactPhoneNumber;
use App\Models\ContactTimelineEvent;
use App\Models\User;
use App\Services\Bitrix24\DiagnoseBitrix24RescueSyncAction;
use App\Services\Bitrix24\QueueBitrix24RescueSyncAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class Bitrix24RescueSyncActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('bitrix24.features.deals_sync_enabled', true);
        config()->set('bitrix24.features.timeline_history_import_enabled', true);
    }

    public function test_diagnostics_reports_missing_requirements_and_does_not_queue(): void
    {
        Queue::fake();

        $contact = Contact::factory()->create([
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_ACTIVE,
            'first_name' => null,
            'country' => 'США',
            'city' => null,
            'age_range' => '18_23',
        ]);

        $diagnostics = app(DiagnoseBitrix24RescueSyncAction::class)->handle($contact);

        $this->assertFalse($diagnostics->ready);
        $this->assertFalse($diagnostics->canQueueContact);
        $this->assertContains('first_name', $diagnostics->missingRequirements);
        $this->assertContains('city', $diagnostics->missingRequirements);
        $this->assertContains('phone', $diagnostics->missingRequirements);
        $this->assertContains('primary_identity', $diagnostics->missingRequirements);
        $this->assertContains('data_collection_not_completed', $diagnostics->reasons);
        $this->assertContains('missing_first_name', $diagnostics->reasons);

        $result = app(QueueBitrix24RescueSyncAction::class)->handle($contact, $this->makeSuperadmin());

        $this->assertSame('not_ready', $result->status);
        $this->assertFalse($result->queuedContact);
        $this->assertFalse($result->queuedDeal);
        $this->assertFalse($result->queuedHistory);

        Queue::assertNothingPushed();
    }

    public function test_rescue_sync_queues_contact_sync_in_quiet_mode_and_logs_request(): void
    {
        Queue::fake();

        $contact = $this->createReadyContact([
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_NOT_SYNCED,
        ]);
        $actor = $this->makeSuperadmin();

        $result = app(QueueBitrix24RescueSyncAction::class)->handle($contact, $actor);

        $contact->refresh();

        $this->assertSame('queued', $result->status);
        $this->assertTrue($result->queuedContact);
        $this->assertFalse($result->queuedDeal);
        $this->assertFalse($result->queuedHistory);
        $this->assertTrue($contact->bitrix24_sync_pending);
        $this->assertSame(Contact::BITRIX24_SYNC_STATUS_PENDING, $contact->bitrix24_sync_status);

        Queue::assertPushed(SyncContactToBitrix24Job::class, function (SyncContactToBitrix24Job $job) use ($contact): bool {
            return $job->contactId === $contact->id
                && $job->suppressDialogContinuation === true;
        });
        Queue::assertNotPushed(EnsureBitrix24DealJob::class);
        Queue::assertNotPushed(SyncChatHistoryToBitrix24Job::class);

        $this->assertDatabaseHas('contact_timeline_events', [
            'contact_id' => $contact->id,
            'event_type' => ContactTimelineEvent::EVENT_BITRIX24_RESCUE_SYNC_REQUESTED,
            'actor_user_id' => $actor->id,
        ]);
        $this->assertDatabaseHas('bitrix24_sync_logs', [
            'direction' => Bitrix24SyncLog::DIRECTION_SYSTEM,
            'operation' => 'rescue_sync_requested',
            'entity_type' => 'contact',
            'entity_id' => (string) $contact->id,
            'status' => Bitrix24SyncLog::STATUS_SUCCESS,
        ]);
    }

    public function test_rescue_sync_skips_pending_review(): void
    {
        Queue::fake();

        $contact = $this->createReadyContact([
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_PENDING_REVIEW,
        ]);

        $result = app(QueueBitrix24RescueSyncAction::class)->handle($contact, $this->makeSuperadmin());

        $this->assertSame('needs_manual_review', $result->status);
        $this->assertTrue($result->needsManualReview);
        $this->assertFalse($result->queuedContact);
        $this->assertContains('contact_needs_manual_review', $result->skippedReasons);

        Queue::assertNothingPushed();
    }

    public function test_rescue_sync_queues_only_missing_deal_and_history_for_synced_contact(): void
    {
        Queue::fake();

        $contact = $this->createReadyContact([
            'bitrix24_contact_id' => '71455',
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_SYNCED,
            'bitrix24_deal_sync_status' => Contact::BITRIX24_DEAL_SYNC_STATUS_NOT_SYNCED,
            'bitrix24_history_sync_status' => Contact::BITRIX24_HISTORY_SYNC_STATUS_FAILED,
        ]);

        $result = app(QueueBitrix24RescueSyncAction::class)->handle($contact, $this->makeSuperadmin());

        $contact->refresh();

        $this->assertSame('queued', $result->status);
        $this->assertFalse($result->queuedContact);
        $this->assertTrue($result->queuedDeal);
        $this->assertTrue($result->queuedHistory);
        $this->assertFalse($contact->bitrix24_sync_pending);
        $this->assertTrue($contact->bitrix24_deal_sync_pending);
        $this->assertTrue($contact->bitrix24_history_sync_pending);

        Queue::assertNotPushed(SyncContactToBitrix24Job::class);
        Queue::assertPushed(EnsureBitrix24DealJob::class, fn (EnsureBitrix24DealJob $job): bool => $job->contactId === $contact->id);
        Queue::assertPushed(SyncChatHistoryToBitrix24Job::class, fn (SyncChatHistoryToBitrix24Job $job): bool => $job->contactId === $contact->id);
    }

    public function test_diagnostics_ignores_disabled_deal_and_history_features(): void
    {
        config()->set('bitrix24.features.deals_sync_enabled', false);
        config()->set('bitrix24.features.timeline_history_import_enabled', false);

        Queue::fake();

        $contact = $this->createReadyContact([
            'bitrix24_contact_id' => '71455',
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_SYNCED,
            'bitrix24_deal_sync_status' => Contact::BITRIX24_DEAL_SYNC_STATUS_NOT_SYNCED,
            'bitrix24_history_sync_status' => Contact::BITRIX24_HISTORY_SYNC_STATUS_NOT_SYNCED,
        ]);

        $diagnostics = app(DiagnoseBitrix24RescueSyncAction::class)->handle($contact);

        $this->assertTrue($diagnostics->ready);
        $this->assertFalse($diagnostics->canQueueDeal);
        $this->assertFalse($diagnostics->canQueueHistory);
        $this->assertContains('deals_sync_disabled', $diagnostics->reasons);
        $this->assertContains('history_sync_disabled', $diagnostics->reasons);

        $result = app(QueueBitrix24RescueSyncAction::class)->handle($contact, $this->makeSuperadmin());

        $this->assertSame('synced', $result->status);
        $this->assertFalse($result->queuedContact);
        $this->assertFalse($result->queuedDeal);
        $this->assertFalse($result->queuedHistory);

        Queue::assertNothingPushed();
    }

    public function test_diagnostics_reports_last_failed_sync_errors(): void
    {
        $contact = $this->createReadyContact([
            'bitrix24_contact_id' => '71455',
            'bitrix24_deal_id' => '136886',
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_SYNCED,
            'bitrix24_deal_sync_status' => Contact::BITRIX24_DEAL_SYNC_STATUS_FAILED,
            'bitrix24_history_sync_status' => Contact::BITRIX24_HISTORY_SYNC_STATUS_FAILED,
        ]);

        Bitrix24SyncLog::query()->create([
            'direction' => Bitrix24SyncLog::DIRECTION_SYSTEM,
            'operation' => 'contact_sync_failed',
            'entity_type' => 'contact',
            'entity_id' => (string) $contact->id,
            'status' => Bitrix24SyncLog::STATUS_FAILED,
            'error_message' => 'Contact failed.',
        ]);
        Bitrix24SyncLog::query()->create([
            'direction' => Bitrix24SyncLog::DIRECTION_SYSTEM,
            'operation' => 'deal_sync_lookup_failed',
            'entity_type' => 'contact',
            'entity_id' => (string) $contact->id,
            'status' => Bitrix24SyncLog::STATUS_FAILED,
            'error_message' => 'Deal failed.',
        ]);
        Bitrix24SyncLog::query()->create([
            'direction' => Bitrix24SyncLog::DIRECTION_SYSTEM,
            'operation' => 'history_export_chunk_failed_deal',
            'entity_type' => 'deal',
            'entity_id' => (string) $contact->bitrix24_deal_id,
            'request_payload' => [
                'contact_id' => $contact->id,
                'bitrix24_deal_id' => $contact->bitrix24_deal_id,
            ],
            'status' => Bitrix24SyncLog::STATUS_FAILED,
            'error_message' => 'History deal copy failed.',
        ]);

        $diagnostics = app(DiagnoseBitrix24RescueSyncAction::class)->handle($contact);

        $this->assertSame('Contact failed.', $diagnostics->lastContactError);
        $this->assertSame('Deal failed.', $diagnostics->lastDealError);
        $this->assertSame('History deal copy failed.', $diagnostics->lastHistoryError);
    }

    public function test_rescue_sync_requires_bitrix_edit_permission(): void
    {
        Queue::fake();

        $contact = $this->createReadyContact();
        $actor = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'is_active' => true,
        ]);

        $this->expectException(AuthorizationException::class);

        try {
            app(QueueBitrix24RescueSyncAction::class)->handle($contact, $actor);
        } finally {
            $this->assertDatabaseCount('contact_timeline_events', 0);
            $this->assertDatabaseCount('bitrix24_sync_logs', 0);
            Queue::assertNothingPushed();
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createReadyContact(array $overrides = []): Contact
    {
        $contact = Contact::factory()->create(array_merge([
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_COMPLETED,
            'first_name' => 'Кирилл',
            'country' => 'США',
            'city' => 'Сиэтл',
            'age_range' => '18_23',
        ], $overrides));

        ContactPhoneNumber::factory()->create([
            'contact_id' => $contact->id,
            'phone_raw' => '+14255983129',
            'phone_normalized' => '14255983129',
            'is_primary' => true,
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        return $contact;
    }

    private function makeSuperadmin(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_SUPERADMIN,
            'is_active' => true,
        ]);
    }
}
