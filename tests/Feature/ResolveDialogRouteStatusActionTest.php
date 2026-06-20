<?php

namespace Tests\Feature;

use App\Data\Dialogs\DialogRouteStatusData;
use App\Models\Channel;
use App\Models\ChannelRuntimeState;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Dialog;
use App\Services\Dialogs\CanSendThroughDialogAction;
use App\Services\Dialogs\ResolveDialogRouteStatusAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResolveDialogRouteStatusActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolve_dialog_route_status_marks_ready_telegram_route(): void
    {
        $dialog = $this->createDialog(
            channelAttributes: [
                'platform' => Channel::PLATFORM_TELEGRAM,
                'credentials' => ['token' => 'telegram-token'],
            ],
            dialogAttributes: [
                'external_chat_id' => 'telegram-ready-chat',
            ],
        );

        $status = app(ResolveDialogRouteStatusAction::class)->handle($dialog);

        $this->assertSame(DialogRouteStatusData::CODE_READY, $status->code);
        $this->assertSame('Маршрут готов', $status->label);
        $this->assertSame('success', $status->tone);
        $this->assertTrue($status->isSendable);
        $this->assertNull($status->blockedReason);
        $this->assertTrue(app(CanSendThroughDialogAction::class)->handle($dialog));
    }

    public function test_resolve_dialog_route_status_marks_disconnected_telegram_channel(): void
    {
        $dialog = $this->createDialog(
            channelAttributes: [
                'platform' => Channel::PLATFORM_TELEGRAM,
                'credentials' => ['token' => 'telegram-token'],
                'connection_status' => Channel::CONNECTION_STATUS_NOT_CONNECTED,
                'webhook_status' => Channel::WEBHOOK_STATUS_NOT_INSTALLED,
                'connection_checked_at' => now(),
                'connection_error_message' => 'Webhook установлен не на эту админку',
            ],
            dialogAttributes: [
                'external_chat_id' => 'telegram-disconnected-chat',
            ],
        );

        $status = app(ResolveDialogRouteStatusAction::class)->handle($dialog);

        $this->assertSame(DialogRouteStatusData::CODE_CHANNEL_NOT_CONNECTED, $status->code);
        $this->assertSame('Канал не подключен', $status->label);
        $this->assertSame('danger', $status->tone);
        $this->assertFalse($status->isSendable);
        $this->assertSame('Webhook установлен не на эту админку', $status->blockedReason);
        $this->assertFalse(app(CanSendThroughDialogAction::class)->handle($dialog));
    }

    public function test_resolve_dialog_route_status_allows_stale_successful_telegram_check_with_warning(): void
    {
        $dialog = $this->createDialog(
            channelAttributes: [
                'platform' => Channel::PLATFORM_TELEGRAM,
                'credentials' => ['token' => 'telegram-token'],
                'connection_status' => Channel::CONNECTION_STATUS_CONNECTED,
                'webhook_status' => Channel::WEBHOOK_STATUS_INSTALLED,
                'connection_checked_at' => now()->subMinutes(11),
                'connection_error_message' => null,
            ],
            dialogAttributes: [
                'external_chat_id' => 'telegram-stale-chat',
            ],
        );

        $status = app(ResolveDialogRouteStatusAction::class)->handle($dialog);

        $this->assertSame(DialogRouteStatusData::CODE_READY, $status->code);
        $this->assertSame('Проверка устарела', $status->label);
        $this->assertSame('warning', $status->tone);
        $this->assertTrue($status->isSendable);
        $this->assertNull($status->blockedReason);
        $this->assertTrue(app(CanSendThroughDialogAction::class)->handle($dialog));
    }

    public function test_resolve_dialog_route_status_marks_missing_telegram_chat_id(): void
    {
        $dialog = $this->createDialog(
            channelAttributes: [
                'platform' => Channel::PLATFORM_TELEGRAM,
                'credentials' => ['token' => 'telegram-token'],
            ],
            dialogAttributes: [
                'external_chat_id' => '',
            ],
        );

        $status = app(ResolveDialogRouteStatusAction::class)->handle($dialog);

        $this->assertSame(DialogRouteStatusData::CODE_MISSING_CHAT_ID, $status->code);
        $this->assertSame('Нет chat id', $status->label);
        $this->assertSame('warning', $status->tone);
        $this->assertFalse($status->isSendable);
        $this->assertSame('У этого диалога сейчас нет рабочего маршрута для отправки ответа.', $status->blockedReason);
        $this->assertFalse(app(CanSendThroughDialogAction::class)->handle($dialog));
    }

    public function test_resolve_dialog_route_status_allows_max_user_route_without_chat_id(): void
    {
        $dialog = $this->createDialog(
            channelAttributes: [
                'platform' => Channel::PLATFORM_MAX,
                'credentials' => ['token' => 'max-token'],
            ],
            identityAttributes: [
                'external_user_id' => 'max-user-100',
            ],
            dialogAttributes: [
                'external_chat_id' => null,
            ],
        );

        $status = app(ResolveDialogRouteStatusAction::class)->handle($dialog);

        $this->assertSame(DialogRouteStatusData::CODE_READY, $status->code);
        $this->assertTrue($status->isSendable);
    }

    public function test_resolve_dialog_route_status_marks_missing_token(): void
    {
        $dialog = $this->createDialog(
            channelAttributes: [
                'platform' => Channel::PLATFORM_TELEGRAM,
                'credentials' => [],
            ],
            dialogAttributes: [
                'external_chat_id' => 'telegram-no-token-chat',
            ],
        );

        $status = app(ResolveDialogRouteStatusAction::class)->handle($dialog);

        $this->assertSame(DialogRouteStatusData::CODE_MISSING_TOKEN, $status->code);
        $this->assertSame('Нет токена', $status->label);
        $this->assertFalse($status->isSendable);
    }

    public function test_resolve_dialog_route_status_marks_dialog_blocked_by_user(): void
    {
        $dialog = $this->createDialog(
            channelAttributes: [
                'platform' => Channel::PLATFORM_TELEGRAM,
                'credentials' => ['token' => 'telegram-token'],
            ],
            dialogAttributes: [
                'external_chat_id' => 'telegram-blocked-chat',
                'bot_subscription_status' => Dialog::BOT_SUBSCRIPTION_STATUS_BLOCKED_BY_USER,
                'bot_subscription_changed_at' => now(),
            ],
        );

        $status = app(ResolveDialogRouteStatusAction::class)->handle($dialog);

        $this->assertSame(DialogRouteStatusData::CODE_BLOCKED_BY_USER, $status->code);
        $this->assertSame('Бот заблокирован', $status->label);
        $this->assertSame('danger', $status->tone);
        $this->assertFalse($status->isSendable);
        $this->assertSame('Клиент заблокировал бота в Telegram. Новые сообщения в этот диалог сейчас отправлять нельзя.', $status->blockedReason);
        $this->assertFalse(app(CanSendThroughDialogAction::class)->handle($dialog));
    }

    public function test_resolve_dialog_route_status_prioritizes_blocked_by_user_reason_over_missing_token(): void
    {
        $dialog = $this->createDialog(
            channelAttributes: [
                'platform' => Channel::PLATFORM_TELEGRAM,
                'credentials' => [],
            ],
            dialogAttributes: [
                'external_chat_id' => 'telegram-blocked-no-token-chat',
                'bot_subscription_status' => Dialog::BOT_SUBSCRIPTION_STATUS_BLOCKED_BY_USER,
                'bot_subscription_changed_at' => now(),
            ],
        );

        $status = app(ResolveDialogRouteStatusAction::class)->handle($dialog);

        $this->assertSame(DialogRouteStatusData::CODE_BLOCKED_BY_USER, $status->code);
        $this->assertSame('Бот заблокирован', $status->label);
        $this->assertSame('Клиент заблокировал бота в Telegram. Новые сообщения в этот диалог сейчас отправлять нельзя.', $status->blockedReason);
    }

    public function test_resolve_dialog_route_status_marks_inactive_channel(): void
    {
        $dialog = $this->createDialog(
            channelAttributes: [
                'platform' => Channel::PLATFORM_MAX,
                'credentials' => ['token' => 'max-token'],
                'is_active' => false,
            ],
        );

        $status = app(ResolveDialogRouteStatusAction::class)->handle($dialog);

        $this->assertSame(DialogRouteStatusData::CODE_CHANNEL_INACTIVE, $status->code);
        $this->assertSame('Канал неактивен', $status->label);
        $this->assertFalse($status->isSendable);
    }

    public function test_resolve_dialog_route_status_marks_missing_max_route_source(): void
    {
        $dialog = $this->createDialog(
            channelAttributes: [
                'platform' => Channel::PLATFORM_MAX,
                'credentials' => ['token' => 'max-token'],
            ],
            identityAttributes: [
                'external_user_id' => '',
            ],
            dialogAttributes: [
                'external_chat_id' => '',
            ],
        );

        $status = app(ResolveDialogRouteStatusAction::class)->handle($dialog);

        $this->assertSame(DialogRouteStatusData::CODE_MISSING_ROUTE_SOURCE, $status->code);
        $this->assertSame('Нет route source', $status->label);
        $this->assertFalse($status->isSendable);
    }

    public function test_resolve_dialog_route_status_marks_non_bot_channel(): void
    {
        $dialog = $this->createDialog(
            channelAttributes: [
                'platform' => Channel::PLATFORM_TELEGRAM,
                'credentials' => ['token' => 'telegram-token'],
                'connection_type' => 'webhook',
            ],
        );

        $status = app(ResolveDialogRouteStatusAction::class)->handle($dialog);

        $this->assertSame(DialogRouteStatusData::CODE_NOT_BOT_CHANNEL, $status->code);
        $this->assertSame('Не bot-канал', $status->label);
        $this->assertFalse($status->isSendable);
    }

    public function test_resolve_dialog_route_status_marks_ready_telegram_account_route_when_gateway_is_live(): void
    {
        $dialog = $this->createDialog(
            channelAttributes: [
                'platform' => Channel::PLATFORM_TELEGRAM,
                'connection_type' => Channel::CONNECTION_TYPE_ACCOUNT,
                'credentials' => [],
                'bot_token_present' => false,
            ],
            dialogAttributes: [
                'external_chat_id' => 'telegram-account-chat',
            ],
        );

        ChannelRuntimeState::query()->create([
            'channel_id' => $dialog->channel_id,
            'auth_status' => ChannelRuntimeState::AUTH_STATUS_AUTHORIZED,
            'authorization_state' => ChannelRuntimeState::AUTHORIZATION_STATE_READY,
            'sync_status' => ChannelRuntimeState::SYNC_STATUS_LIVE,
            'last_gateway_heartbeat_at' => now(),
            'runtime_payload' => [
                'gateway_capabilities' => [
                    'outgoing_replies' => true,
                ],
            ],
        ]);

        $status = app(ResolveDialogRouteStatusAction::class)->handle($dialog->fresh(['channel.runtimeState', 'currentContactIdentity']));

        $this->assertSame(DialogRouteStatusData::CODE_READY, $status->code);
        $this->assertSame('Маршрут готов', $status->label);
        $this->assertTrue($status->isSendable);
        $this->assertTrue(app(CanSendThroughDialogAction::class)->handle($dialog->fresh(['channel.runtimeState', 'currentContactIdentity'])));
    }

    public function test_resolve_dialog_route_status_blocks_telegram_account_route_until_gateway_supports_outgoing_replies(): void
    {
        $dialog = $this->createDialog(
            channelAttributes: [
                'platform' => Channel::PLATFORM_TELEGRAM,
                'connection_type' => Channel::CONNECTION_TYPE_ACCOUNT,
                'credentials' => [],
                'bot_token_present' => false,
            ],
            dialogAttributes: [
                'external_chat_id' => 'telegram-account-chat',
            ],
        );

        ChannelRuntimeState::query()->create([
            'channel_id' => $dialog->channel_id,
            'auth_status' => ChannelRuntimeState::AUTH_STATUS_AUTHORIZED,
            'authorization_state' => ChannelRuntimeState::AUTHORIZATION_STATE_READY,
            'sync_status' => ChannelRuntimeState::SYNC_STATUS_LIVE,
            'last_gateway_heartbeat_at' => now(),
            'runtime_payload' => [],
        ]);

        $status = app(ResolveDialogRouteStatusAction::class)->handle($dialog->fresh(['channel.runtimeState', 'currentContactIdentity']));

        $this->assertSame(DialogRouteStatusData::CODE_ACCOUNT_NOT_READY, $status->code);
        $this->assertSame('Gateway не готов к исходящим ответам', $status->label);
        $this->assertFalse($status->isSendable);
        $this->assertSame('Gateway не подтвердил отправку исходящих ответов для этого Telegram account.', $status->blockedReason);
        $this->assertFalse(app(CanSendThroughDialogAction::class)->handle($dialog->fresh(['channel.runtimeState', 'currentContactIdentity'])));
    }

    public function test_resolve_dialog_route_status_blocks_telegram_account_route_until_gateway_is_live(): void
    {
        $dialog = $this->createDialog(
            channelAttributes: [
                'platform' => Channel::PLATFORM_TELEGRAM,
                'connection_type' => Channel::CONNECTION_TYPE_ACCOUNT,
                'credentials' => [],
                'bot_token_present' => false,
            ],
            dialogAttributes: [
                'external_chat_id' => 'telegram-account-chat',
            ],
        );

        ChannelRuntimeState::query()->create([
            'channel_id' => $dialog->channel_id,
            'auth_status' => ChannelRuntimeState::AUTH_STATUS_PENDING,
            'authorization_state' => ChannelRuntimeState::AUTHORIZATION_STATE_AWAITING_QR,
            'sync_status' => ChannelRuntimeState::SYNC_STATUS_IDLE,
            'runtime_payload' => [],
        ]);

        $status = app(ResolveDialogRouteStatusAction::class)->handle($dialog->fresh(['channel.runtimeState', 'currentContactIdentity']));

        $this->assertSame(DialogRouteStatusData::CODE_ACCOUNT_NOT_READY, $status->code);
        $this->assertSame('Gateway не готов к исходящим ответам', $status->label);
        $this->assertFalse($status->isSendable);
        $this->assertSame('Telegram account не авторизован, поэтому gateway не может отправлять исходящие ответы.', $status->blockedReason);
    }

    public function test_resolve_dialog_route_status_marks_unsupported_platform(): void
    {
        $dialog = $this->createDialog(
            channelAttributes: [
                'platform' => 'whatsapp',
                'credentials' => ['token' => 'unsupported-token'],
            ],
        );

        $status = app(ResolveDialogRouteStatusAction::class)->handle($dialog);

        $this->assertSame(DialogRouteStatusData::CODE_UNSUPPORTED_PLATFORM, $status->code);
        $this->assertSame('Платформа не поддерживается', $status->label);
        $this->assertFalse($status->isSendable);
    }

    protected function createDialog(
        array $channelAttributes = [],
        array $identityAttributes = [],
        array $dialogAttributes = [],
    ): Dialog {
        $contact = Contact::factory()->create();
        $channel = Channel::factory()->create(array_merge([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => ['token' => 'default-token'],
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'is_active' => true,
            'connection_status' => Channel::CONNECTION_STATUS_CONNECTED,
            'webhook_status' => Channel::WEBHOOK_STATUS_INSTALLED,
            'connection_checked_at' => now(),
            'connection_error_message' => null,
            'provider_webhook_url' => null,
            'expected_webhook_url' => null,
        ], $channelAttributes));
        $identity = ContactIdentity::factory()->create(array_merge([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'external-user-100',
        ], $identityAttributes));

        return Dialog::factory()->create(array_merge([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'chat-100',
        ], $dialogAttributes));
    }
}
