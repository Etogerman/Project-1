<?php

namespace Tests\Feature;

use App\Data\Bots\IncomingBotMessage;
use App\Jobs\ExportMessageToBitrix24OpenLinesJob;
use App\Jobs\ProcessAutoReplyJob;
use App\Jobs\ProcessDeferredParameterAutoReplyJob;
use App\Jobs\SyncContactToBitrix24Job;
use App\Models\AutoReplyRule;
use App\Models\Bitrix24Connection;
use App\Models\Bitrix24OpenLineRoute;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactPhoneNumber;
use App\Models\Dialog;
use App\Models\Message;
use App\Services\Bitrix24\ExportMessageToBitrix24OpenLinesAction;
use App\Services\Bots\StoreInboundMessageAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Concerns\InteractsWithBitrix24RuntimeProfile;
use Tests\TestCase;

class DeferredParameterAutoReplyFlowTest extends TestCase
{
    use InteractsWithBitrix24RuntimeProfile;
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

    public function test_parameter_inbound_captured_before_qualification_is_sent_after_sync_without_retry_requirement(): void
    {
        Queue::fake();
        Http::fake([
            'https://client-endpoint.example/rest/crm.duplicate.findbycomm.json' => Http::response([
                'result' => ['CONTACT' => []],
            ], 200),
            'https://client-endpoint.example/rest/crm.contact.add.json' => Http::response([
                'result' => 501,
            ], 200),
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 9801,
                ],
            ], 200),
        ]);

        $connection = $this->makeActiveConnection();
        $channel = $this->makeTelegramChannel();
        $this->makeOpenLineRoute($connection, $channel);

        $storedResult = app(StoreInboundMessageAction::class)->handle(
            $channel,
            $this->makeInboundUserMessage(
                channel: $channel,
                providerEventKey: 'telegram-e2e-deferred-no-retry',
                externalMessageId: 'telegram-e2e-deferred-no-retry',
                messageParameter: 'PROMO_SYNC',
                receivedAt: Carbon::parse('2026-04-09 14:00:00'),
            ),
        );

        $dialog = Dialog::query()->findOrFail($storedResult->message->dialog_id);
        $contact = Contact::query()->findOrFail($storedResult->message->contact_id);

        $this->assertSame($storedResult->message->id, $dialog->pending_auto_reply_source_message_id);

        $this->qualifyContactForSync($contact);
        $dialog->forceFill([
            'bitrix24_live_status' => Dialog::BITRIX24_LIVE_STATUS_ACTIVE,
        ])->save();

        AutoReplyRule::factory()->forChannel($channel)->create([
            'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_PARAMETER,
            'keyword' => 'PROMO_SYNC',
            'normalized_keyword' => AutoReplyRule::normalizeKeyword('PROMO_SYNC'),
            'contact_phone_condition' => AutoReplyRule::CONTACT_PHONE_CONDITION_HAS_PHONE,
            'reply_text' => 'Финальный delayed ответ без retry',
        ]);

        $this->runSyncJob($contact);

        Queue::assertPushed(ProcessDeferredParameterAutoReplyJob::class, function (ProcessDeferredParameterAutoReplyJob $job) use ($dialog): bool {
            return $job->dialogId === $dialog->id;
        });
        Queue::assertNotPushed(ExportMessageToBitrix24OpenLinesJob::class, function (ExportMessageToBitrix24OpenLinesJob $job) use ($storedResult): bool {
            return $job->messageId === $storedResult->message->id
                && $job->retryAfterSync === true;
        });
        $this->assertSame(0, Message::query()
            ->where('reply_to_message_id', $storedResult->message->id)
            ->where('message_kind', Message::KIND_OUTBOUND_AUTO_REPLY)
            ->count());

        app()->call([new ProcessDeferredParameterAutoReplyJob($dialog->id), 'handle']);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === $dialog->external_chat_id
            && $request['text'] === 'Финальный delayed ответ без retry');

        $storedResult->message->refresh();
        $dialog->refresh();
        $outbound = Message::query()
            ->where('reply_to_message_id', $storedResult->message->id)
            ->where('message_kind', Message::KIND_OUTBOUND_AUTO_REPLY)
            ->firstOrFail();

        $this->assertNotNull($storedResult->message->auto_reply_sent_at);
        $this->assertNull($dialog->pending_auto_reply_source_message_id);
        $this->assertSame('Финальный delayed ответ без retry', $outbound->text);

        Queue::assertPushed(ExportMessageToBitrix24OpenLinesJob::class, function (ExportMessageToBitrix24OpenLinesJob $job) use ($outbound): bool {
            return $job->messageId === $outbound->id
                && $job->retryAfterSync === false;
        });
    }

    public function test_parameter_reply_waits_for_retry_after_sync_export_before_delayed_send(): void
    {
        Queue::fake();
        Http::fake([
            'https://client-endpoint.example/rest/crm.duplicate.findbycomm.json' => Http::response([
                'result' => ['CONTACT' => []],
            ], 200),
            'https://client-endpoint.example/rest/crm.contact.add.json' => Http::response([
                'result' => 502,
            ], 200),
            'https://client-endpoint.example/rest/imconnector.send.messages.json' => Http::response([
                'result' => true,
            ], 200),
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 9802,
                ],
            ], 200),
        ]);

        $connection = $this->makeActiveConnection();
        $channel = $this->makeTelegramChannel();
        $this->makeOpenLineRoute($connection, $channel);

        $storedResult = app(StoreInboundMessageAction::class)->handle(
            $channel,
            $this->makeInboundUserMessage(
                channel: $channel,
                providerEventKey: 'telegram-e2e-deferred-with-retry',
                externalMessageId: 'telegram-e2e-deferred-with-retry',
                messageParameter: 'PROMO_RETRY',
                receivedAt: Carbon::parse('2026-04-09 14:10:00'),
            ),
        );

        $dialog = Dialog::query()->findOrFail($storedResult->message->dialog_id);
        $contact = Contact::query()->findOrFail($storedResult->message->contact_id);

        $dialog->forceFill([
            'bitrix24_live_status' => Dialog::BITRIX24_LIVE_STATUS_NOT_LINKED,
        ])->save();

        $this->qualifyContactForSync($contact);

        AutoReplyRule::factory()->forChannel($channel)->create([
            'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_PARAMETER,
            'keyword' => 'PROMO_RETRY',
            'normalized_keyword' => AutoReplyRule::normalizeKeyword('PROMO_RETRY'),
            'contact_phone_condition' => AutoReplyRule::CONTACT_PHONE_CONDITION_HAS_PHONE,
            'reply_text' => 'Финальный delayed ответ после retry',
        ]);

        $this->runSyncJob($contact);

        Queue::assertPushed(ExportMessageToBitrix24OpenLinesJob::class, function (ExportMessageToBitrix24OpenLinesJob $job) use ($storedResult): bool {
            return $job->messageId === $storedResult->message->id
                && $job->retryAfterSync === true;
        });
        Queue::assertNotPushed(ProcessDeferredParameterAutoReplyJob::class);
        $this->assertSame(0, Message::query()
            ->where('reply_to_message_id', $storedResult->message->id)
            ->where('message_kind', Message::KIND_OUTBOUND_AUTO_REPLY)
            ->count());

        app(ExportMessageToBitrix24OpenLinesAction::class)->handle($storedResult->message, retryAfterSync: true);

        Queue::assertPushed(ProcessDeferredParameterAutoReplyJob::class, function (ProcessDeferredParameterAutoReplyJob $job) use ($dialog): bool {
            return $job->dialogId === $dialog->id;
        });
        $this->assertSame(0, Message::query()
            ->where('reply_to_message_id', $storedResult->message->id)
            ->where('message_kind', Message::KIND_OUTBOUND_AUTO_REPLY)
            ->count());

        app()->call([new ProcessDeferredParameterAutoReplyJob($dialog->id), 'handle']);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === $dialog->external_chat_id
            && $request['text'] === 'Финальный delayed ответ после retry');

        $storedResult->message->refresh();
        $dialog->refresh();
        $outbound = Message::query()
            ->where('reply_to_message_id', $storedResult->message->id)
            ->where('message_kind', Message::KIND_OUTBOUND_AUTO_REPLY)
            ->firstOrFail();

        $this->assertSame(Dialog::BITRIX24_LIVE_STATUS_ACTIVE, $dialog->bitrix24_live_status);
        $this->assertNotNull($storedResult->message->auto_reply_sent_at);
        $this->assertNull($dialog->pending_auto_reply_source_message_id);
        $this->assertSame('Финальный delayed ответ после retry', $outbound->text);

        Queue::assertPushed(ExportMessageToBitrix24OpenLinesJob::class, function (ExportMessageToBitrix24OpenLinesJob $job) use ($outbound): bool {
            return $job->messageId === $outbound->id
                && $job->retryAfterSync === false;
        });
    }

    public function test_max_parameter_inbound_is_replied_after_fake_sync_and_fake_retry_export(): void
    {
        Queue::fake();
        Http::fake([
            'https://platform-api.max.ru/*' => Http::response([
                'message' => [
                    'body' => [
                        'mid' => 'max-delayed-9001',
                    ],
                ],
            ], 200),
        ]);

        config()->set('bitrix24.features.fake_happy_path_enabled', true);

        $connection = $this->makeActiveConnection();
        $channel = $this->makeMaxChannel();
        $this->makeOpenLineRoute($connection, $channel);

        $storedResult = app(StoreInboundMessageAction::class)->handle(
            $channel,
            $this->makeInboundUserMessage(
                channel: $channel,
                providerEventKey: 'max-e2e-deferred-fake-retry',
                externalMessageId: 'max-e2e-deferred-fake-retry',
                messageParameter: 'PROMO_MAX',
                receivedAt: Carbon::parse('2026-04-09 14:20:00'),
                externalChatId: 'max-chat-100',
                externalUserId: 'max-user-100',
            ),
        );

        $dialog = Dialog::query()->findOrFail($storedResult->message->dialog_id);
        $contact = Contact::query()->findOrFail($storedResult->message->contact_id);

        $dialog->forceFill([
            'bitrix24_live_status' => Dialog::BITRIX24_LIVE_STATUS_NOT_LINKED,
        ])->save();

        AutoReplyRule::factory()->forChannel($channel)->create([
            'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
            'keyword' => null,
            'normalized_keyword' => null,
            'contact_phone_condition' => AutoReplyRule::CONTACT_PHONE_CONDITION_MISSING_PHONE,
            'reply_text' => 'Сначала запросим телефон',
        ]);
        AutoReplyRule::factory()->forChannel($channel)->create([
            'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_PARAMETER,
            'keyword' => 'PROMO_MAX',
            'normalized_keyword' => AutoReplyRule::normalizeKeyword('PROMO_MAX'),
            'contact_phone_condition' => AutoReplyRule::CONTACT_PHONE_CONDITION_HAS_PHONE,
            'reply_text' => 'MAX delayed fake reply',
        ]);

        app()->call([new ProcessAutoReplyJob($storedResult->message->id), 'handle']);

        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://platform-api.max.ru/messages?')
            && str_contains($request->url(), 'chat_id=max-chat-100')
            && $request['text'] === 'Сначала запросим телефон');
        $storedResult->message->refresh();
        $this->assertNotNull($storedResult->message->auto_reply_sent_at);

        $this->qualifyContactForSync($contact);
        $this->runSyncJob($contact);

        Queue::assertPushed(ExportMessageToBitrix24OpenLinesJob::class, function (ExportMessageToBitrix24OpenLinesJob $job) use ($storedResult): bool {
            return $job->messageId === $storedResult->message->id
                && $job->retryAfterSync === true;
        });
        Queue::assertNotPushed(ProcessDeferredParameterAutoReplyJob::class);

        app(ExportMessageToBitrix24OpenLinesAction::class)->handle($storedResult->message, retryAfterSync: true);

        Queue::assertPushed(ProcessDeferredParameterAutoReplyJob::class, function (ProcessDeferredParameterAutoReplyJob $job) use ($dialog): bool {
            return $job->dialogId === $dialog->id;
        });

        app()->call([new ProcessDeferredParameterAutoReplyJob($dialog->id), 'handle']);

        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://platform-api.max.ru/messages?')
            && str_contains($request->url(), 'chat_id=max-chat-100')
            && $request['text'] === 'MAX delayed fake reply');
        Http::assertSentCount(2);

        $storedResult->message->refresh();
        $dialog->refresh();
        $outbound = Message::query()
            ->where('reply_to_message_id', $storedResult->message->id)
            ->where('message_kind', Message::KIND_OUTBOUND_AUTO_REPLY)
            ->where('text', 'MAX delayed fake reply')
            ->firstOrFail();

        $this->assertSame(Dialog::BITRIX24_LIVE_STATUS_ACTIVE, $dialog->bitrix24_live_status);
        $this->assertSame('fake-live-dialog-'.$dialog->id, $dialog->bitrix24_live_chat_id);
        $this->assertNotNull($storedResult->message->auto_reply_sent_at);
        $this->assertNull($dialog->pending_auto_reply_source_message_id);
        $this->assertSame('MAX delayed fake reply', $outbound->text);
        $this->assertSame('max-chat-100', $outbound->external_chat_id);

        Queue::assertPushed(ExportMessageToBitrix24OpenLinesJob::class, function (ExportMessageToBitrix24OpenLinesJob $job) use ($outbound): bool {
            return $job->messageId === $outbound->id
                && $job->retryAfterSync === false;
        });
    }

    private function makeTelegramChannel(array $overrides = []): Channel
    {
        return Channel::factory()->create(array_merge([
            'name' => 'Telegram Sales',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'bot_username' => 'abrikosoff_tg',
            'bot_name' => 'Abrikosoff TG',
            'auto_reply_mode' => Channel::AUTO_REPLY_MODE_RULES_ONLY,
            'is_active' => true,
            'credentials' => [
                'token' => 'telegram-token',
            ],
        ], $overrides));
    }

    private function makeMaxChannel(array $overrides = []): Channel
    {
        return Channel::factory()->create(array_merge([
            'name' => 'MAX Sales',
            'platform' => Channel::PLATFORM_MAX,
            'bot_username' => 'abrikosoff_max',
            'bot_name' => 'Abrikosoff MAX',
            'auto_reply_mode' => Channel::AUTO_REPLY_MODE_RULES_ONLY,
            'is_active' => true,
            'credentials' => [
                'token' => 'max-token',
            ],
        ], $overrides));
    }

    private function makeOpenLineRoute(Bitrix24Connection $connection, Channel $channel): Bitrix24OpenLineRoute
    {
        $profile = $connection->profile()->firstOrFail();

        return Bitrix24OpenLineRoute::query()->create([
            'bitrix24_profile_id' => $profile->id,
            'channel_id' => $channel->id,
            'portal_domain' => $profile->portal_domain,
            'profile_key' => $profile->profile_key,
            'channel_type' => Bitrix24OpenLineRoute::channelTypeForChannel($channel),
            'connector_code' => $profile->openLinesConnectorCodeForPlatform($channel->platform),
            'line_id' => $profile->openLinesLineIdForPlatform($channel->platform),
            'source_id' => $profile->sourceIdForPlatform($channel->platform),
            'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
        ]);
    }

    private function makeInboundUserMessage(
        Channel $channel,
        string $providerEventKey,
        string $externalMessageId,
        string $messageParameter,
        Carbon $receivedAt,
        string $externalChatId = 'telegram-chat-100',
        string $externalUserId = 'telegram-user-100',
    ): IncomingBotMessage {
        return new IncomingBotMessage(
            platform: $channel->platform,
            channelId: $channel->id,
            externalChatId: $externalChatId,
            externalUserId: $externalUserId,
            providerEventKey: $providerEventKey,
            externalMessageId: $externalMessageId,
            externalUsername: 'telegram_user',
            contactName: 'Тестовый контакт',
            text: '/start '.$messageParameter,
            inboundKind: IncomingBotMessage::KIND_INBOUND_USER,
            sharedPhoneNumber: null,
            sharedContactUserId: null,
            rawPayload: [
                'message' => [
                    'text' => '/start '.$messageParameter,
                ],
            ],
            receivedAt: $receivedAt,
            messageParameter: $messageParameter,
        );
    }

    private function qualifyContactForSync(Contact $contact): void
    {
        ContactPhoneNumber::factory()->create([
            'contact_id' => $contact->id,
            'phone_raw' => '+7 999 123 45 67',
            'phone_normalized' => '+79991234567',
            'is_primary' => true,
        ]);

        $contact->forceFill([
            'first_name' => 'Герман',
            'last_name' => 'Абрикосов',
            'country' => 'Россия',
            'city' => 'Москва',
            'age_range' => '24_29',
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_COMPLETED,
            'data_collection_current_field' => null,
            'is_auto_reply_enabled' => true,
            'bitrix24_contact_id' => null,
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_PENDING,
            'bitrix24_sync_pending' => true,
            'bitrix24_last_synced_at' => null,
            'bitrix24_linked_at' => null,
            'bitrix24_sync_fingerprint' => null,
        ])->save();
    }

    private function makeActiveConnection(): Bitrix24Connection
    {
        return $this->makeProfileLinkedActiveBitrix24Connection([
            'scope' => ['crm', 'imconnector', 'imopenlines'],
        ]);
    }

    private function runSyncJob(Contact $contact): void
    {
        app()->call([new SyncContactToBitrix24Job($contact->id), 'handle']);
    }
}
