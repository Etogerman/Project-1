<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Dialog;
use App\Models\Message;
use App\Models\User;
use App\Services\Dialogs\LoadContactDialogsOverviewAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoadContactDialogsOverviewActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_text_uses_plain_fallback_for_html_message(): void
    {
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create();
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'preview-user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'preview-chat',
        ]);

        Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'sent_by_user_id' => $employee->id,
            'external_chat_id' => 'preview-chat',
            'text' => 'HTML preview',
            'text_format' => Message::TEXT_FORMAT_HTML,
            'source_text' => '<b>HTML preview</b>',
            'received_at' => now(),
        ]);

        $overview = app(LoadContactDialogsOverviewAction::class)->handle($contact);

        $this->assertCount(1, $overview);
        $this->assertSame('HTML preview', $overview[0]['preview_text']);
    }
}
