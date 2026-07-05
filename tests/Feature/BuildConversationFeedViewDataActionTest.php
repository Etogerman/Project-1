<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Dialog;
use App\Models\Message;
use App\Models\User;
use App\Services\Dialogs\BuildConversationFeedViewDataAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BuildConversationFeedViewDataActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_formatted_html_is_sanitized_again_before_rendering(): void
    {
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
            'name' => 'Оператор',
        ]);
        $contact = Contact::factory()->create();
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'render-user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'render-chat',
        ]);
        $message = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'sent_by_user_id' => $employee->id,
            'external_chat_id' => 'render-chat',
            'text' => 'Безопасный текст пример',
            'text_format' => Message::TEXT_FORMAT_HTML,
            'source_text' => '<img src=x onerror="alert(1)"><b>Безопасный текст</b> <a href="javascript:alert(2)">плохая ссылка</a> <a href="https://example.com">пример</a>',
            'received_at' => now(),
        ]);

        $feed = app(BuildConversationFeedViewDataAction::class)->handle(
            Message::query()
                ->whereKey($message->id)
                ->with(['channel', 'dialog.channel', 'sentByUser'])
                ->get(),
        );

        $this->assertCount(1, $feed);
        $this->assertSame('Безопасный текст пример', $feed[0]['display_text']);
        $this->assertSame($message->source_text, $feed[0]['html_source_text']);
        $this->assertStringContainsString('<b>Безопасный текст</b>', $feed[0]['formatted_html']);
        $this->assertStringContainsString('плохая ссылка', $feed[0]['formatted_html']);
        $this->assertStringContainsString('<a href="https://example.com">пример</a>', $feed[0]['formatted_html']);
        $this->assertStringNotContainsString('<img', $feed[0]['formatted_html']);
        $this->assertStringNotContainsString('onerror', $feed[0]['formatted_html']);
        $this->assertStringNotContainsString('<a>плохая ссылка</a>', $feed[0]['formatted_html']);
        $this->assertStringNotContainsString('javascript:', $feed[0]['formatted_html']);
    }

    public function test_media_only_message_uses_media_type_display_text_and_badges(): void
    {
        $contact = Contact::factory()->create();
        $channel = Channel::factory()->account()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'render-media-user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'render-media-chat',
        ]);
        $message = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => 'render-media-chat',
            'external_message_id' => 'render-media-message',
            'text' => null,
            'raw_payload' => [
                'media' => [
                    ['type' => 'photo'],
                ],
            ],
            'received_at' => now(),
        ]);

        $feed = app(BuildConversationFeedViewDataAction::class)->handle(
            Message::query()
                ->whereKey($message->id)
                ->with(['channel', 'dialog.channel', 'sentByUser'])
                ->get(),
        );

        $this->assertCount(1, $feed);
        $this->assertSame('Фото', $feed[0]['display_text']);
        $this->assertTrue($feed[0]['has_media']);
        $this->assertSame(['Фото'], $feed[0]['media_badges']);
        $this->assertSame([
            ['label' => 'Ожидает загрузки', 'tone' => 'gray'],
        ], $feed[0]['media_state_badges']);
    }

    public function test_text_message_with_media_exposes_media_badges_without_overwriting_text(): void
    {
        $contact = Contact::factory()->create();
        $channel = Channel::factory()->account()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'render-mixed-user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'render-mixed-chat',
        ]);
        $message = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => 'render-mixed-chat',
            'external_message_id' => 'render-mixed-message',
            'text' => 'Смотри вложения',
            'raw_payload' => [
                'media' => [
                    ['type' => 'photo'],
                    ['type' => 'document', 'file_name' => 'offer.pdf'],
                ],
            ],
            'received_at' => now(),
        ]);

        $feed = app(BuildConversationFeedViewDataAction::class)->handle(
            Message::query()
                ->whereKey($message->id)
                ->with(['channel', 'dialog.channel', 'sentByUser'])
                ->get(),
        );

        $this->assertCount(1, $feed);
        $this->assertSame('Смотри вложения', $feed[0]['display_text']);
        $this->assertTrue($feed[0]['has_media']);
        $this->assertSame(['Фото', 'Документ: offer.pdf'], $feed[0]['media_badges']);
        $this->assertSame([
            ['label' => 'Ожидает загрузки x2', 'tone' => 'gray'],
        ], $feed[0]['media_state_badges']);
    }

    public function test_media_message_uses_explicit_failed_download_state_badge(): void
    {
        $contact = Contact::factory()->create();
        $channel = Channel::factory()->account()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'render-failed-user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'render-failed-chat',
        ]);
        $message = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => 'render-failed-chat',
            'external_message_id' => 'render-failed-message',
            'text' => null,
            'raw_payload' => [
                'media' => [
                    [
                        'type' => 'document',
                        'file_name' => 'contract.pdf',
                        'download_status' => Message::MEDIA_DOWNLOAD_STATUS_FAILED,
                        'download_error_message' => 'Network timeout',
                    ],
                ],
            ],
            'received_at' => now(),
        ]);

        $feed = app(BuildConversationFeedViewDataAction::class)->handle(
            Message::query()
                ->whereKey($message->id)
                ->with(['channel', 'dialog.channel', 'sentByUser'])
                ->get(),
        );

        $this->assertCount(1, $feed);
        $this->assertSame(['Документ: contract.pdf'], $feed[0]['media_badges']);
        $this->assertSame([
            ['label' => 'Ошибка загрузки', 'tone' => 'danger'],
        ], $feed[0]['media_state_badges']);
    }

    public function test_removed_message_exposes_removal_label_for_conversation_feed(): void
    {
        $contact = Contact::factory()->create();
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'removed-user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'removed-chat',
        ]);
        $message = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => 'removed-chat',
            'external_message_id' => 'removed-message',
            'text' => 'Удалённый текст',
            'received_at' => Carbon::parse('2026-07-05 15:12:00'),
            'removed_at' => Carbon::parse('2026-07-05 15:13:19'),
            'remove_count' => 1,
        ]);

        $feed = app(BuildConversationFeedViewDataAction::class)->handle(
            Message::query()
                ->whereKey($message->id)
                ->with(['channel', 'dialog.channel', 'sentByUser'])
                ->get(),
        );

        $this->assertCount(1, $feed);
        $this->assertTrue($feed[0]['is_removed']);
        $this->assertSame('удалено 15:13', $feed[0]['removed_label']);
        $this->assertNotNull($feed[0]['removed_at_iso']);
    }
}
