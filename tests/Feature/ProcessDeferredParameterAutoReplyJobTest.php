<?php

namespace Tests\Feature;

use App\Jobs\ExportMessageToBitrix24OpenLinesJob;
use App\Jobs\ProcessDeferredParameterAutoReplyJob;
use App\Models\AutoReplyRule;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\ContactPhoneNumber;
use App\Models\Dialog;
use App\Models\Message;
use App\Services\Bots\QueueDeferredParameterAutoReplyAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ProcessDeferredParameterAutoReplyJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('bitrix24.features.openlines_enabled', true);
    }

    public function test_job_sends_single_delayed_reply_and_clears_pending(): void
    {
        Queue::fake();
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 9701,
                ],
            ]),
        ]);

        [$dialog, $sourceMessage] = $this->createPendingDialogWithSource();
        AutoReplyRule::factory()->forChannel($dialog->channel)->create([
            'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_PARAMETER,
            'keyword' => 'promo',
            'normalized_keyword' => AutoReplyRule::normalizeKeyword('promo'),
            'contact_phone_condition' => AutoReplyRule::CONTACT_PHONE_CONDITION_HAS_PHONE,
            'reply_text' => 'Первый delayed ответ',
            'priority' => 5,
        ]);
        AutoReplyRule::factory()->forChannel($dialog->channel)->create([
            'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_TEXT_OR_PARAMETER,
            'keyword' => 'promo',
            'normalized_keyword' => AutoReplyRule::normalizeKeyword('promo'),
            'contact_phone_condition' => AutoReplyRule::CONTACT_PHONE_CONDITION_HAS_PHONE,
            'reply_text' => 'Второй delayed ответ',
            'priority' => 20,
        ]);

        app()->call([new ProcessDeferredParameterAutoReplyJob($dialog->id), 'handle']);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === 'dialog-chat-current'
            && $request['text'] === 'Первый delayed ответ');

        $sourceMessage->refresh();
        $dialog->refresh();
        $outbound = Message::query()
            ->where('reply_to_message_id', $sourceMessage->id)
            ->where('message_kind', Message::KIND_OUTBOUND_AUTO_REPLY)
            ->firstOrFail();

        $this->assertNotNull($sourceMessage->auto_reply_sent_at);
        $this->assertNull($dialog->pending_auto_reply_source_message_id);
        $this->assertSame('Первый delayed ответ', $outbound->text);
        $this->assertSame('9701', $outbound->external_message_id);
        $this->assertSame('dialog-chat-current', $outbound->external_chat_id);
        $this->assertSame($dialog->current_contact_identity_id, $outbound->contact_identity_id);

        Queue::assertPushed(ExportMessageToBitrix24OpenLinesJob::class, function (ExportMessageToBitrix24OpenLinesJob $job) use ($outbound): bool {
            return $job->messageId === $outbound->id
                && $job->retryAfterSync === false;
        });
    }

    public function test_job_skips_and_clears_pending_when_legacy_cutover_is_enabled(): void
    {
        Queue::fake();
        Http::fake();
        config()->set('bots.legacy_auto_reply_rules_enabled', false);

        [$dialog, $sourceMessage] = $this->createPendingDialogWithSource();
        AutoReplyRule::factory()->forChannel($dialog->channel)->create([
            'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_PARAMETER,
            'keyword' => 'promo',
            'normalized_keyword' => AutoReplyRule::normalizeKeyword('promo'),
            'contact_phone_condition' => AutoReplyRule::CONTACT_PHONE_CONDITION_HAS_PHONE,
            'reply_text' => 'Старый delayed ответ',
        ]);

        app()->call([new ProcessDeferredParameterAutoReplyJob($dialog->id), 'handle']);

        Http::assertNothingSent();
        Queue::assertNotPushed(ExportMessageToBitrix24OpenLinesJob::class);
        $this->assertNull($dialog->fresh()->pending_auto_reply_source_message_id);
        $this->assertNull($sourceMessage->fresh()->auto_reply_sent_at);
        $this->assertSame(0, Message::query()
            ->where('reply_to_message_id', $sourceMessage->id)
            ->where('message_kind', Message::KIND_OUTBOUND_AUTO_REPLY)
            ->count());
    }

    public function test_queue_action_skips_and_clears_pending_when_legacy_cutover_is_enabled(): void
    {
        Queue::fake();
        config()->set('bots.legacy_auto_reply_rules_enabled', false);

        [$dialog] = $this->createPendingDialogWithSource();

        $queued = app(QueueDeferredParameterAutoReplyAction::class)->handle($dialog);

        $this->assertFalse($queued);
        Queue::assertNotPushed(ProcessDeferredParameterAutoReplyJob::class);
        $this->assertNull($dialog->fresh()->pending_auto_reply_source_message_id);
    }

    public function test_job_still_sends_delayed_reply_when_source_message_already_has_auto_reply_sent_at(): void
    {
        Queue::fake();
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 9705,
                ],
            ]),
        ]);

        [$dialog, $sourceMessage] = $this->createPendingDialogWithSource([
            'auto_reply_sent_at' => now(),
        ]);
        AutoReplyRule::factory()->forChannel($dialog->channel)->create([
            'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_PARAMETER,
            'keyword' => 'promo',
            'normalized_keyword' => AutoReplyRule::normalizeKeyword('promo'),
            'contact_phone_condition' => AutoReplyRule::CONTACT_PHONE_CONDITION_HAS_PHONE,
            'reply_text' => 'Финальный ответ после missing_phone',
        ]);

        app()->call([new ProcessDeferredParameterAutoReplyJob($dialog->id), 'handle']);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === 'dialog-chat-current'
            && $request['text'] === 'Финальный ответ после missing_phone');
        $this->assertNull($dialog->fresh()->pending_auto_reply_source_message_id);
        $this->assertSame(1, Message::query()
            ->where('reply_to_message_id', $sourceMessage->id)
            ->where('message_kind', Message::KIND_OUTBOUND_AUTO_REPLY)
            ->count());
    }

    public function test_job_clears_pending_when_no_delayed_rule_matches(): void
    {
        Queue::fake();
        Http::fake();

        [$dialog] = $this->createPendingDialogWithSource();
        AutoReplyRule::factory()->forChannel($dialog->channel)->create([
            'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
            'keyword' => null,
            'normalized_keyword' => null,
            'contact_phone_condition' => null,
            'reply_text' => 'Generic rule must be ignored',
        ]);

        app()->call([new ProcessDeferredParameterAutoReplyJob($dialog->id), 'handle']);

        Http::assertNothingSent();
        $this->assertNull($dialog->fresh()->pending_auto_reply_source_message_id);
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_job_keeps_pending_when_transport_fails(): void
    {
        Queue::fake();
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => false,
            ], 500),
        ]);

        [$dialog, $sourceMessage] = $this->createPendingDialogWithSource();
        AutoReplyRule::factory()->forChannel($dialog->channel)->create([
            'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_PARAMETER,
            'keyword' => 'promo',
            'normalized_keyword' => AutoReplyRule::normalizeKeyword('promo'),
            'contact_phone_condition' => AutoReplyRule::CONTACT_PHONE_CONDITION_HAS_PHONE,
            'reply_text' => 'Упадёт на отправке',
        ]);

        try {
            app()->call([new ProcessDeferredParameterAutoReplyJob($dialog->id), 'handle']);
            $this->fail('Expected delayed auto reply job to throw on failed delivery.');
        } catch (\Throwable) {
        }

        $sourceMessage->refresh();
        $dialog->refresh();

        $this->assertNull($sourceMessage->auto_reply_sent_at);
        $this->assertSame($sourceMessage->id, $dialog->pending_auto_reply_source_message_id);
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_job_does_not_duplicate_reply_on_repeat_run_after_success(): void
    {
        Queue::fake();
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 9702,
                ],
            ]),
        ]);

        [$dialog, $sourceMessage] = $this->createPendingDialogWithSource();
        AutoReplyRule::factory()->forChannel($dialog->channel)->create([
            'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_PARAMETER,
            'keyword' => 'promo',
            'normalized_keyword' => AutoReplyRule::normalizeKeyword('promo'),
            'contact_phone_condition' => AutoReplyRule::CONTACT_PHONE_CONDITION_HAS_PHONE,
            'reply_text' => 'Single delayed reply',
        ]);

        app()->call([new ProcessDeferredParameterAutoReplyJob($dialog->id), 'handle']);
        app()->call([new ProcessDeferredParameterAutoReplyJob($dialog->id), 'handle']);

        Http::assertSentCount(1);
        $this->assertNull($dialog->fresh()->pending_auto_reply_source_message_id);
        $this->assertSame(1, Message::query()
            ->where('reply_to_message_id', $sourceMessage->id)
            ->where('message_kind', Message::KIND_OUTBOUND_AUTO_REPLY)
            ->count());
    }

    public function test_job_still_sends_delayed_reply_for_completed_contact_with_auto_first_name(): void
    {
        Queue::fake();
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 9706,
                ],
            ]),
        ]);

        [$dialog, $sourceMessage] = $this->createPendingDialogWithSource(contactOverrides: [
            'first_name_source' => Contact::FIRST_NAME_SOURCE_AUTO,
        ]);
        AutoReplyRule::factory()->forChannel($dialog->channel)->create([
            'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_PARAMETER,
            'keyword' => 'promo',
            'normalized_keyword' => AutoReplyRule::normalizeKeyword('promo'),
            'contact_phone_condition' => AutoReplyRule::CONTACT_PHONE_CONDITION_HAS_PHONE,
            'reply_text' => 'Delayed ответ для completed auto first name',
        ]);

        app()->call([new ProcessDeferredParameterAutoReplyJob($dialog->id), 'handle']);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === 'dialog-chat-current'
            && $request['text'] === 'Delayed ответ для completed auto first name');
        $this->assertNull($dialog->fresh()->pending_auto_reply_source_message_id);
        $this->assertSame(1, Message::query()
            ->where('reply_to_message_id', $sourceMessage->id)
            ->where('message_kind', Message::KIND_OUTBOUND_AUTO_REPLY)
            ->count());
    }

    /**
     * @param  array<string, mixed>  $sourceOverrides
     * @param  array<string, mixed>  $contactOverrides
     * @return array{0: Dialog, 1: Message}
     */
    private function createPendingDialogWithSource(array $sourceOverrides = [], array $contactOverrides = []): array
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
            ],
            'auto_reply_mode' => Channel::AUTO_REPLY_MODE_RULES_ONLY,
            'is_active' => true,
        ]);

        $contact = Contact::factory()->create(array_merge([
            'first_name' => 'Герман',
            'last_name' => 'Абрикосов',
            'age_range' => '24_29',
            'country' => 'Россия',
            'city' => 'Москва',
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_COMPLETED,
            'data_collection_current_field' => null,
            'is_auto_reply_enabled' => true,
            'bitrix24_contact_id' => 'B24-CONTACT-501',
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_SYNCED,
            'bitrix24_sync_pending' => false,
            'bitrix24_linked_at' => now()->subDay(),
            'bitrix24_last_synced_at' => now()->subMinute(),
        ], $contactOverrides));

        $sourceIdentity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'old-user',
            'external_username' => 'old_user',
        ]);

        $currentIdentity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'current-user',
            'external_username' => 'current_user',
        ]);

        ContactPhoneNumber::factory()->create([
            'contact_id' => $contact->id,
            'phone_raw' => '+7 999 123 45 67',
            'phone_normalized' => '+79991234567',
            'is_primary' => true,
        ]);

        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $currentIdentity->id,
            'external_chat_id' => 'dialog-chat-current',
            'bitrix24_live_status' => Dialog::BITRIX24_LIVE_STATUS_ACTIVE,
        ]);

        $sourceMessage = Message::factory()->create(array_merge([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $sourceIdentity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'provider_event_key' => 'delayed-source-1',
            'external_chat_id' => 'legacy-chat-id',
            'external_message_id' => 'legacy-message-1',
            'text' => '/start promo',
            'message_parameter' => 'promo',
            'received_at' => now(),
            'auto_reply_sent_at' => null,
        ], $sourceOverrides));

        $dialog->forceFill([
            'pending_auto_reply_source_message_id' => $sourceMessage->id,
        ])->save();

        return [
            $dialog->fresh(['channel', 'contact', 'currentContactIdentity']),
            $sourceMessage->fresh(['channel', 'contact', 'contactIdentity']),
        ];
    }
}
