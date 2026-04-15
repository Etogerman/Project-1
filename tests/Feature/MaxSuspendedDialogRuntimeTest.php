<?php

namespace Tests\Feature;

use App\Data\Bots\IncomingBotMessage;
use App\Data\Dialogs\DialogRouteStatusData;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Dialog;
use App\Models\Message;
use App\Models\User;
use App\Services\Bots\SendManualDialogReplyAction;
use App\Services\Bots\StoreInboundMessageAction;
use App\Services\Dialogs\ResolveDialogRouteStatusAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class MaxSuspendedDialogRuntimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_max_reply_suspended_response_marks_dialog_blocked_without_storing_outbound_message(): void
    {
        Http::fake([
            'https://platform-api.max.ru/messages*' => Http::response([
                'code' => 'chat.denied',
                'message' => 'Key: error.dialog.suspended, args: [228532008,].',
            ], 403),
        ]);

        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createMaxDialog();
        $initialMessageCount = Message::query()->count();

        try {
            app(SendManualDialogReplyAction::class)->handle($dialog, $admin, 'Проверка связи');
            $this->fail('MAX suspended response should block manual reply.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'Клиент заблокировал бота в MAX. Новые сообщения в этот диалог сейчас отправлять нельзя.',
                $exception->getMessage(),
            );
        }

        $dialog->refresh();
        $routeStatus = app(ResolveDialogRouteStatusAction::class)->handle($dialog);

        $this->assertSame($initialMessageCount, Message::query()->count());
        $this->assertSame(Dialog::BOT_SUBSCRIPTION_STATUS_BLOCKED_BY_USER, $dialog->bot_subscription_status);
        $this->assertNotNull($dialog->bot_subscription_changed_at);
        $this->assertNull($dialog->bot_subscription_source_message_id);
        $this->assertSame(DialogRouteStatusData::CODE_BLOCKED_BY_USER, $routeStatus->code);
        $this->assertFalse($routeStatus->isSendable);
        $this->assertSame(
            'Клиент заблокировал бота в MAX. Новые сообщения в этот диалог сейчас отправлять нельзя.',
            $routeStatus->blockedReason,
        );

        Http::assertSent(function (Request $request): bool {
            return str_starts_with($request->url(), 'https://platform-api.max.ru/messages?')
                && str_contains($request->url(), 'chat_id=max-chat-100')
                && $request['text'] === 'Проверка связи';
        });
    }

    public function test_newer_max_inbound_message_clears_suspended_state_as_recovery_signal(): void
    {
        $dialog = $this->createMaxDialog([
            'bot_subscription_status' => Dialog::BOT_SUBSCRIPTION_STATUS_BLOCKED_BY_USER,
            'bot_subscription_changed_at' => Carbon::parse('2026-04-15 10:00:00'),
        ]);

        $storedResult = app(StoreInboundMessageAction::class)->handle(
            $dialog->channel()->firstOrFail(),
            new IncomingBotMessage(
                platform: Channel::PLATFORM_MAX,
                channelId: $dialog->channel_id,
                externalChatId: 'max-chat-100',
                externalUserId: 'max-user-100',
                providerEventKey: 'max-message-after-suspended',
                externalMessageId: 'max-mid-after-suspended',
                externalUsername: 'max_user',
                contactName: 'MAX Клиент',
                text: 'Я снова написал',
                inboundKind: IncomingBotMessage::KIND_INBOUND_USER,
                sharedPhoneNumber: null,
                sharedContactUserId: null,
                rawPayload: ['update_type' => 'message_created'],
                receivedAt: Carbon::parse('2026-04-15 10:01:00'),
            ),
        );

        $dialog->refresh();

        $this->assertNull($dialog->bot_subscription_status);
        $this->assertSame('2026-04-15 10:01:00', $dialog->bot_subscription_changed_at?->format('Y-m-d H:i:s'));
        $this->assertSame($storedResult?->message->id, $dialog->bot_subscription_source_message_id);
    }

    public function test_older_max_inbound_message_does_not_clear_newer_suspended_state(): void
    {
        $dialog = $this->createMaxDialog([
            'bot_subscription_status' => Dialog::BOT_SUBSCRIPTION_STATUS_BLOCKED_BY_USER,
            'bot_subscription_changed_at' => Carbon::parse('2026-04-15 10:00:00'),
        ]);

        app(StoreInboundMessageAction::class)->handle(
            $dialog->channel()->firstOrFail(),
            new IncomingBotMessage(
                platform: Channel::PLATFORM_MAX,
                channelId: $dialog->channel_id,
                externalChatId: 'max-chat-100',
                externalUserId: 'max-user-100',
                providerEventKey: 'max-message-before-suspended',
                externalMessageId: 'max-mid-before-suspended',
                externalUsername: 'max_user',
                contactName: 'MAX Клиент',
                text: 'Старое сообщение',
                inboundKind: IncomingBotMessage::KIND_INBOUND_USER,
                sharedPhoneNumber: null,
                sharedContactUserId: null,
                rawPayload: ['update_type' => 'message_created'],
                receivedAt: Carbon::parse('2026-04-15 09:59:59'),
            ),
        );

        $dialog->refresh();

        $this->assertSame(Dialog::BOT_SUBSCRIPTION_STATUS_BLOCKED_BY_USER, $dialog->bot_subscription_status);
        $this->assertSame('2026-04-15 10:00:00', $dialog->bot_subscription_changed_at?->format('Y-m-d H:i:s'));
        $this->assertNull($dialog->bot_subscription_source_message_id);
    }

    /**
     * @param  array<string, mixed>  $dialogAttributes
     */
    private function createMaxDialog(array $dialogAttributes = []): Dialog
    {
        $contact = Contact::factory()->create();
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => ['token' => 'max-token'],
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'max-user-100',
        ]);

        return Dialog::factory()->create(array_merge([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'max-chat-100',
        ], $dialogAttributes));
    }
}
