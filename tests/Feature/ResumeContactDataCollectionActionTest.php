<?php

namespace Tests\Feature;

use App\Jobs\ProcessDataCollectionQuestionJob;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\ContactPhoneNumber;
use App\Models\Dialog;
use App\Models\Message;
use App\Services\DataCollection\ResumeContactDataCollectionAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class ResumeContactDataCollectionActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_action_does_not_activate_collector_without_sendable_route(): void
    {
        Queue::fake();

        $contact = Contact::factory()->create([
            'data_collection_status' => null,
            'data_collection_current_field' => null,
            'first_name' => null,
            'country' => null,
            'city' => null,
            'age_range' => null,
        ]);

        ContactPhoneNumber::factory()->create([
            'contact_id' => $contact->id,
            'is_primary' => true,
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
        ]);

        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => null,
        ]);

        Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'contact_identity_id' => $identity->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => 'legacy-chat-id',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Не удалось определить маршрут для возобновления анкеты.');

        try {
            app(ResumeContactDataCollectionAction::class)->handle($contact);
        } finally {
            $freshContact = $contact->fresh();

            $this->assertNotNull($freshContact);
            $this->assertNotSame(Contact::DATA_COLLECTION_STATUS_ACTIVE, $freshContact->data_collection_status);
            $this->assertNull($freshContact->data_collection_current_field);
            Queue::assertNothingPushed();
        }
    }

    public function test_action_uses_sendable_dialog_instead_of_latest_raw_message(): void
    {
        Queue::fake();

        $contact = Contact::factory()->create([
            'data_collection_status' => null,
            'data_collection_current_field' => null,
            'first_name' => null,
            'country' => null,
            'city' => null,
            'age_range' => null,
        ]);

        ContactPhoneNumber::factory()->create([
            'contact_id' => $contact->id,
            'is_primary' => true,
        ]);

        $sendableChannel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $sendableIdentity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $sendableChannel->id,
            'platform' => $sendableChannel->platform,
        ]);
        $sendableDialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $sendableChannel->id,
            'current_contact_identity_id' => $sendableIdentity->id,
            'external_chat_id' => 'sendable-chat',
            'last_message_at' => now()->subMinutes(10),
            'last_inbound_at' => now()->subMinutes(10),
        ]);
        $sendableMessage = Message::factory()->create([
            'dialog_id' => $sendableDialog->id,
            'contact_id' => $contact->id,
            'channel_id' => $sendableChannel->id,
            'contact_identity_id' => $sendableIdentity->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => 'sendable-chat',
        ]);

        $staleChannel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $staleIdentity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $staleChannel->id,
            'platform' => $staleChannel->platform,
        ]);
        $staleDialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $staleChannel->id,
            'current_contact_identity_id' => $staleIdentity->id,
            'external_chat_id' => null,
            'last_message_at' => now(),
            'last_inbound_at' => now(),
        ]);
        Message::factory()->create([
            'dialog_id' => $staleDialog->id,
            'contact_id' => $contact->id,
            'channel_id' => $staleChannel->id,
            'contact_identity_id' => $staleIdentity->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => 'stale-chat-id',
        ]);

        $nextField = app(ResumeContactDataCollectionAction::class)->handle($contact);

        $this->assertSame(Contact::DATA_COLLECTION_FIELD_FIRST_NAME, $nextField);

        $freshContact = $contact->fresh();

        $this->assertNotNull($freshContact);
        $this->assertSame(Contact::DATA_COLLECTION_STATUS_ACTIVE, $freshContact->data_collection_status);
        $this->assertSame(Contact::DATA_COLLECTION_FIELD_FIRST_NAME, $freshContact->data_collection_current_field);

        Queue::assertPushed(ProcessDataCollectionQuestionJob::class, function (ProcessDataCollectionQuestionJob $job) use ($contact, $sendableMessage): bool {
            return $job->sourceMessageId === $sendableMessage->id
                && $job->forceSend === true
                && $job->contactId === $contact->id
                && $job->expectedField === Contact::DATA_COLLECTION_FIELD_FIRST_NAME;
        });
    }

    public function test_action_can_resume_from_sendable_dialog_without_inbound_message(): void
    {
        Queue::fake();

        $contact = Contact::factory()->create([
            'data_collection_status' => null,
            'data_collection_current_field' => null,
            'first_name' => null,
            'country' => null,
            'city' => null,
            'age_range' => null,
        ]);

        ContactPhoneNumber::factory()->create([
            'contact_id' => $contact->id,
            'is_primary' => true,
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'sendable-chat',
            'last_message_at' => now(),
            'last_outbound_at' => now(),
        ]);
        $outboundMessage = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'contact_identity_id' => $identity->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_AUTO_REPLY,
            'external_chat_id' => 'sendable-chat',
        ]);

        $nextField = app(ResumeContactDataCollectionAction::class)->handle($contact);

        $this->assertSame(Contact::DATA_COLLECTION_FIELD_FIRST_NAME, $nextField);

        Queue::assertPushed(ProcessDataCollectionQuestionJob::class, function (ProcessDataCollectionQuestionJob $job) use ($contact, $outboundMessage): bool {
            return $job->sourceMessageId === $outboundMessage->id
                && $job->forceSend === true
                && $job->contactId === $contact->id
                && $job->expectedField === Contact::DATA_COLLECTION_FIELD_FIRST_NAME;
        });
    }

    public function test_action_does_not_activate_collector_when_sendable_dialog_has_no_messages(): void
    {
        Queue::fake();

        $contact = Contact::factory()->create([
            'data_collection_status' => null,
            'data_collection_current_field' => null,
            'first_name' => null,
            'country' => null,
            'city' => null,
            'age_range' => null,
        ]);

        ContactPhoneNumber::factory()->create([
            'contact_id' => $contact->id,
            'is_primary' => true,
        ]);

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
        ]);
        Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'sendable-chat',
            'last_message_at' => now(),
            'last_inbound_at' => now(),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Не удалось определить сообщение для возобновления анкеты.');

        try {
            app(ResumeContactDataCollectionAction::class)->handle($contact);
        } finally {
            $freshContact = $contact->fresh();

            $this->assertNotNull($freshContact);
            $this->assertNotSame(Contact::DATA_COLLECTION_STATUS_ACTIVE, $freshContact->data_collection_status);
            $this->assertNull($freshContact->data_collection_current_field);
            Queue::assertNothingPushed();
        }
    }
}
