<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\ContactIdentity;
use App\Models\Dialog;
use App\Models\Message;
use App\Services\Dialogs\DeleteLastOutboundDialogMessageAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DeleteLastOutboundDialogMessageActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_deletes_last_outbound_telegram_bot_message_and_clears_dialog_link(): void
    {
        Http::fake([
            'https://api.telegram.org/*/deleteMessage' => Http::response(['ok' => true, 'result' => true]),
        ]);

        [$dialog, $message] = $this->dialogWithLastOutbound();

        $result = app(DeleteLastOutboundDialogMessageAction::class)->handle($dialog);

        $dialog->refresh();
        $message->refresh();

        $this->assertSame(DeleteLastOutboundDialogMessageAction::STATUS_DELETED, $result->status);
        $this->assertNull($dialog->last_outbound_message_id);
        $this->assertNull($dialog->last_outbound_message_preview);
        $this->assertSame(DeleteLastOutboundDialogMessageAction::STATUS_DELETED, data_get($message->raw_payload, 'delete_action_result'));
        $this->assertNotEmpty(data_get($message->raw_payload, 'deleted_by_action_at'));

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/deleteMessage'
            && $request['chat_id'] === 'chat-100'
            && $request['message_id'] === '1001');
    }

    public function test_deletes_last_outbound_max_bot_message_and_clears_dialog_link(): void
    {
        Http::fake([
            'https://platform-api.max.ru/messages*' => Http::response(['success' => true]),
        ]);

        [$dialog, $message] = $this->dialogWithLastOutbound([
            'platform' => Channel::PLATFORM_MAX,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'credentials' => ['token' => 'max-token'],
        ], [
            'external_chat_id' => '',
            'raw_payload' => ['provider' => 'max_bot'],
        ]);

        $result = app(DeleteLastOutboundDialogMessageAction::class)->handle($dialog);

        $dialog->refresh();
        $message->refresh();

        $this->assertSame(DeleteLastOutboundDialogMessageAction::STATUS_DELETED, $result->status);
        $this->assertNull($dialog->last_outbound_message_id);
        $this->assertNull($dialog->last_outbound_message_preview);
        $this->assertSame(DeleteLastOutboundDialogMessageAction::STATUS_DELETED, data_get($message->raw_payload, 'delete_action_result'));
        $this->assertNotEmpty(data_get($message->raw_payload, 'deleted_by_action_at'));

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://platform-api.max.ru/messages?message_id=1001'
            && $request->hasHeader('Authorization', 'max-token'));
    }

    public function test_provider_not_found_clears_dialog_link(): void
    {
        Http::fake([
            'https://api.telegram.org/*/deleteMessage' => Http::response([
                'ok' => false,
                'description' => 'Bad Request: message to delete not found',
            ], 400),
        ]);

        [$dialog, $message] = $this->dialogWithLastOutbound();

        $result = app(DeleteLastOutboundDialogMessageAction::class)->handle($dialog);

        $dialog->refresh();
        $message->refresh();

        $this->assertSame(DeleteLastOutboundDialogMessageAction::STATUS_NOT_FOUND, $result->status);
        $this->assertNull($dialog->last_outbound_message_id);
        $this->assertSame(DeleteLastOutboundDialogMessageAction::STATUS_NOT_FOUND, data_get($message->raw_payload, 'delete_action_result'));
        $this->assertNotEmpty(data_get($message->raw_payload, 'deleted_by_action_at'));
    }

    public function test_provider_failed_keeps_dialog_link_for_retry(): void
    {
        Http::fake([
            'https://api.telegram.org/*/deleteMessage' => Http::response([
                'ok' => false,
                'description' => "Bad Request: message can't be deleted",
            ], 400),
        ]);

        [$dialog, $message] = $this->dialogWithLastOutbound();

        $result = app(DeleteLastOutboundDialogMessageAction::class)->handle($dialog);

        $dialog->refresh();
        $message->refresh();

        $this->assertSame(DeleteLastOutboundDialogMessageAction::STATUS_PROVIDER_FAILED, $result->status);
        $this->assertSame($message->id, $dialog->last_outbound_message_id);
        $this->assertSame(DeleteLastOutboundDialogMessageAction::STATUS_PROVIDER_FAILED, data_get($message->raw_payload, 'delete_action_result'));
        $this->assertEmpty(data_get($message->raw_payload, 'deleted_by_action_at'));
    }

    public function test_max_provider_failed_keeps_dialog_link_for_retry(): void
    {
        Http::fake([
            'https://platform-api.max.ru/messages*' => Http::response([
                'success' => false,
                'message' => 'Access denied: message cannot be deleted',
            ], 403),
        ]);

        [$dialog, $message] = $this->dialogWithLastOutbound([
            'platform' => Channel::PLATFORM_MAX,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'credentials' => ['token' => 'max-token'],
        ], [
            'raw_payload' => ['provider' => 'max_bot'],
        ]);

        $result = app(DeleteLastOutboundDialogMessageAction::class)->handle($dialog);

        $dialog->refresh();
        $message->refresh();

        $this->assertSame(DeleteLastOutboundDialogMessageAction::STATUS_PROVIDER_FAILED, $result->status);
        $this->assertSame($message->id, $dialog->last_outbound_message_id);
        $this->assertSame(DeleteLastOutboundDialogMessageAction::STATUS_PROVIDER_FAILED, data_get($message->raw_payload, 'delete_action_result'));
        $this->assertEmpty(data_get($message->raw_payload, 'deleted_by_action_at'));
        $this->assertStringContainsString('Access denied', (string) data_get($message->raw_payload, 'delete_action_error'));
    }

    public function test_unsupported_channel_clears_dialog_link_without_provider_call(): void
    {
        Http::fake();

        [$dialog, $message] = $this->dialogWithLastOutbound([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_ACCOUNT,
            'credentials' => [],
        ]);

        $result = app(DeleteLastOutboundDialogMessageAction::class)->handle($dialog);

        $dialog->refresh();
        $message->refresh();

        $this->assertSame(DeleteLastOutboundDialogMessageAction::STATUS_NOT_SUPPORTED, $result->status);
        $this->assertNull($dialog->last_outbound_message_id);
        $this->assertSame(DeleteLastOutboundDialogMessageAction::STATUS_NOT_SUPPORTED, data_get($message->raw_payload, 'delete_action_result'));
        Http::assertNothingSent();
    }

    public function test_invalid_last_message_clears_dialog_link_without_provider_call(): void
    {
        Http::fake();

        [$dialog, $message] = $this->dialogWithLastOutbound(messageAttributes: [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
        ]);

        $result = app(DeleteLastOutboundDialogMessageAction::class)->handle($dialog);

        $dialog->refresh();

        $this->assertSame(DeleteLastOutboundDialogMessageAction::STATUS_INVALID_LAST_MESSAGE, $result->status);
        $this->assertSame($message->id, $result->messageId);
        $this->assertNull($dialog->last_outbound_message_id);
        Http::assertNothingSent();
    }

    public function test_missing_external_id_clears_dialog_link_without_provider_call(): void
    {
        Http::fake();

        [$dialog, $message] = $this->dialogWithLastOutbound(messageAttributes: [
            'external_message_id' => null,
        ]);

        $result = app(DeleteLastOutboundDialogMessageAction::class)->handle($dialog);

        $dialog->refresh();
        $message->refresh();

        $this->assertSame(DeleteLastOutboundDialogMessageAction::STATUS_MISSING_EXTERNAL_ID, $result->status);
        $this->assertNull($dialog->last_outbound_message_id);
        $this->assertSame(DeleteLastOutboundDialogMessageAction::STATUS_MISSING_EXTERNAL_ID, data_get($message->raw_payload, 'delete_action_result'));
        Http::assertNothingSent();
    }

    public function test_already_deleted_message_clears_dialog_link_without_provider_call(): void
    {
        Http::fake();

        [$dialog, $message] = $this->dialogWithLastOutbound(messageAttributes: [
            'raw_payload' => [
                'deleted_by_action_at' => now()->toJSON(),
                'delete_action_result' => DeleteLastOutboundDialogMessageAction::STATUS_DELETED,
            ],
        ]);

        $result = app(DeleteLastOutboundDialogMessageAction::class)->handle($dialog);

        $dialog->refresh();
        $message->refresh();

        $this->assertSame(DeleteLastOutboundDialogMessageAction::STATUS_ALREADY_DELETED, $result->status);
        $this->assertNull($dialog->last_outbound_message_id);
        $this->assertSame(DeleteLastOutboundDialogMessageAction::STATUS_ALREADY_DELETED, data_get($message->raw_payload, 'delete_action_result'));
        Http::assertNothingSent();
    }

    /**
     * @param  array<string, mixed>  $channelAttributes
     * @param  array<string, mixed>  $messageAttributes
     * @return array{0: Dialog, 1: Message}
     */
    private function dialogWithLastOutbound(array $channelAttributes = [], array $messageAttributes = []): array
    {
        $channel = Channel::factory()->create(array_replace([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'credentials' => ['token' => 'telegram-token'],
        ], $channelAttributes));
        $identity = ContactIdentity::factory()->create([
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'user-100',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $identity->contact_id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'chat-100',
        ]);
        $message = Message::factory()->create(array_replace([
            'dialog_id' => $dialog->id,
            'contact_id' => $identity->contact_id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_SCENARIO_MESSAGE,
            'external_chat_id' => 'chat-100',
            'external_message_id' => '1001',
            'text' => 'Предыдущее сообщение',
            'raw_payload' => ['provider' => 'telegram_bot'],
        ], $messageAttributes));

        $dialog->forceFill([
            'last_outbound_message_id' => $message->id,
            'last_outbound_message_preview' => 'Предыдущее сообщение',
        ])->save();

        return [$dialog, $message];
    }
}
