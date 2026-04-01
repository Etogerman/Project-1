<?php

namespace Tests\Feature;

use App\Jobs\SyncContactToBitrix24Job;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\ContactPhoneNumber;
use App\Services\Bitrix24\QueueBitrix24ContactSyncAction;
use App\Services\Contacts\AddContactPhoneAction;
use App\Services\Contacts\DeleteContactPhoneAction;
use App\Services\Contacts\UpdateContactPhoneAction;
use App\Services\Contacts\UpdateContactProfileAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class Bitrix24ContactSyncTriggerTest extends TestCase
{
    use RefreshDatabase;

    public function test_contacts_table_has_bitrix24_sync_fields_with_expected_defaults(): void
    {
        $this->assertTrue(Schema::hasColumns('contacts', [
            'bitrix24_contact_id',
            'bitrix24_sync_status',
            'bitrix24_last_synced_at',
            'bitrix24_linked_at',
            'bitrix24_sync_pending',
            'bitrix24_sync_fingerprint',
        ]));

        $contact = Contact::factory()->create();
        $contact->refresh();

        $this->assertNull($contact->bitrix24_contact_id);
        $this->assertSame(Contact::BITRIX24_SYNC_STATUS_NOT_SYNCED, $contact->bitrix24_sync_status);
        $this->assertNull($contact->bitrix24_last_synced_at);
        $this->assertNull($contact->bitrix24_linked_at);
        $this->assertFalse($contact->bitrix24_sync_pending);
        $this->assertNull($contact->bitrix24_sync_fingerprint);
    }

    public function test_queue_action_queues_ready_root_contact(): void
    {
        Queue::fake();

        $contact = $this->createSyncReadyContact();

        $result = app(QueueBitrix24ContactSyncAction::class)->handle($contact);

        $contact->refresh();

        $this->assertTrue($result->queued);
        $this->assertFalse($result->alreadyPending);
        $this->assertTrue($result->ready);
        $this->assertSame($contact->id, $result->rootContactId);
        $this->assertTrue($contact->bitrix24_sync_pending);
        $this->assertSame(Contact::BITRIX24_SYNC_STATUS_PENDING, $contact->bitrix24_sync_status);

        Queue::assertPushed(SyncContactToBitrix24Job::class, function (SyncContactToBitrix24Job $job) use ($contact): bool {
            return $job->contactId === $contact->id;
        });
    }

    public function test_queue_action_skips_incomplete_contact(): void
    {
        Queue::fake();

        $contact = Contact::factory()->create([
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_COMPLETED,
            'first_name' => 'Герман',
            'country' => 'Россия',
            'city' => 'Москва',
            'age_range' => null,
        ]);

        $result = app(QueueBitrix24ContactSyncAction::class)->handle($contact);

        $contact->refresh();

        $this->assertFalse($result->queued);
        $this->assertFalse($result->alreadyPending);
        $this->assertFalse($result->ready);
        $this->assertFalse($contact->bitrix24_sync_pending);
        $this->assertSame(Contact::BITRIX24_SYNC_STATUS_NOT_SYNCED, $contact->bitrix24_sync_status);

        Queue::assertNotPushed(SyncContactToBitrix24Job::class);
    }

    public function test_queue_action_routes_merged_child_to_root_contact(): void
    {
        Queue::fake();

        $root = $this->createSyncReadyContact();
        $merged = Contact::factory()->create([
            'merged_into_contact_id' => $root->id,
            'merged_at' => now(),
        ]);

        $result = app(QueueBitrix24ContactSyncAction::class)->handle($merged);

        $root->refresh();
        $merged->refresh();

        $this->assertTrue($result->queued);
        $this->assertSame($root->id, $result->rootContactId);
        $this->assertTrue($root->bitrix24_sync_pending);
        $this->assertFalse($merged->bitrix24_sync_pending);

        Queue::assertPushed(SyncContactToBitrix24Job::class, function (SyncContactToBitrix24Job $job) use ($root): bool {
            return $job->contactId === $root->id;
        });
    }

    public function test_queue_action_does_not_enqueue_duplicate_job_for_pending_contact(): void
    {
        Queue::fake();

        $contact = $this->createSyncReadyContact([
            'bitrix24_sync_pending' => true,
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_PENDING,
        ]);

        $result = app(QueueBitrix24ContactSyncAction::class)->handle($contact);

        $this->assertFalse($result->queued);
        $this->assertTrue($result->alreadyPending);
        $this->assertTrue($result->ready);

        Queue::assertNotPushed(SyncContactToBitrix24Job::class);
    }

    public function test_queue_action_preserves_pending_review_status(): void
    {
        Queue::fake();

        $contact = $this->createSyncReadyContact([
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_PENDING_REVIEW,
        ]);

        app(QueueBitrix24ContactSyncAction::class)->handle($contact);

        $contact->refresh();

        $this->assertTrue($contact->bitrix24_sync_pending);
        $this->assertSame(Contact::BITRIX24_SYNC_STATUS_PENDING_REVIEW, $contact->bitrix24_sync_status);
    }

    public function test_update_contact_profile_action_queues_bitrix24_sync_for_ready_contact(): void
    {
        Queue::fake();

        $contact = $this->createSyncReadyContact([
            'city' => 'Казань',
            'country' => 'Россия',
            'region' => 'Республика Татарстан',
        ]);

        $updated = app(UpdateContactProfileAction::class)->handle($contact, [
            'city' => 'Москва',
            'country' => 'Россия',
            'region' => 'Московская область',
        ]);

        $updated->refresh();

        $this->assertTrue($updated->bitrix24_sync_pending);

        Queue::assertPushed(SyncContactToBitrix24Job::class, function (SyncContactToBitrix24Job $job) use ($contact): bool {
            return $job->contactId === $contact->id;
        });
    }

    public function test_add_contact_phone_action_queues_sync_when_contact_becomes_ready(): void
    {
        Queue::fake();

        $contact = $this->createSyncReadyContactWithoutPhone();

        $phoneNumber = app(AddContactPhoneAction::class)->handle(
            $contact,
            '+7 999 123 45 67',
            'manual',
        );

        $contact->refresh();

        $this->assertSame($contact->id, $phoneNumber->contact_id);
        $this->assertTrue($contact->bitrix24_sync_pending);

        Queue::assertPushed(SyncContactToBitrix24Job::class, function (SyncContactToBitrix24Job $job) use ($contact): bool {
            return $job->contactId === $contact->id;
        });
    }

    public function test_update_contact_phone_action_queues_sync_for_ready_contact(): void
    {
        Queue::fake();

        $contact = $this->createSyncReadyContact();
        $phoneNumber = $contact->phoneNumbers()->firstOrFail();

        app(UpdateContactPhoneAction::class)->handle($phoneNumber, '+7 999 555 55 55');

        $contact->refresh();

        $this->assertTrue($contact->bitrix24_sync_pending);

        Queue::assertPushed(SyncContactToBitrix24Job::class, function (SyncContactToBitrix24Job $job) use ($contact): bool {
            return $job->contactId === $contact->id;
        });
    }

    public function test_delete_contact_phone_action_queues_sync_when_contact_still_has_phone(): void
    {
        Queue::fake();

        $contact = $this->createSyncReadyContact();
        ContactPhoneNumber::factory()->create([
            'contact_id' => $contact->id,
            'phone_raw' => '+7 999 555 55 55',
            'phone_normalized' => '+79995555555',
            'is_primary' => false,
        ]);
        $phoneNumber = $contact->phoneNumbers()->firstOrFail();

        app(DeleteContactPhoneAction::class)->handle($phoneNumber);

        $contact->refresh();

        $this->assertTrue($contact->bitrix24_sync_pending);

        Queue::assertPushed(SyncContactToBitrix24Job::class, function (SyncContactToBitrix24Job $job) use ($contact): bool {
            return $job->contactId === $contact->id;
        });
    }

    public function test_sync_contact_job_clears_pending_flag_for_contact_that_is_no_longer_ready(): void
    {
        $contact = $this->createSyncReadyContact([
            'age_range' => null,
            'bitrix24_sync_pending' => true,
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_PENDING,
        ]);

        $job = new SyncContactToBitrix24Job($contact->id);
        app()->call([$job, 'handle']);

        $contact->refresh();

        $this->assertFalse($contact->bitrix24_sync_pending);
        $this->assertSame(Contact::BITRIX24_SYNC_STATUS_PENDING, $contact->bitrix24_sync_status);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createSyncReadyContact(array $overrides = []): Contact
    {
        $withPhone = ! array_key_exists('with_phone', $overrides) || (bool) $overrides['with_phone'];
        unset($overrides['with_phone']);

        $contact = Contact::factory()->create(array_merge([
            'first_name' => 'Герман',
            'country' => 'Россия',
            'city' => 'Москва',
            'age_range' => '24_29',
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_COMPLETED,
            'data_collection_current_field' => null,
        ], $overrides));

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
        ]);

        if ($withPhone) {
            ContactPhoneNumber::factory()->create([
                'contact_id' => $contact->id,
                'phone_raw' => '+7 999 123 45 67',
                'phone_normalized' => '+79991234567',
                'is_primary' => true,
            ]);
        }

        return $contact->fresh();
    }

    private function createSyncReadyContactWithoutPhone(): Contact
    {
        return $this->createSyncReadyContact([
            'with_phone' => false,
        ]);
    }
}
