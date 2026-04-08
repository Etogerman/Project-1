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
}
