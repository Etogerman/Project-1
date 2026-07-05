<?php

namespace Tests\Feature;

use App\Filament\Resources\Contacts\ContactResource;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Dialog;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\MessageRevision;
use App\Models\User;
use App\Services\Dialogs\BuildConversationFeedViewDataAction;
use App\Services\Messages\ResolveMessageMediaItemsAction;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
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

    public function test_rich_text_html_is_rendered_for_conversation_feed(): void
    {
        $contact = Contact::factory()->create();
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'rich-text-user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'rich-text-chat',
        ]);
        $message = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => 'rich-text-chat',
            'text' => '😀 Жирный текст и ссылка',
            'text_format' => Message::TEXT_FORMAT_PLAIN_TEXT,
            'source_text' => null,
            'rich_text' => [
                'version' => 1,
                'plain_text' => '😀 Жирный текст и ссылка',
                'runs' => [
                    ['text' => '😀 ', 'marks' => []],
                    ['text' => 'Жирный текст', 'marks' => [['type' => 'bold']]],
                    ['text' => ' и ссылка', 'marks' => [['type' => 'link', 'href' => 'javascript:alert(1)']]],
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
        $this->assertSame('😀 Жирный текст и ссылка', $feed[0]['display_text']);
        $this->assertFalse($feed[0]['is_html']);
        $this->assertNull($feed[0]['html_source_text']);
        $this->assertSame('😀 <strong>Жирный текст</strong> и ссылка', $feed[0]['formatted_html']);
        $this->assertStringNotContainsString('javascript:', $feed[0]['formatted_html']);
    }

    public function test_edited_message_exposes_edit_label_and_history_for_conversation_feed(): void
    {
        $contact = Contact::factory()->create();
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'edited-user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'edited-chat',
        ]);
        $message = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => 'edited-chat',
            'external_message_id' => 'edited-message',
            'text' => 'Новый текст',
            'received_at' => Carbon::parse('2026-06-30 12:00:00'),
            'edited_at' => Carbon::parse('2026-06-30 12:34:00'),
            'edit_count' => 1,
        ]);
        MessageRevision::query()->create([
            'message_id' => $message->id,
            'revision_type' => MessageRevision::TYPE_EDIT,
            'provider_event_key' => 'edit-1',
            'provider_edited_at' => Carbon::parse('2026-06-30 12:34:00'),
            'previous_text' => 'Старый текст',
            'new_text' => 'Новый текст',
        ]);

        $feed = app(BuildConversationFeedViewDataAction::class)->handle(
            Message::query()
                ->whereKey($message->id)
                ->with(['channel', 'dialog.channel', 'sentByUser'])
                ->get(),
        );

        $this->assertCount(1, $feed);
        $this->assertTrue($feed[0]['is_edited']);
        $this->assertSame('изменено 12:34', $feed[0]['edited_label']);
        $this->assertSame([
            [
                'id' => MessageRevision::query()->firstOrFail()->id,
                'label' => '12:34 30.06.2026',
                'previous_text' => 'Старый текст',
                'new_text' => 'Новый текст',
            ],
        ], $feed[0]['edit_history']);
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
        $this->assertSame('Удалённый текст', $feed[0]['display_text']);
    }

    public function test_invalid_rich_text_falls_back_to_existing_html_path(): void
    {
        $contact = Contact::factory()->create();
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'legacy-html-user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'legacy-html-chat',
        ]);
        $message = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'external_chat_id' => 'legacy-html-chat',
            'text' => 'Legacy HTML',
            'text_format' => Message::TEXT_FORMAT_HTML,
            'source_text' => '<b>Legacy HTML</b>',
            'rich_text' => [
                'version' => 1,
                'plain_text' => 'Legacy HTML',
                'runs' => [
                    ['text' => 'Legacy HTML', 'marks' => ['bold']],
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
        $this->assertSame('<b>Legacy HTML</b>', $feed[0]['formatted_html']);
        $this->assertSame('<b>Legacy HTML</b>', $feed[0]['html_source_text']);
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

    public function test_legacy_media_contract_normalizes_kinds_without_visual_drift(): void
    {
        $contact = Contact::factory()->create();
        $channel = Channel::factory()->account()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'legacy-contract-user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'legacy-contract-chat',
        ]);
        $message = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => 'legacy-contract-chat',
            'external_message_id' => 'legacy-contract-message',
            'text' => null,
            'raw_payload' => [
                'media' => [
                    ['type' => 'photo'],
                    [
                        'type' => 'document',
                        'file_name' => 'offer.pdf',
                        'download_status' => Message::MEDIA_DOWNLOAD_STATUS_PENDING,
                    ],
                ],
            ],
            'received_at' => now(),
        ]);

        $message->load(['channel', 'dialog.channel', 'attachments']);

        $items = app(ResolveMessageMediaItemsAction::class)->handle($message);

        $this->assertSame('legacy_raw_payload', $items[0]['source']);
        $this->assertSame(MessageAttachment::MEDIA_KIND_IMAGE, $items[0]['media_kind']);
        $this->assertSame(MessageAttachment::MEDIA_KIND_IMAGE, $items[0]['type']);
        $this->assertSame('photo', $items[0]['legacy_type']);
        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD, $items[0]['download_status']);
        $this->assertSame(MessageAttachment::MEDIA_KIND_DOCUMENT, $items[1]['media_kind']);
        $this->assertSame('offer.pdf', $items[1]['file_name']);

        $feed = app(BuildConversationFeedViewDataAction::class)->handle(
            Message::query()
                ->whereKey($message->id)
                ->with(['channel', 'dialog.channel', 'sentByUser'])
                ->get(),
        );

        $this->assertSame('Медиа', $feed[0]['display_text']);
        $this->assertSame(['Фото', 'Документ: offer.pdf'], $feed[0]['media_badges']);
        $this->assertSame([
            ['label' => 'Ожидает загрузки x2', 'tone' => 'gray'],
        ], $feed[0]['media_state_badges']);
    }

    public function test_message_attachments_take_precedence_over_legacy_raw_payload_media(): void
    {
        $contact = Contact::factory()->create();
        $channel = Channel::factory()->account()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'attachment-priority-user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'attachment-priority-chat',
        ]);
        $message = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => 'attachment-priority-chat',
            'external_message_id' => 'attachment-priority-message',
            'text' => null,
            'raw_payload' => [
                'media' => [
                    ['type' => 'document', 'file_name' => 'legacy.pdf'],
                ],
            ],
            'received_at' => now(),
        ]);
        MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $channel->id,
            'provider_event_key' => $message->provider_event_key,
            'provider_attachment_key' => 'attachment-photo-1',
            'media_kind' => MessageAttachment::MEDIA_KIND_IMAGE,
            'original_filename' => 'photo.jpg',
            'mime_type' => 'image/jpeg',
            'extension' => 'jpg',
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED,
            'sort_order' => 0,
        ]);

        $items = app(ResolveMessageMediaItemsAction::class)->handle(
            $message->fresh(['channel', 'dialog.channel', 'attachments']),
        );

        $this->assertCount(1, $items);
        $this->assertSame('attachment', $items[0]['source']);
        $this->assertSame(MessageAttachment::MEDIA_KIND_IMAGE, $items[0]['media_kind']);
        $this->assertSame('photo.jpg', $items[0]['file_name']);

        $feed = app(BuildConversationFeedViewDataAction::class)->handle(
            Message::query()
                ->whereKey($message->id)
                ->with(['channel', 'dialog.channel', 'sentByUser'])
                ->get(),
        );

        $this->assertSame('Фото', $feed[0]['display_text']);
        $this->assertSame(['Фото: photo.jpg'], $feed[0]['media_badges']);
        $this->assertSame([], $feed[0]['media_state_badges']);
    }

    public function test_downloaded_message_attachment_exposes_operator_download_view_data(): void
    {
        $contact = Contact::factory()->create();
        $channel = Channel::factory()->account()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'downloadable-attachment-user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'downloadable-attachment-chat',
        ]);
        $message = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => 'downloadable-attachment-chat',
            'external_message_id' => 'downloadable-attachment-message',
            'provider_event_key' => 'telegram_account:'.$channel->id.':downloadable-attachment-chat:downloadable-attachment-message',
            'text' => 'Файл во вложении',
            'received_at' => now(),
        ]);
        $attachment = MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $channel->id,
            'provider_event_key' => $message->provider_event_key,
            'provider_attachment_key' => '0:document:file-1',
            'media_kind' => MessageAttachment::MEDIA_KIND_DOCUMENT,
            'original_filename' => 'offer.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'file_size_bytes' => 2048,
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED,
            'local_disk' => MessageAttachment::LOCAL_DISK_PRIVATE,
            'local_path' => MessageAttachment::LOCAL_PATH_PREFIX.'/'.$message->id.'/downloaded.pdf',
        ]);

        $feed = app(BuildConversationFeedViewDataAction::class)->handle(
            Message::query()
                ->whereKey($message->id)
                ->with(['channel', 'dialog.channel', 'sentByUser'])
                ->get(),
        );

        $this->assertSame('Файл во вложении', $feed[0]['display_text']);
        $this->assertSame([], $feed[0]['media_state_badges']);
        $this->assertSame('Документ', $feed[0]['media_items'][0]['media_kind_label']);
        $this->assertSame('offer.pdf', $feed[0]['media_items'][0]['title']);
        $this->assertSame(['application/pdf', '2 КБ'], $feed[0]['media_items'][0]['meta']);
        $this->assertSame('Готово', $feed[0]['media_items'][0]['status_label']);
        $this->assertTrue($feed[0]['media_items'][0]['is_downloadable']);
        $this->assertFalse($feed[0]['media_items'][0]['is_previewable']);
        $this->assertNull($feed[0]['media_items'][0]['preview_kind']);
        $this->assertNull($feed[0]['media_items'][0]['preview_url']);
        $this->assertSame(
            route('admin.message-attachments.download', ['attachment' => $attachment->id]),
            $feed[0]['media_items'][0]['download_url'],
        );
    }

    public function test_downloaded_image_attachment_exposes_operator_inline_preview_view_data(): void
    {
        $message = Message::factory()->create([
            'provider_event_key' => 'max-bot:image-preview:message-1',
            'text' => null,
        ]);
        $attachment = MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $message->channel_id,
            'provider' => MessageAttachment::PROVIDER_MAX_BOT,
            'provider_event_key' => $message->provider_event_key,
            'provider_attachment_key' => '25852958504',
            'media_kind' => MessageAttachment::MEDIA_KIND_IMAGE,
            'original_filename' => 'photo.jpg',
            'mime_type' => 'image/jpeg',
            'extension' => 'jpg',
            'file_size_bytes' => 4096,
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED,
            'local_disk' => MessageAttachment::LOCAL_DISK_PRIVATE,
            'local_path' => MessageAttachment::LOCAL_PATH_PREFIX.'/'.$message->id.'/photo.jpg',
        ]);

        $feed = app(BuildConversationFeedViewDataAction::class)->handle(
            Message::query()
                ->whereKey($message->id)
                ->with(['channel', 'dialog.channel', 'sentByUser'])
                ->get(),
        );

        $this->assertSame('Фото', $feed[0]['display_text']);
        $this->assertTrue($feed[0]['is_media_only_display_text']);
        $this->assertSame('Фото', $feed[0]['media_items'][0]['media_kind_label']);
        $this->assertSame('photo.jpg', $feed[0]['media_items'][0]['title']);
        $this->assertSame('Готово', $feed[0]['media_items'][0]['status_label']);
        $this->assertFalse($feed[0]['media_items'][0]['show_status']);
        $this->assertTrue($feed[0]['media_items'][0]['is_downloadable']);
        $this->assertTrue($feed[0]['media_items'][0]['is_previewable']);
        $this->assertSame(MessageAttachment::PREVIEW_KIND_IMAGE, $feed[0]['media_items'][0]['preview_kind']);
        $this->assertSame(
            route('admin.message-attachments.preview', ['attachment' => $attachment->id]),
            $feed[0]['media_items'][0]['preview_url'],
        );
        $this->assertSame(
            route('admin.message-attachments.download', ['attachment' => $attachment->id]),
            $feed[0]['media_items'][0]['download_url'],
        );
    }

    public function test_downloaded_browser_playable_video_attachment_exposes_operator_preview_view_data(): void
    {
        $message = Message::factory()->create([
            'provider_event_key' => 'telegram-account:video-preview:message-1',
            'text' => 'Видео клиента',
        ]);
        $attachment = MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $message->channel_id,
            'provider' => MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT,
            'provider_event_key' => $message->provider_event_key,
            'provider_attachment_key' => '0:video:file-1',
            'media_kind' => MessageAttachment::MEDIA_KIND_VIDEO,
            'original_filename' => 'clip.mp4',
            'mime_type' => 'video/mp4',
            'extension' => 'mp4',
            'file_size_bytes' => 4096,
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED,
            'local_disk' => MessageAttachment::LOCAL_DISK_PRIVATE,
            'local_path' => MessageAttachment::LOCAL_PATH_PREFIX.'/'.$message->id.'/clip.mp4',
        ]);

        $feed = app(BuildConversationFeedViewDataAction::class)->handle(
            Message::query()
                ->whereKey($message->id)
                ->with(['channel', 'dialog.channel', 'sentByUser'])
                ->get(),
        );

        $this->assertSame('Видео клиента', $feed[0]['display_text']);
        $this->assertSame('Видео', $feed[0]['media_items'][0]['media_kind_label']);
        $this->assertSame('clip.mp4', $feed[0]['media_items'][0]['title']);
        $this->assertSame(['video/mp4', '4 КБ'], $feed[0]['media_items'][0]['meta']);
        $this->assertSame('Готово', $feed[0]['media_items'][0]['status_label']);
        $this->assertTrue($feed[0]['media_items'][0]['is_downloadable']);
        $this->assertTrue($feed[0]['media_items'][0]['is_previewable']);
        $this->assertSame(MessageAttachment::PREVIEW_KIND_VIDEO, $feed[0]['media_items'][0]['preview_kind']);
        $this->assertSame(
            route('admin.message-attachments.preview', ['attachment' => $attachment->id]),
            $feed[0]['media_items'][0]['preview_url'],
        );
        $this->assertSame(
            route('admin.message-attachments.download', ['attachment' => $attachment->id]),
            $feed[0]['media_items'][0]['download_url'],
        );
    }

    public function test_downloaded_video_note_attachment_exposes_inline_round_video_view_data(): void
    {
        $message = Message::factory()->create([
            'provider_event_key' => 'telegram-bot:video-note-preview:message-1',
            'text' => null,
        ]);
        $attachment = MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $message->channel_id,
            'provider' => MessageAttachment::PROVIDER_TELEGRAM_BOT,
            'provider_event_key' => $message->provider_event_key,
            'provider_attachment_key' => 'video-note:file-1',
            'media_kind' => MessageAttachment::MEDIA_KIND_VIDEO_NOTE,
            'original_filename' => 'round.mp4',
            'mime_type' => 'video/mp4',
            'extension' => 'mp4',
            'file_size_bytes' => 4096,
            'provider_metadata' => [
                'duration' => 21,
                'is_video_note' => true,
            ],
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED,
            'local_disk' => MessageAttachment::LOCAL_DISK_PRIVATE,
            'local_path' => MessageAttachment::LOCAL_PATH_PREFIX.'/'.$message->id.'/round.mp4',
        ]);

        $feed = app(BuildConversationFeedViewDataAction::class)->handle(
            Message::query()
                ->whereKey($message->id)
                ->with(['channel', 'dialog.channel', 'sentByUser'])
                ->get(),
        );

        $this->assertSame('Кружок', $feed[0]['display_text']);
        $this->assertTrue($feed[0]['is_media_only_display_text']);
        $this->assertSame('Кружок', $feed[0]['media_items'][0]['media_kind_label']);
        $this->assertSame('round.mp4', $feed[0]['media_items'][0]['title']);
        $this->assertSame('0:21', $feed[0]['media_items'][0]['duration_label']);
        $this->assertSame(['video/mp4', '0:21', '4 КБ'], $feed[0]['media_items'][0]['meta']);
        $this->assertTrue($feed[0]['media_items'][0]['is_video_note']);
        $this->assertTrue($feed[0]['media_items'][0]['is_downloadable']);
        $this->assertTrue($feed[0]['media_items'][0]['is_previewable']);
        $this->assertSame(MessageAttachment::PREVIEW_KIND_VIDEO, $feed[0]['media_items'][0]['preview_kind']);
        $this->assertSame(
            route('admin.message-attachments.preview', ['attachment' => $attachment->id]),
            $feed[0]['media_items'][0]['preview_url'],
        );
        $this->assertSame(
            route('admin.message-attachments.download', ['attachment' => $attachment->id]),
            $feed[0]['media_items'][0]['download_url'],
        );
    }

    public function test_max_forwarded_video_note_exposes_safe_forwarded_label(): void
    {
        $contact = Contact::factory()->create();
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'max-forward-user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'max-forward-chat',
        ]);
        $message = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => 'max-forward-chat',
            'external_message_id' => 'max-forward-message',
            'provider_event_key' => 'max-forward-message',
            'text' => null,
            'raw_payload' => [
                'message' => [
                    'link' => [
                        'type' => 'forward',
                        'sender' => [
                            'name' => 'Tanya',
                            'user_id' => '60850565',
                        ],
                        'message' => [
                            'mid' => 'mid.forward.original',
                            'attachments' => [
                                [
                                    'type' => 'video',
                                    'payload' => [
                                        'id' => 'max-video-42',
                                        'url' => 'https://max.example.test/private-video?token=secret-token',
                                        'token' => 'secret-token',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'received_at' => now(),
        ]);

        $attachment = MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $channel->id,
            'provider' => MessageAttachment::PROVIDER_MAX_BOT,
            'provider_event_key' => $message->provider_event_key,
            'provider_attachment_key' => 'max-video-42',
            'media_kind' => MessageAttachment::MEDIA_KIND_VIDEO_NOTE,
            'original_filename' => 'max-video-42.mp4',
            'mime_type' => 'video/mp4',
            'extension' => 'mp4',
            'file_size_bytes' => 3305961,
            'provider_file_reference' => 'max-video-42',
            'provider_metadata' => [
                'duration' => 23,
                'is_video_note' => true,
            ],
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED,
            'local_disk' => MessageAttachment::LOCAL_DISK_PRIVATE,
            'local_path' => MessageAttachment::LOCAL_PATH_PREFIX.'/'.$message->id.'/max-video-42.mp4',
        ]);

        $feed = app(BuildConversationFeedViewDataAction::class)->handle(
            Message::query()
                ->whereKey($message->id)
                ->with(['channel', 'dialog.channel', 'sentByUser'])
                ->get(),
        );
        $encodedFeed = json_encode($feed, JSON_THROW_ON_ERROR);

        $this->assertSame('Переслано от Tanya', $feed[0]['forwarded_label']);
        $this->assertSame('60850565', $feed[0]['forwarded_context']['sender_user_id']);
        $this->assertFalse($feed[0]['forwarded_context']['contact_found']);
        $this->assertSame('не найден', $feed[0]['forwarded_context']['contact_label']);
        $this->assertSame('mid.forward.original', $feed[0]['forwarded_context']['original_message_id']);
        $this->assertSame([
            [
                'label' => 'MAX user_id',
                'value' => '60850565',
            ],
            [
                'label' => 'AB контакт',
                'value' => 'не найден',
                'tone' => 'warning',
            ],
            [
                'label' => 'Оригинал',
                'value' => 'mid.forward.original',
            ],
        ], $feed[0]['forwarded_context']['details']);
        $this->assertSame('Кружок', $feed[0]['display_text']);
        $this->assertTrue($feed[0]['is_media_only_display_text']);
        $this->assertSame('Кружок', $feed[0]['media_items'][0]['media_kind_label']);
        $this->assertSame('0:23', $feed[0]['media_items'][0]['duration_label']);
        $this->assertSame(
            route('admin.message-attachments.preview', ['attachment' => $attachment->id]),
            $feed[0]['media_items'][0]['preview_url'],
        );
        $this->assertStringNotContainsString('secret-token', $encodedFeed);
        $this->assertStringNotContainsString('max.example.test', $encodedFeed);
    }

    public function test_max_forwarded_context_marks_existing_ab_contact(): void
    {
        $contact = Contact::factory()->create([
            'name' => 'Tanya Local',
        ]);
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '60850565',
            'display_name' => 'Tanya',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'max-forward-existing-chat',
        ]);
        $message = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => 'max-forward-existing-chat',
            'external_message_id' => 'max-forward-existing-message',
            'provider_event_key' => 'max-forward-existing-message',
            'text' => 'Пересланный текст',
            'raw_payload' => [
                'message' => [
                    'link' => [
                        'type' => 'forward',
                        'sender' => [
                            'name' => 'Tanya',
                            'user_id' => '60850565',
                        ],
                        'message' => [
                            'mid' => 'mid.forward.found',
                        ],
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

        $this->assertSame('Переслано от Tanya', $feed[0]['forwarded_label']);
        $this->assertTrue($feed[0]['forwarded_context']['contact_found']);
        $this->assertSame($contact->id, $feed[0]['forwarded_context']['contact_id']);
        $this->assertSame('#'.$contact->id.' Tanya Local', $feed[0]['forwarded_context']['contact_label']);
        $this->assertSame(
            ContactResource::getUrl('view', ['record' => $contact->id]),
            $feed[0]['forwarded_context']['contact_url'],
        );
        $this->assertSame([
            [
                'label' => 'MAX user_id',
                'value' => '60850565',
            ],
            [
                'label' => 'AB контакт',
                'value' => '#'.$contact->id.' Tanya Local',
                'tone' => 'success',
            ],
            [
                'label' => 'Оригинал',
                'value' => 'mid.forward.found',
            ],
        ], $feed[0]['forwarded_context']['details']);
    }

    public function test_max_reply_context_uses_link_message_without_replacing_own_text(): void
    {
        $contact = Contact::factory()->create();
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'max-reply-user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'max-reply-chat',
        ]);
        $sourceMessage = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => 'max-reply-chat',
            'external_message_id' => 'mid.reply.source',
            'provider_event_key' => 'mid.reply.source',
            'text' => 'TQ-01 обычный текст без форматирования',
            'received_at' => now()->subMinute(),
        ]);
        $replyMessage = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => 'max-reply-chat',
            'external_message_id' => 'mid.reply.current',
            'provider_event_key' => 'mid.reply.current',
            'text' => 'TQ-03 это reply на TQ-01',
            'raw_payload' => [
                'message' => [
                    'body' => [
                        'mid' => 'mid.reply.current',
                        'text' => 'TQ-03 это reply на TQ-01',
                    ],
                    'link' => [
                        'type' => 'reply',
                        'message' => [
                            'mid' => 'mid.reply.source',
                            'text' => 'TQ-01 обычный текст без форматирования',
                        ],
                    ],
                ],
            ],
            'received_at' => now(),
        ]);

        $feed = app(BuildConversationFeedViewDataAction::class)->handle(
            Message::query()
                ->whereKey($replyMessage->id)
                ->with(['channel', 'dialog.channel', 'sentByUser'])
                ->get(),
        );

        $this->assertSame('TQ-03 это reply на TQ-01', $feed[0]['display_text']);
        $this->assertNull($feed[0]['forwarded_label']);
        $this->assertNull($feed[0]['forwarded_context']);
        $this->assertSame([
            'label' => 'Ответ на сообщение',
            'original_message_id' => 'mid.reply.source',
            'local_message_id' => $sourceMessage->id,
            'has_local_message' => true,
            'preview_text' => 'TQ-01 обычный текст без форматирования',
        ], $feed[0]['reply_context']);
    }

    public function test_outbound_max_button_context_uses_sent_keyboard_payload(): void
    {
        $contact = Contact::factory()->create();
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'max-button-user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'max-button-chat',
        ]);
        $message = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_SCENARIO_MESSAGE,
            'sent_by_type' => Message::SENT_BY_TYPE_SYSTEM,
            'sent_by_system_code' => 'scenario___scenario_constructor_workspace',
            'external_chat_id' => 'max-button-chat',
            'external_message_id' => 'mid.max.button.current',
            'text' => 'QA MAX BTN-01: нажми кнопку ниже',
            'raw_payload' => [
                'v3' => [
                    'buttons' => [
                        'rows' => [
                            [
                                [
                                    'text' => 'Fallback button',
                                    'type' => 'text',
                                ],
                            ],
                        ],
                    ],
                ],
                'message' => [
                    'body' => [
                        'attachments' => [
                            [
                                'type' => 'inline_keyboard',
                                'payload' => [
                                    'buttons' => [
                                        [
                                            [
                                                'text' => 'Поделиться номером телефона',
                                                'type' => 'request_contact',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
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

        $this->assertSame([
            'label' => 'Отправленные кнопки',
            'rows' => [
                [
                    [
                        'text' => 'Поделиться номером телефона',
                        'type' => 'request_contact',
                        'type_label' => 'Запрос телефона',
                        'url' => null,
                    ],
                ],
            ],
        ], $feed[0]['button_context']);
    }

    public function test_telegram_forwarded_message_exposes_safe_forwarded_label_and_sender_id(): void
    {
        $contact = Contact::factory()->create();
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '200',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'telegram-forward-chat',
        ]);
        $message = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => 'telegram-forward-chat',
            'external_message_id' => 'telegram-forward-message',
            'provider_event_key' => 'telegram-forward-update',
            'text' => 'Groups are currently disabled for bot',
            'raw_payload' => [
                'message' => [
                    'message_id' => 31,
                    'text' => 'Groups are currently disabled for bot',
                    'forward_origin' => [
                        'type' => 'user',
                        'sender_user' => [
                            'id' => 93372553,
                            'is_bot' => true,
                            'first_name' => 'BotFather',
                            'username' => 'BotFather',
                        ],
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

        $this->assertSame('Groups are currently disabled for bot', $feed[0]['display_text']);
        $this->assertSame('Переслано от BotFather', $feed[0]['forwarded_label']);
        $this->assertSame('93372553', $feed[0]['forwarded_context']['sender_user_id']);
        $this->assertSame([
            [
                'label' => 'Telegram user_id',
                'value' => '93372553',
            ],
            [
                'label' => 'AB контакт',
                'value' => 'не найден',
                'tone' => 'warning',
            ],
        ], $feed[0]['forwarded_context']['details']);
    }

    public function test_telegram_account_tdlib_forward_info_exposes_forwarded_context(): void
    {
        $contact = Contact::factory()->create();
        $channel = Channel::factory()->account()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '200',
        ]);
        $forwardedContact = Contact::factory()->create([
            'name' => 'BotFather local',
        ]);
        ContactIdentity::factory()->create([
            'contact_id' => $forwardedContact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '93372553',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'telegram-account-forward-chat',
        ]);
        $message = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => 'telegram-account-forward-chat',
            'external_message_id' => 'telegram-account-forward-message',
            'provider_event_key' => 'telegram-account-forward-event',
            'text' => 'Groups are currently disabled for bot',
            'raw_payload' => [
                'tdlib_message_type' => 'messageText',
                'forward_info' => [
                    'origin' => [
                        '_' => 'messageOriginUser',
                        'sender_user_id' => 93372553,
                    ],
                    'from_message_id' => 77,
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

        $this->assertSame('Groups are currently disabled for bot', $feed[0]['display_text']);
        $this->assertSame('Переслано от Telegram user_id 93372553', $feed[0]['forwarded_label']);
        $this->assertSame('93372553', $feed[0]['forwarded_context']['sender_user_id']);
        $this->assertTrue($feed[0]['forwarded_context']['contact_found']);
        $this->assertSame($forwardedContact->id, $feed[0]['forwarded_context']['contact_id']);
        $this->assertSame(
            ContactResource::getUrl('view', ['record' => $forwardedContact->id]),
            $feed[0]['forwarded_context']['contact_url'],
        );
        $this->assertSame('77', $feed[0]['forwarded_context']['original_message_id']);
        $this->assertSame([
            [
                'label' => 'Telegram user_id',
                'value' => '93372553',
            ],
            [
                'label' => 'AB контакт',
                'value' => '#'.$forwardedContact->id.' BotFather local',
                'tone' => 'success',
            ],
            [
                'label' => 'Оригинал',
                'value' => '77',
            ],
        ], $feed[0]['forwarded_context']['details']);
    }

    public function test_max_forwarded_message_text_from_link_is_displayed_when_message_text_is_empty(): void
    {
        $contact = Contact::factory()->create();
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'max-forward-link-text-user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'max-forward-link-text-chat',
        ]);
        $message = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => 'max-forward-link-text-chat',
            'external_message_id' => 'max-forward-link-text-message',
            'provider_event_key' => 'max-forward-link-text-message',
            'text' => null,
            'raw_payload' => [
                'message' => [
                    'link' => [
                        'type' => 'forward',
                        'sender' => [
                            'name' => 'Tanya',
                            'user_id' => '60850565',
                        ],
                        'message' => [
                            'mid' => 'mid.forward.link-text',
                            'text' => 'Текст из пересланного MAX-сообщения',
                        ],
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

        $this->assertSame('Переслано от Tanya', $feed[0]['forwarded_label']);
        $this->assertSame('Текст из пересланного MAX-сообщения', $feed[0]['display_text']);
        $this->assertNotSame('Системное сообщение', $feed[0]['display_text']);
        $this->assertFalse($feed[0]['is_media_only_display_text']);
    }

    public function test_max_forwarded_message_without_available_content_does_not_look_like_system_message(): void
    {
        $contact = Contact::factory()->create();
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'max-forward-empty-user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'max-forward-empty-chat',
        ]);
        $message = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => 'max-forward-empty-chat',
            'external_message_id' => 'max-forward-empty-message',
            'provider_event_key' => 'max-forward-empty-message',
            'text' => null,
            'raw_payload' => [
                'message' => [
                    'link' => [
                        'type' => 'forward',
                        'sender' => [
                            'name' => 'Tanya',
                            'user_id' => '60850565',
                        ],
                        'message' => [
                            'mid' => 'mid.forward.empty',
                        ],
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

        $this->assertSame('Переслано от Tanya', $feed[0]['forwarded_label']);
        $this->assertSame('60850565', $feed[0]['forwarded_context']['sender_user_id']);
        $this->assertSame('Пересланное сообщение без доступного содержимого', $feed[0]['display_text']);
        $this->assertNotSame('Системное сообщение', $feed[0]['display_text']);
        $this->assertFalse($feed[0]['is_media_only_display_text']);
        $this->assertSame([], $feed[0]['media_items']);
    }

    public function test_max_contact_share_without_phone_renders_contact_context_not_phone_text(): void
    {
        $contact = Contact::factory()->create();
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'max-contact-share-sender',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'max-contact-share-chat',
        ]);
        $message = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_CONTACT_SHARE,
            'external_chat_id' => 'max-contact-share-chat',
            'external_message_id' => 'max-contact-share-message',
            'provider_event_key' => 'max-contact-share-message',
            'text' => null,
            'raw_payload' => [
                'message' => [
                    'body' => [
                        'attachments' => [
                            [
                                'type' => 'contact',
                                'payload' => [
                                    'max_info' => [
                                        'name' => 'Александр Бабичев',
                                        'first_name' => 'Александр',
                                        'last_name' => 'Бабичев',
                                        'user_id' => 106381897,
                                    ],
                                ],
                            ],
                        ],
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

        $this->assertSame('Поделился контактом', $feed[0]['display_text']);
        $this->assertSame('Поделился контактом', $feed[0]['contact_share_context']['label']);
        $this->assertSame('Александр Бабичев', $feed[0]['contact_share_context']['name']);
        $this->assertNull($feed[0]['contact_share_context']['phone_number']);
        $this->assertSame('106381897', $feed[0]['contact_share_context']['shared_contact_user_id']);
        $this->assertFalse($feed[0]['contact_share_context']['contact_found']);
        $this->assertSame('не найден', $feed[0]['contact_share_context']['contact_label']);
        $this->assertSame([
            [
                'label' => 'Имя',
                'value' => 'Александр Бабичев',
            ],
            [
                'label' => 'MAX user_id',
                'value' => '106381897',
            ],
            [
                'label' => 'AB контакт',
                'value' => 'не найден',
                'tone' => 'warning',
            ],
        ], $feed[0]['contact_share_context']['details']);
    }

    public function test_contact_share_with_phone_keeps_phone_display_and_context(): void
    {
        $contact = Contact::factory()->create();
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'max-contact-share-phone-sender',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'max-contact-share-phone-chat',
        ]);
        $sharedContact = Contact::factory()->create([
            'name' => 'Александр Бабичев',
        ]);
        ContactIdentity::factory()->create([
            'contact_id' => $sharedContact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '106381897',
        ]);
        $message = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_CONTACT_SHARE,
            'external_chat_id' => 'max-contact-share-phone-chat',
            'external_message_id' => 'max-contact-share-phone-message',
            'provider_event_key' => 'max-contact-share-phone-message',
            'text' => null,
            'raw_payload' => [
                'message' => [
                    'body' => [
                        'attachments' => [
                            [
                                'type' => 'contact',
                                'payload' => [
                                    'phone_number' => '+7 999 123 45 67',
                                    'max_info' => [
                                        'name' => 'Александр Бабичев',
                                        'user_id' => '106381897',
                                    ],
                                ],
                            ],
                        ],
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

        $this->assertSame('Поделился номером: +7 999 123 45 67', $feed[0]['display_text']);
        $this->assertSame('Поделился номером', $feed[0]['contact_share_context']['label']);
        $this->assertSame('+7 999 123 45 67', $feed[0]['contact_share_context']['phone_number']);
        $this->assertTrue($feed[0]['contact_share_context']['contact_found']);
        $this->assertSame($sharedContact->id, $feed[0]['contact_share_context']['contact_id']);
        $this->assertSame('#'.$sharedContact->id.' Александр Бабичев', $feed[0]['contact_share_context']['contact_label']);
    }

    public function test_downloaded_browser_playable_voice_attachment_exposes_operator_preview_view_data(): void
    {
        $message = Message::factory()->create([
            'provider_event_key' => 'telegram-account:voice-preview:message-1',
            'text' => 'Голосовое клиента',
        ]);
        $attachment = MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $message->channel_id,
            'provider' => MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT,
            'provider_event_key' => $message->provider_event_key,
            'provider_attachment_key' => '0:voice:file-1',
            'media_kind' => MessageAttachment::MEDIA_KIND_VOICE,
            'original_filename' => 'voice.mp3',
            'mime_type' => 'audio/mpeg',
            'extension' => 'mp3',
            'file_size_bytes' => 2048,
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED,
            'local_disk' => MessageAttachment::LOCAL_DISK_PRIVATE,
            'local_path' => MessageAttachment::LOCAL_PATH_PREFIX.'/'.$message->id.'/voice.mp3',
        ]);

        $feed = app(BuildConversationFeedViewDataAction::class)->handle(
            Message::query()
                ->whereKey($message->id)
                ->with(['channel', 'dialog.channel', 'sentByUser'])
                ->get(),
        );

        $this->assertSame('Голосовое клиента', $feed[0]['display_text']);
        $this->assertSame('Голосовое', $feed[0]['media_items'][0]['media_kind_label']);
        $this->assertSame('voice.mp3', $feed[0]['media_items'][0]['title']);
        $this->assertSame(['audio/mpeg', '2 КБ'], $feed[0]['media_items'][0]['meta']);
        $this->assertSame('Готово', $feed[0]['media_items'][0]['status_label']);
        $this->assertTrue($feed[0]['media_items'][0]['is_downloadable']);
        $this->assertTrue($feed[0]['media_items'][0]['is_previewable']);
        $this->assertSame(MessageAttachment::PREVIEW_KIND_AUDIO, $feed[0]['media_items'][0]['preview_kind']);
        $this->assertSame(
            route('admin.message-attachments.preview', ['attachment' => $attachment->id]),
            $feed[0]['media_items'][0]['preview_url'],
        );
        $this->assertSame(
            route('admin.message-attachments.download', ['attachment' => $attachment->id]),
            $feed[0]['media_items'][0]['download_url'],
        );
    }

    public function test_downloaded_image_attachment_with_generic_mime_uses_local_signature_for_preview(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);

        $message = Message::factory()->create([
            'provider_event_key' => 'telegram-account:image-preview:message-1',
            'text' => 'Подпись под фото',
        ]);
        $path = MessageAttachment::LOCAL_PATH_PREFIX.'/'.$message->id.'/13.bin';

        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->put($path, "\xFF\xD8\xFF\xE0".str_repeat("\0", 16));

        $attachment = MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $message->channel_id,
            'provider' => MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT,
            'provider_event_key' => $message->provider_event_key,
            'provider_attachment_key' => '0:image:tdlib-photo-42',
            'media_kind' => MessageAttachment::MEDIA_KIND_IMAGE,
            'original_filename' => 'attachment-13',
            'mime_type' => 'application/octet-stream',
            'extension' => null,
            'file_size_bytes' => 20,
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED,
            'local_disk' => MessageAttachment::LOCAL_DISK_PRIVATE,
            'local_path' => $path,
        ]);

        $feed = app(BuildConversationFeedViewDataAction::class)->handle(
            Message::query()
                ->whereKey($message->id)
                ->with(['channel', 'dialog.channel', 'sentByUser'])
                ->get(),
        );

        $this->assertSame('Подпись под фото', $feed[0]['display_text']);
        $this->assertFalse($feed[0]['is_media_only_display_text']);
        $this->assertTrue($feed[0]['media_items'][0]['is_downloadable']);
        $this->assertTrue($feed[0]['media_items'][0]['is_previewable']);
        $this->assertSame(MessageAttachment::PREVIEW_KIND_IMAGE, $feed[0]['media_items'][0]['preview_kind']);
        $this->assertSame('application/octet-stream', $feed[0]['media_items'][0]['mime_type']);
        $this->assertSame(
            route('admin.message-attachments.preview', ['attachment' => $attachment->id]),
            $feed[0]['media_items'][0]['preview_url'],
        );
    }

    public function test_max_sticker_metadata_only_attachment_renders_without_fake_preview(): void
    {
        $contact = Contact::factory()->create();
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'max-sticker-user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'max-sticker-chat',
        ]);
        $message = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => 'max-sticker-chat',
            'external_message_id' => 'max-sticker-message',
            'provider_event_key' => 'max-sticker-message',
            'text' => null,
            'received_at' => now(),
        ]);

        MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $channel->id,
            'provider' => MessageAttachment::PROVIDER_MAX_BOT,
            'provider_event_key' => $message->provider_event_key,
            'provider_attachment_key' => '429b5',
            'media_kind' => MessageAttachment::MEDIA_KIND_STICKER,
            'original_filename' => null,
            'mime_type' => null,
            'extension' => null,
            'file_size_bytes' => null,
            'provider_file_reference' => '429b5',
            'provider_metadata' => [
                'width' => 144,
                'height' => 144,
            ],
            'raw_payload_excerpt' => [
                'type' => 'sticker',
                'media_kind' => MessageAttachment::MEDIA_KIND_STICKER,
                'sticker_code' => '429b5',
                'width' => 144,
                'height' => 144,
            ],
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_METADATA_ONLY,
            'local_disk' => null,
            'local_path' => null,
        ]);

        $feed = app(BuildConversationFeedViewDataAction::class)->handle(
            Message::query()
                ->whereKey($message->id)
                ->with(['channel', 'dialog.channel', 'sentByUser'])
                ->get(),
        );

        $this->assertSame('Стикер', $feed[0]['display_text']);
        $this->assertTrue($feed[0]['is_media_only_display_text']);
        $this->assertSame('Стикер', $feed[0]['media_items'][0]['media_kind_label']);
        $this->assertSame('Стикер', $feed[0]['media_items'][0]['title']);
        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_METADATA_ONLY, $feed[0]['media_items'][0]['status']);
        $this->assertFalse($feed[0]['media_items'][0]['is_downloadable']);
        $this->assertFalse($feed[0]['media_items'][0]['is_previewable']);
        $this->assertNull($feed[0]['media_items'][0]['preview_kind']);
        $this->assertNull($feed[0]['media_items'][0]['preview_url']);
        $this->assertNull($feed[0]['media_items'][0]['download_url']);
    }

    public function test_downloaded_sticker_attachment_renders_as_previewable_image_without_technical_filename_title(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);

        $contact = Contact::factory()->create();
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'max-sticker-user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'max-sticker-chat',
        ]);
        $message = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => 'max-sticker-chat',
            'external_message_id' => 'max-sticker-message',
            'provider_event_key' => 'max-sticker-message',
            'text' => null,
            'received_at' => now(),
        ]);
        $path = MessageAttachment::LOCAL_PATH_PREFIX.'/'.$message->id.'/sticker.png';

        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->put($path, "\x89PNG\r\n\x1A\n".str_repeat("\0", 16));

        $attachment = MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $channel->id,
            'provider' => MessageAttachment::PROVIDER_MAX_BOT,
            'provider_event_key' => $message->provider_event_key,
            'provider_attachment_key' => '429b5',
            'media_kind' => MessageAttachment::MEDIA_KIND_STICKER,
            'original_filename' => 'getSmile',
            'mime_type' => 'image/png',
            'extension' => 'png',
            'file_size_bytes' => 24,
            'provider_file_reference' => '429b5',
            'provider_metadata' => [
                'width' => 170,
                'height' => 170,
            ],
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED,
            'local_disk' => MessageAttachment::LOCAL_DISK_PRIVATE,
            'local_path' => $path,
        ]);

        $feed = app(BuildConversationFeedViewDataAction::class)->handle(
            Message::query()
                ->whereKey($message->id)
                ->with(['channel', 'dialog.channel', 'sentByUser'])
                ->get(),
        );

        $this->assertSame('Стикер', $feed[0]['display_text']);
        $this->assertTrue($feed[0]['is_media_only_display_text']);
        $this->assertSame('Стикер', $feed[0]['media_items'][0]['media_kind_label']);
        $this->assertSame('Стикер', $feed[0]['media_items'][0]['title']);
        $this->assertSame('getSmile', $feed[0]['media_items'][0]['file_name']);
        $this->assertTrue($feed[0]['media_items'][0]['is_downloadable']);
        $this->assertTrue($feed[0]['media_items'][0]['is_previewable']);
        $this->assertSame(MessageAttachment::PREVIEW_KIND_IMAGE, $feed[0]['media_items'][0]['preview_kind']);
        $this->assertSame(
            route('admin.message-attachments.preview', ['attachment' => $attachment->id]),
            $feed[0]['media_items'][0]['preview_url'],
        );
        $this->assertSame(
            route('admin.message-attachments.download', ['attachment' => $attachment->id]),
            $feed[0]['media_items'][0]['download_url'],
        );
    }

    public function test_bot_metadata_only_message_attachment_exposes_operator_media_item_without_download(): void
    {
        $contact = Contact::factory()->create();
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'max-bot-media-user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'max-bot-media-chat',
        ]);
        $message = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => 'max-bot-media-chat',
            'external_message_id' => 'max-bot-media-message',
            'provider_event_key' => 'max-bot-media-message',
            'text' => null,
            'received_at' => now(),
        ]);

        MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $channel->id,
            'provider' => MessageAttachment::PROVIDER_MAX_BOT,
            'provider_event_key' => $message->provider_event_key,
            'provider_attachment_key' => '25852958504',
            'media_kind' => MessageAttachment::MEDIA_KIND_IMAGE,
            'original_filename' => null,
            'mime_type' => null,
            'extension' => null,
            'file_size_bytes' => null,
            'provider_file_reference' => '25852958504',
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_METADATA_ONLY,
            'local_disk' => null,
            'local_path' => null,
        ]);

        $feed = app(BuildConversationFeedViewDataAction::class)->handle(
            Message::query()
                ->whereKey($message->id)
                ->with(['channel', 'dialog.channel', 'sentByUser'])
                ->get(),
        );

        $this->assertSame('Фото', $feed[0]['display_text']);
        $this->assertSame([
            ['label' => 'Только метаданные', 'tone' => 'gray'],
        ], $feed[0]['media_state_badges']);
        $this->assertSame('Фото', $feed[0]['media_items'][0]['media_kind_label']);
        $this->assertSame('Фото', $feed[0]['media_items'][0]['title']);
        $this->assertSame('Только метаданные', $feed[0]['media_items'][0]['status_label']);
        $this->assertFalse($feed[0]['media_items'][0]['is_downloadable']);
        $this->assertFalse($feed[0]['media_items'][0]['is_previewable']);
        $this->assertNull($feed[0]['media_items'][0]['preview_url']);
        $this->assertNull($feed[0]['media_items'][0]['download_url']);
    }

    public function test_provider_group_key_messages_render_as_single_conversation_item(): void
    {
        $contact = Contact::factory()->create();
        $channel = Channel::factory()->account()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'album-user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'album-chat',
        ]);
        $baseTime = now()->subMinutes(10);

        $messages = collect([0, 1, 2])
            ->map(function (int $index) use ($baseTime, $channel, $contact, $dialog, $identity): Message {
                $message = Message::factory()->create([
                    'dialog_id' => $dialog->id,
                    'contact_id' => $contact->id,
                    'contact_identity_id' => $identity->id,
                    'channel_id' => $channel->id,
                    'direction' => Message::DIRECTION_INBOUND,
                    'message_kind' => Message::KIND_INBOUND_USER,
                    'external_chat_id' => 'album-chat',
                    'external_message_id' => 'album-message-'.($index + 1),
                    'provider_event_key' => 'telegram-account:album-chat:album-message-'.($index + 1),
                    'provider_group_key' => 'tdlib-album-61',
                    'text' => $index === 1 ? 'Подпись альбома' : null,
                    'rich_text' => $index === 1 ? [
                        'version' => 1,
                        'plain_text' => 'Подпись альбома',
                        'runs' => [
                            ['text' => 'Подпись', 'marks' => [['type' => 'bold']]],
                            ['text' => ' альбома', 'marks' => []],
                        ],
                    ] : null,
                    'received_at' => $baseTime->copy()->addSeconds($index),
                ]);

                MessageAttachment::factory()->create([
                    'message_id' => $message->id,
                    'channel_id' => $channel->id,
                    'provider' => MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT,
                    'provider_event_key' => $message->provider_event_key,
                    'provider_attachment_key' => $index.':image:file-'.$index,
                    'media_kind' => MessageAttachment::MEDIA_KIND_IMAGE,
                    'original_filename' => 'album-photo-'.($index + 1).'.jpg',
                    'mime_type' => 'image/jpeg',
                    'extension' => 'jpg',
                    'download_status' => MessageAttachment::DOWNLOAD_STATUS_METADATA_ONLY,
                    'sort_order' => 0,
                ]);

                return $message;
            });

        $feed = app(BuildConversationFeedViewDataAction::class)->handle(
            Message::query()
                ->whereKey($messages[1]->id)
                ->with(['channel', 'dialog.channel', 'sentByUser'])
                ->get(),
        );

        $this->assertCount(1, $feed);
        $this->assertSame($messages->pluck('id')->all(), $feed[0]['message_ids']);
        $this->assertSame($messages[0]->id, $feed[0]['id']);
        $this->assertSame('tdlib-album-61', $feed[0]['provider_group_key']);
        $this->assertStringStartsWith('group:', $feed[0]['item_key']);
        $this->assertTrue($feed[0]['is_grouped']);
        $this->assertSame('Подпись альбома', $feed[0]['display_text']);
        $this->assertSame('<strong>Подпись</strong> альбома', $feed[0]['formatted_html']);
        $this->assertNull($feed[0]['html_source_text']);
        $this->assertFalse($feed[0]['is_media_only_display_text']);
        $this->assertCount(3, $feed[0]['media_items']);
        $this->assertSame([
            'album-photo-1.jpg',
            'album-photo-2.jpg',
            'album-photo-3.jpg',
        ], collect($feed[0]['media_items'])->pluck('file_name')->all());
        $this->assertSame([
            ['label' => 'Только метаданные x3', 'tone' => 'gray'],
        ], $feed[0]['media_state_badges']);
    }

    public function test_null_provider_group_key_messages_remain_separate_conversation_items(): void
    {
        $contact = Contact::factory()->create();
        $channel = Channel::factory()->account()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'single-photo-user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'single-photo-chat',
        ]);
        $baseTime = now()->subMinutes(5);

        $messages = collect([0, 1])
            ->map(function (int $index) use ($baseTime, $channel, $contact, $dialog, $identity): Message {
                $message = Message::factory()->create([
                    'dialog_id' => $dialog->id,
                    'contact_id' => $contact->id,
                    'contact_identity_id' => $identity->id,
                    'channel_id' => $channel->id,
                    'direction' => Message::DIRECTION_INBOUND,
                    'message_kind' => Message::KIND_INBOUND_USER,
                    'external_chat_id' => 'single-photo-chat',
                    'external_message_id' => 'single-photo-message-'.$index,
                    'provider_event_key' => 'telegram-account:single-photo-chat:single-photo-message-'.$index,
                    'provider_group_key' => null,
                    'text' => null,
                    'received_at' => $baseTime->copy()->addSeconds($index),
                ]);

                MessageAttachment::factory()->create([
                    'message_id' => $message->id,
                    'channel_id' => $channel->id,
                    'provider' => MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT,
                    'provider_event_key' => $message->provider_event_key,
                    'provider_attachment_key' => $index.':image:file-'.$index,
                    'media_kind' => MessageAttachment::MEDIA_KIND_IMAGE,
                    'original_filename' => 'single-photo-'.($index + 1).'.jpg',
                    'mime_type' => 'image/jpeg',
                    'extension' => 'jpg',
                    'download_status' => MessageAttachment::DOWNLOAD_STATUS_METADATA_ONLY,
                    'sort_order' => 0,
                ]);

                return $message;
            });

        $feed = app(BuildConversationFeedViewDataAction::class)->handle(
            Message::query()
                ->whereKey($messages->pluck('id')->all())
                ->with(['channel', 'dialog.channel', 'sentByUser'])
                ->get(),
        );

        $this->assertCount(2, $feed);
        $this->assertSame(
            $messages->map(fn (Message $message): array => [$message->id])->all(),
            collect($feed)->pluck('message_ids')->all(),
        );
        $this->assertSame(
            $messages->map(fn (Message $message): string => 'message:'.$message->id)->all(),
            collect($feed)->pluck('item_key')->all(),
        );
        $this->assertSame([false, false], collect($feed)->pluck('is_grouped')->all());
    }

    public function test_provider_group_key_does_not_cross_dialog_boundary(): void
    {
        $firstContact = Contact::factory()->create();
        $secondContact = Contact::factory()->create();
        $channel = Channel::factory()->account()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $firstIdentity = ContactIdentity::factory()->create([
            'contact_id' => $firstContact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'first-dialog-user',
        ]);
        $secondIdentity = ContactIdentity::factory()->create([
            'contact_id' => $secondContact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'second-dialog-user',
        ]);
        $firstDialog = Dialog::factory()->create([
            'contact_id' => $firstContact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $firstIdentity->id,
            'external_chat_id' => 'first-dialog-chat',
        ]);
        $secondDialog = Dialog::factory()->create([
            'contact_id' => $secondContact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $secondIdentity->id,
            'external_chat_id' => 'second-dialog-chat',
        ]);

        $firstMessage = Message::factory()->create([
            'dialog_id' => $firstDialog->id,
            'contact_id' => $firstContact->id,
            'contact_identity_id' => $firstIdentity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => 'first-dialog-chat',
            'external_message_id' => 'first-dialog-message',
            'provider_event_key' => 'telegram-account:first-dialog-chat:first-dialog-message',
            'provider_group_key' => 'same-telegram-group-key',
            'text' => null,
            'received_at' => now()->subMinute(),
        ]);
        $secondMessage = Message::factory()->create([
            'dialog_id' => $secondDialog->id,
            'contact_id' => $secondContact->id,
            'contact_identity_id' => $secondIdentity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => 'second-dialog-chat',
            'external_message_id' => 'second-dialog-message',
            'provider_event_key' => 'telegram-account:second-dialog-chat:second-dialog-message',
            'provider_group_key' => 'same-telegram-group-key',
            'text' => null,
            'received_at' => now(),
        ]);

        foreach ([$firstMessage, $secondMessage] as $index => $message) {
            MessageAttachment::factory()->create([
                'message_id' => $message->id,
                'channel_id' => $channel->id,
                'provider' => MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT,
                'provider_event_key' => $message->provider_event_key,
                'provider_attachment_key' => $index.':image:file-'.$index,
                'media_kind' => MessageAttachment::MEDIA_KIND_IMAGE,
                'original_filename' => 'dialog-photo-'.($index + 1).'.jpg',
                'mime_type' => 'image/jpeg',
                'extension' => 'jpg',
                'download_status' => MessageAttachment::DOWNLOAD_STATUS_METADATA_ONLY,
            ]);
        }

        $feed = app(BuildConversationFeedViewDataAction::class)->handle(
            Message::query()
                ->whereKey($firstMessage->id)
                ->with(['channel', 'dialog.channel', 'sentByUser'])
                ->get(),
        );

        $this->assertCount(1, $feed);
        $this->assertSame([$firstMessage->id], $feed[0]['message_ids']);
        $this->assertCount(1, $feed[0]['media_items']);
        $this->assertSame('dialog-photo-1.jpg', $feed[0]['media_items'][0]['file_name']);
    }

    public function test_attachment_media_items_expose_deleted_and_unsupported_states(): void
    {
        $message = Message::factory()->create([
            'provider_event_key' => 'telegram-account:ui-state:message-1',
            'text' => null,
        ]);

        MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $message->channel_id,
            'provider_event_key' => $message->provider_event_key,
            'provider_attachment_key' => '0:video:file-1',
            'media_kind' => MessageAttachment::MEDIA_KIND_VIDEO,
            'original_filename' => 'clip.mp4',
            'mime_type' => 'video/mp4',
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED,
            'safe_error_code' => 'unsupported_media_kind',
            'safe_error_message' => 'Media kind is not supported by the first Telegram Account download slice.',
            'sort_order' => 0,
        ]);
        MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $message->channel_id,
            'provider_event_key' => $message->provider_event_key,
            'provider_attachment_key' => '1:document:file-2',
            'media_kind' => MessageAttachment::MEDIA_KIND_DOCUMENT,
            'original_filename' => 'old.pdf',
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DELETED_LOCAL,
            'sort_order' => 1,
        ]);

        $feed = app(BuildConversationFeedViewDataAction::class)->handle(
            Message::query()
                ->whereKey($message->id)
                ->with(['channel', 'dialog.channel', 'sentByUser'])
                ->get(),
        );

        $this->assertSame([
            ['label' => 'Не поддерживается', 'tone' => 'warning'],
            ['label' => 'Файл удалён', 'tone' => 'gray'],
        ], $feed[0]['media_state_badges']);
        $this->assertSame('Не поддерживается', $feed[0]['media_items'][0]['status_label']);
        $this->assertSame('Файл удалён', $feed[0]['media_items'][1]['status_label']);
        $this->assertFalse($feed[0]['media_items'][0]['is_downloadable']);
        $this->assertFalse($feed[0]['media_items'][1]['is_downloadable']);
    }

    public function test_message_attachment_relation_persists_slice_one_reserved_fields(): void
    {
        $message = Message::factory()->create([
            'provider_event_key' => 'telegram-account:chat-1:message-1',
        ]);

        $attachment = MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $message->channel_id,
            'provider_event_key' => $message->provider_event_key,
            'provider_attachment_key' => '0:image:file-1',
            'media_kind' => MessageAttachment::MEDIA_KIND_IMAGE,
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_METADATA_ONLY,
            'send_status' => MessageAttachment::SEND_STATUS_NOT_APPLICABLE,
            'outbound_attachment_key' => null,
            'local_disk' => null,
            'local_path' => null,
        ]);

        $loaded = $message->fresh('attachments');

        $this->assertCount(1, $loaded->attachments);
        $this->assertTrue($loaded->attachments->first()->is($attachment));
        $this->assertDatabaseHas('message_attachments', [
            'message_id' => $message->id,
            'channel_id' => $message->channel_id,
            'media_kind' => MessageAttachment::MEDIA_KIND_IMAGE,
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_METADATA_ONLY,
            'send_status' => MessageAttachment::SEND_STATUS_NOT_APPLICABLE,
            'outbound_attachment_key' => null,
            'local_disk' => null,
            'local_path' => null,
        ]);
    }

    public function test_message_attachment_rejects_channel_that_does_not_match_message(): void
    {
        $message = Message::factory()->create();
        $otherChannel = Channel::factory()->create();

        $this->expectException(QueryException::class);

        MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $otherChannel->id,
            'provider_event_key' => 'telegram-account:chat-1:message-1',
            'provider_attachment_key' => '0:image:file-1',
        ]);
    }

    public function test_message_attachment_inbound_identity_is_unique_by_provider_channel_event_and_attachment_key(): void
    {
        $message = Message::factory()->create([
            'provider_event_key' => 'telegram-account:chat-1:message-1',
        ]);
        $identity = [
            'message_id' => $message->id,
            'channel_id' => $message->channel_id,
            'provider' => 'telegram_account',
            'provider_event_key' => $message->provider_event_key,
            'provider_attachment_key' => '0:image:file-1',
        ];

        MessageAttachment::factory()->create($identity);

        $this->expectException(QueryException::class);

        MessageAttachment::factory()->create($identity);
    }

    public function test_message_attachment_outbound_key_is_unique_when_present(): void
    {
        $firstMessage = Message::factory()->create();
        $secondMessage = Message::factory()->create();

        MessageAttachment::factory()->create([
            'message_id' => $firstMessage->id,
            'channel_id' => $firstMessage->channel_id,
            'provider' => null,
            'provider_event_key' => null,
            'provider_attachment_key' => null,
            'outbound_attachment_key' => 'outbound-attachment-1',
        ]);

        $this->expectException(QueryException::class);

        MessageAttachment::factory()->create([
            'message_id' => $secondMessage->id,
            'channel_id' => $secondMessage->channel_id,
            'provider' => null,
            'provider_event_key' => null,
            'provider_attachment_key' => null,
            'outbound_attachment_key' => 'outbound-attachment-1',
        ]);
    }
}
