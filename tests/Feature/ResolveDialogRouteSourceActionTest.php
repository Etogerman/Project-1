<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Message;
use App\Services\Dialogs\ResolveDialogRouteSourceAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ResolveDialogRouteSourceActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_message_route_source_uses_channel_token_predicate(): void
    {
        $contact = Contact::factory()->create();
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => ['token' => 'max-route-token'],
            'bot_token_present' => true,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => Channel::PLATFORM_MAX,
            'external_user_id' => 'max-user-500',
        ]);
        $message = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'external_chat_id' => null,
        ]);

        DB::table('channels')
            ->where('id', $channel->id)
            ->update(['bot_token_present' => false]);

        $this->assertFalse($channel->fresh()->hasBotTokenConfigured());
        $this->assertSame('max-route-token', $channel->fresh()->getToken());

        $this->assertFalse(
            app(ResolveDialogRouteSourceAction::class)->legacyMessageCanBeUsedAsRouteSource($message->fresh()),
        );
    }
}
