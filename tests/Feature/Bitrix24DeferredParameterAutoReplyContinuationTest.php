<?php

namespace Tests\Feature;

use App\Jobs\ExportMessageToBitrix24OpenLinesJob;
use App\Jobs\ProcessDeferredParameterAutoReplyJob;
use App\Jobs\SyncContactToBitrix24Job;
use App\Models\Bitrix24Connection;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\ContactPhoneNumber;
use App\Models\Dialog;
use App\Models\Message;
use App\Services\Bitrix24\ExportMessageToBitrix24OpenLinesAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class Bitrix24DeferredParameterAutoReplyContinuationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('bitrix24.application.client_id', 'local.app');
        config()->set('bitrix24.application.client_secret', 'local.secret');
        config()->set('bitrix24.features.openlines_enabled', true);
        config()->set('bitrix24.openlines.telegram_connector_code', 'abrikosoff_telegram');
        config()->set('bitrix24.openlines.telegram_line_id', 'line-telegram');
        config()->set('bitrix24.openlines.max_connector_code', 'abrikosoff_max');
        config()->set('bitrix24.openlines.max_line_id', 'line-max');
        config()->set('bitrix24.sources.telegram_id', 'ABRIKOSOFF_TELEGRAM');
        config()->set('bitrix24.sources.max_id', 'ABRIKOSOFF_MAX');
        config()->set('bitrix24.duplicate_phone_diagnostic.enabled', false);
        config()->set('bitrix24.http.retry_sleep_milliseconds', 0);
    }

    public function test_first_successful_sync_queues_deferred_parameter_job_for_pending_dialog_without_relevant_retry(): void
    {
        Queue::fake();

        $this->makeActiveConnection();
        $channel = $this->makeTelegramChannel();
        $contact = $this->createSyncReadyContact([
            'bitrix24_contact_id' => null,
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_NOT_SYNCED,
            'bitrix24_last_synced_at' => null,
            'bitrix24_linked_at' => null,
            'bitrix24_sync_fingerprint' => null,
        ], channel: $channel);

        $dialog = $this->makeDialog($contact, $channel, [
            'bitrix24_live_status' => Dialog::BITRIX24_LIVE_STATUS_ACTIVE,
        ]);
        $pendingSource = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'Параметрический inbound без retry',
        ]);

        $dialog->forceFill([
            'pending_auto_reply_source_message_id' => $pendingSource->id,
        ])->save();

        $contact->forceFill([
            'bitrix24_sync_pending' => true,
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_PENDING,
        ])->save();

        Http::fake([
            'https://client-endpoint.example/rest/crm.duplicate.findbycomm.json' => Http::response([
                'result' => ['CONTACT' => []],
            ], 200),
            'https://client-endpoint.example/rest/crm.contact.add.json' => Http::response([
                'result' => 501,
            ], 200),
        ]);

        $this->runSyncJob($contact);

        Queue::assertPushed(ProcessDeferredParameterAutoReplyJob::class, function (ProcessDeferredParameterAutoReplyJob $job) use ($dialog): bool {
            return $job->dialogId === $dialog->id;
        });
        Queue::assertNotPushed(ExportMessageToBitrix24OpenLinesJob::class);
    }

    public function test_first_successful_sync_waits_for_relevant_retry_before_queuing_deferred_parameter_job(): void
    {
        Queue::fake();

        $this->makeActiveConnection();
        $channel = $this->makeTelegramChannel();
        $contact = $this->createSyncReadyContact([
            'bitrix24_contact_id' => null,
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_NOT_SYNCED,
            'bitrix24_last_synced_at' => null,
            'bitrix24_linked_at' => null,
            'bitrix24_sync_fingerprint' => null,
        ], channel: $channel);

        $dialog = $this->makeDialog($contact, $channel, [
            'bitrix24_live_status' => Dialog::BITRIX24_LIVE_STATUS_NOT_LINKED,
        ]);
        $pendingSource = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'Параметрический inbound с required retry',
        ]);

        $dialog->forceFill([
            'pending_auto_reply_source_message_id' => $pendingSource->id,
        ])->save();

        $contact->forceFill([
            'bitrix24_sync_pending' => true,
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_PENDING,
        ])->save();

        Http::fake([
            'https://client-endpoint.example/rest/crm.duplicate.findbycomm.json' => Http::response([
                'result' => ['CONTACT' => []],
            ], 200),
            'https://client-endpoint.example/rest/crm.contact.add.json' => Http::response([
                'result' => 501,
            ], 200),
        ]);

        $this->runSyncJob($contact);

        Queue::assertPushed(ExportMessageToBitrix24OpenLinesJob::class, function (ExportMessageToBitrix24OpenLinesJob $job) use ($pendingSource): bool {
            return $job->messageId === $pendingSource->id
                && $job->retryAfterSync === true;
        });
        Queue::assertNotPushed(ProcessDeferredParameterAutoReplyJob::class);
    }

    public function test_resync_of_already_linked_contact_queues_deferred_parameter_job_for_pending_dialog_without_relevant_retry(): void
    {
        Queue::fake();

        $this->makeActiveConnection();
        $channel = $this->makeTelegramChannel();
        $contact = $this->createSyncReadyContact([
            'bitrix24_contact_id' => '999',
            'bitrix24_sync_pending' => true,
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_PENDING,
            'bitrix24_linked_at' => now()->subDay(),
            'bitrix24_last_synced_at' => now()->subDay(),
            'bitrix24_sync_fingerprint' => 'existing-sync',
        ], channel: $channel);

        $dialog = $this->makeDialog($contact, $channel, [
            'bitrix24_live_status' => Dialog::BITRIX24_LIVE_STATUS_ACTIVE,
        ]);
        $pendingSource = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'Already linked pending dialog without retry',
        ]);

        $dialog->forceFill([
            'pending_auto_reply_source_message_id' => $pendingSource->id,
        ])->save();

        Http::fake([
            'https://client-endpoint.example/rest/crm.contact.get.json' => Http::response([
                'result' => $this->makeBitrix24ContactSnapshot($contact, $channel, [
                    'ID' => '999',
                ]),
            ], 200),
        ]);

        $this->runSyncJob($contact);

        Queue::assertPushed(ProcessDeferredParameterAutoReplyJob::class, function (ProcessDeferredParameterAutoReplyJob $job) use ($dialog): bool {
            return $job->dialogId === $dialog->id;
        });
        Queue::assertNotPushed(ExportMessageToBitrix24OpenLinesJob::class);
    }

    public function test_resync_of_already_linked_contact_waits_for_relevant_retry_before_queuing_deferred_parameter_job(): void
    {
        Queue::fake();

        $this->makeActiveConnection();
        $channel = $this->makeTelegramChannel();
        $contact = $this->createSyncReadyContact([
            'bitrix24_contact_id' => '999',
            'bitrix24_sync_pending' => true,
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_PENDING,
            'bitrix24_linked_at' => now()->subDay(),
            'bitrix24_last_synced_at' => now()->subDay(),
            'bitrix24_sync_fingerprint' => 'existing-sync',
        ], channel: $channel);

        $dialog = $this->makeDialog($contact, $channel, [
            'bitrix24_live_status' => Dialog::BITRIX24_LIVE_STATUS_NOT_LINKED,
        ]);
        $pendingSource = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'Already linked pending dialog with required retry',
        ]);

        $dialog->forceFill([
            'pending_auto_reply_source_message_id' => $pendingSource->id,
        ])->save();

        Http::fake([
            'https://client-endpoint.example/rest/crm.contact.get.json' => Http::response([
                'result' => $this->makeBitrix24ContactSnapshot($contact, $channel, [
                    'ID' => '999',
                ]),
            ], 200),
        ]);

        $this->runSyncJob($contact);

        Queue::assertPushed(ExportMessageToBitrix24OpenLinesJob::class, function (ExportMessageToBitrix24OpenLinesJob $job) use ($pendingSource): bool {
            return $job->messageId === $pendingSource->id
                && $job->retryAfterSync === true;
        });
        Queue::assertNotPushed(ProcessDeferredParameterAutoReplyJob::class);
    }

    public function test_successful_retry_after_sync_live_export_queues_deferred_parameter_job_for_same_dialog(): void
    {
        Queue::fake();

        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog(dialogAttributes: [
            'bitrix24_live_status' => Dialog::BITRIX24_LIVE_STATUS_NOT_LINKED,
        ]);
        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'Missed inbound before delayed reply',
        ]);

        $dialog->forceFill([
            'pending_auto_reply_source_message_id' => $message->id,
        ])->save();

        Http::fake([
            'https://client-endpoint.example/rest/imconnector.send.messages.json' => Http::response([
                'result' => true,
            ], 200),
        ]);

        app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message, retryAfterSync: true);

        Queue::assertPushed(ProcessDeferredParameterAutoReplyJob::class, function (ProcessDeferredParameterAutoReplyJob $job) use ($dialog): bool {
            return $job->dialogId === $dialog->id;
        });
    }

    public function test_regular_live_export_does_not_queue_deferred_parameter_job_without_retry_after_sync_flag(): void
    {
        Queue::fake();

        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog(dialogAttributes: [
            'bitrix24_live_status' => Dialog::BITRIX24_LIVE_STATUS_NOT_LINKED,
        ]);
        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'Обычный live export',
        ]);

        $dialog->forceFill([
            'pending_auto_reply_source_message_id' => $message->id,
        ])->save();

        Http::fake([
            'https://client-endpoint.example/rest/imconnector.send.messages.json' => Http::response([
                'result' => true,
            ], 200),
        ]);

        app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message, retryAfterSync: false);

        Queue::assertNotPushed(ProcessDeferredParameterAutoReplyJob::class);
    }

    public function test_retry_after_sync_export_does_not_queue_deferred_parameter_job_for_another_dialog(): void
    {
        Queue::fake();

        $this->makeActiveConnection();
        $dialog = $this->createLiveReadyDialog(dialogAttributes: [
            'bitrix24_live_status' => Dialog::BITRIX24_LIVE_STATUS_NOT_LINKED,
        ]);
        $message = $this->makeMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'Retry export в первом диалоге',
        ]);

        $secondChannel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'bot_username' => 'abrikosoff_max',
            'bot_name' => 'Abrikosoff MAX',
        ]);
        $secondIdentity = ContactIdentity::factory()->create([
            'contact_id' => $dialog->contact_id,
            'channel_id' => $secondChannel->id,
            'platform' => $secondChannel->platform,
            'external_user_id' => 'max-user-'.$dialog->contact_id,
            'external_username' => 'max_user_'.$dialog->contact_id,
        ]);
        $otherDialog = Dialog::factory()->create([
            'contact_id' => $dialog->contact_id,
            'channel_id' => $secondChannel->id,
            'current_contact_identity_id' => $secondIdentity->id,
            'external_chat_id' => 'max-chat-'.$dialog->contact_id,
            'bitrix24_live_status' => Dialog::BITRIX24_LIVE_STATUS_ACTIVE,
        ]);
        $otherPendingSource = $this->makeMessage($otherDialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'Pending во втором диалоге',
        ]);
        $otherDialog->forceFill([
            'pending_auto_reply_source_message_id' => $otherPendingSource->id,
        ])->save();

        Http::fake([
            'https://client-endpoint.example/rest/imconnector.send.messages.json' => Http::response([
                'result' => true,
            ], 200),
        ]);

        app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message, retryAfterSync: true);

        Queue::assertNotPushed(ProcessDeferredParameterAutoReplyJob::class);
    }

    private function createSyncReadyContact(
        array $contactOverrides = [],
        ?Channel $channel = null,
    ): Contact {
        $contact = Contact::factory()->create(array_merge([
            'first_name' => 'Герман',
            'last_name' => 'Абрикосов',
            'gender' => 'male',
            'age_years' => 28,
            'age_range' => '24_29',
            'country' => 'Россия',
            'city' => 'Москва',
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_COMPLETED,
            'data_collection_current_field' => null,
        ], $contactOverrides));

        $channel ??= $this->makeTelegramChannel();

        ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'telegram-user-'.$contact->id,
        ]);

        ContactPhoneNumber::factory()->create([
            'contact_id' => $contact->id,
            'phone_raw' => '+7 999 123 45 67',
            'phone_normalized' => '+79991234567',
            'is_primary' => true,
        ]);

        return $contact->fresh();
    }

    private function createLiveReadyDialog(
        string $platform = Channel::PLATFORM_TELEGRAM,
        array $contactAttributes = [],
        array $channelAttributes = [],
        array $dialogAttributes = [],
    ): Dialog {
        $contact = Contact::factory()->create(array_merge([
            'name' => 'Live Contact',
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_COMPLETED,
            'bitrix24_contact_id' => 'B24-CONTACT-100',
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_SYNCED,
            'bitrix24_sync_pending' => false,
        ], $contactAttributes));
        $channel = Channel::factory()->create(array_merge([
            'platform' => $platform,
            'bot_username' => $platform === Channel::PLATFORM_MAX ? 'abrikosoff_max' : 'abrikosoff_tg',
            'bot_name' => $platform === Channel::PLATFORM_MAX ? 'Abrikosoff MAX' : 'Abrikosoff TG',
        ], $channelAttributes));
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $platform,
            'external_user_id' => $platform.'-user-100',
            'external_username' => $platform.'_user_100',
        ]);
        ContactPhoneNumber::factory()->create([
            'contact_id' => $contact->id,
            'phone_raw' => '+7 999 123 45 67',
            'phone_normalized' => '+79991234567',
            'is_primary' => true,
        ]);

        return Dialog::factory()->create(array_merge([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => $platform.'-chat-100',
        ], $dialogAttributes));
    }

    private function makeTelegramChannel(array $overrides = []): Channel
    {
        return Channel::factory()->create(array_merge([
            'name' => 'Telegram Sales',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'bot_username' => 'abrikosoff_tg',
            'bot_name' => 'Abrikosoff TG',
        ], $overrides));
    }

    private function makeDialog(Contact $contact, Channel $channel, array $overrides = []): Dialog
    {
        $identity = $contact->identities()
            ->where('channel_id', $channel->id)
            ->firstOrFail();

        return Dialog::factory()->create(array_merge([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => $channel->platform.'-chat-'.$contact->id,
            'bitrix24_live_status' => Dialog::BITRIX24_LIVE_STATUS_NOT_LINKED,
        ], $overrides));
    }

    private function makeMessage(Dialog $dialog, array $attributes = []): Message
    {
        $dialog->loadMissing(['contact', 'channel', 'currentContactIdentity']);

        return Message::factory()->create(array_merge([
            'dialog_id' => $dialog->id,
            'contact_id' => $dialog->contact_id,
            'contact_identity_id' => $dialog->current_contact_identity_id,
            'channel_id' => $dialog->channel_id,
            'external_chat_id' => $dialog->external_chat_id,
            'external_message_id' => (string) fake()->numerify('######'),
            'received_at' => now(),
        ], $attributes));
    }

    private function makeActiveConnection(): Bitrix24Connection
    {
        return Bitrix24Connection::query()->forceCreate([
            'portal_domain' => 'crm.alexlesley.biz',
            'application_name' => 'Abrikosoff Connector',
            'client_id' => 'local.app',
            'member_id' => 'member-1',
            'application_token' => 'application-token',
            'status' => Bitrix24Connection::STATUS_ACTIVE,
            'access_token_encrypted' => 'access-token',
            'refresh_token_encrypted' => 'refresh-token',
            'access_token_expires_at' => now()->addHour(),
            'scope' => ['crm', 'imconnector', 'imopenlines'],
            'client_endpoint' => 'https://client-endpoint.example/rest/',
            'server_endpoint' => 'https://server-endpoint.example/rest/',
            'installed_at' => now(),
        ]);
    }

    private function runSyncJob(Contact $contact): void
    {
        $job = new SyncContactToBitrix24Job($contact->id);

        app()->call([$job, 'handle']);
    }
}
