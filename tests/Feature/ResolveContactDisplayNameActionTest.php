<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Dialog;
use App\Services\Contacts\ResolveContactDisplayNameAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResolveContactDisplayNameActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_combines_first_name_and_last_name_without_duplicate_suffix(): void
    {
        $contact = Contact::factory()->create([
            'first_name' => 'Иван',
            'last_name' => 'Петров',
        ]);

        $this->assertSame('Иван Петров', app(ResolveContactDisplayNameAction::class)->handle($contact));
    }

    public function test_it_avoids_duplicate_last_name_when_first_name_already_contains_it(): void
    {
        $contact = Contact::factory()->create([
            'first_name' => 'Иван Петров',
            'last_name' => 'Петров',
        ]);

        $this->assertSame('Иван Петров', app(ResolveContactDisplayNameAction::class)->handle($contact));
    }

    public function test_it_falls_back_to_identity_display_name(): void
    {
        $contact = Contact::factory()->create([
            'first_name' => null,
            'last_name' => null,
        ]);
        $contact->setRelation('identities', collect([ContactIdentity::factory()->make([
            'display_name' => 'Имя канала',
            'external_username' => 'runtime_customer',
        ])]));

        $this->assertSame('Имя канала', app(ResolveContactDisplayNameAction::class)->handle($contact));
    }

    public function test_it_temporarily_falls_back_to_legacy_name_before_identity_labels(): void
    {
        $contact = Contact::factory()->create([
            'name' => 'Имя из мессенджера',
            'first_name' => null,
            'last_name' => null,
        ]);
        $contact->setRelation('identities', collect([ContactIdentity::factory()->make([
            'display_name' => 'Имя канала',
            'external_username' => 'runtime_customer',
        ])]));

        $this->assertSame('Имя из мессенджера', app(ResolveContactDisplayNameAction::class)->handle($contact));
    }

    public function test_it_falls_back_to_identity_username(): void
    {
        $contact = Contact::factory()->create([
            'first_name' => null,
            'last_name' => null,
        ]);
        $contact->setRelation('identities', collect([ContactIdentity::factory()->make([
            'display_name' => null,
            'external_username' => 'runtime_customer',
        ])]));

        $this->assertSame('@runtime_customer', app(ResolveContactDisplayNameAction::class)->handle($contact));
    }

    public function test_it_falls_back_to_contact_id_when_identity_label_is_missing(): void
    {
        $contact = Contact::factory()->create([
            'first_name' => null,
            'last_name' => null,
            'name' => null,
        ]);

        $this->assertSame("Контакт #{$contact->id}", app(ResolveContactDisplayNameAction::class)->handle($contact));
    }

    public function test_it_prefers_identity_from_most_recent_dialog_outside_dialog_context(): void
    {
        $contact = Contact::factory()->create([
            'first_name' => null,
            'last_name' => null,
            'name' => null,
        ]);
        $channel = Channel::factory()->create();
        $staleIdentity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'stale-user',
            'external_username' => 'stale_user',
        ]);
        $freshIdentity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'fresh-user',
            'external_username' => 'fresh_user',
        ]);

        Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $staleIdentity->id,
            'last_message_at' => now()->subDay(),
        ]);
        Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $freshIdentity->id,
            'last_message_at' => now(),
        ]);

        $this->assertSame('@fresh_user', app(ResolveContactDisplayNameAction::class)->handle($contact));
    }

    public function test_it_prefers_dialog_context_identity_over_global_contact_label(): void
    {
        $contact = Contact::factory()->create([
            'first_name' => null,
            'last_name' => null,
            'name' => null,
        ]);
        $channel = Channel::factory()->create();
        $globalIdentity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'global-user',
            'display_name' => 'Глобальное имя',
        ]);
        $dialogIdentity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'dialog-user',
            'display_name' => 'Имя текущего диалога',
        ]);

        Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $globalIdentity->id,
            'last_message_at' => now(),
        ]);

        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $dialogIdentity->id,
            'last_message_at' => now()->subMinute(),
        ]);

        $this->assertSame('Имя текущего диалога', app(ResolveContactDisplayNameAction::class)->handle($contact, $dialog));
    }
}
