<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Message;
use App\Services\Contacts\SelectPrimaryContactForMergeAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SelectPrimaryContactForMergeActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_prefers_more_complete_profile(): void
    {
        $moreComplete = Contact::factory()->create([
            'first_name' => 'Alice',
            'last_name' => 'Ivanova',
            'country' => 'Россия',
            'city' => 'Москва',
        ]);
        $lessComplete = Contact::factory()->create([
            'first_name' => 'Alice',
        ]);

        $result = app(SelectPrimaryContactForMergeAction::class)->handle($moreComplete, $lessComplete);

        $this->assertNotNull($result);
        $this->assertSame($moreComplete->id, $result->primary->id);
        $this->assertSame($lessComplete->id, $result->secondary->id);
    }

    public function test_it_prefers_more_messages_when_profile_completeness_is_equal(): void
    {
        $channel = Channel::factory()->create();

        $fewerMessages = Contact::factory()->create([
            'first_name' => 'Alice',
        ]);
        $moreMessages = Contact::factory()->create([
            'first_name' => 'Alice',
        ]);

        $this->createInboundMessage($fewerMessages, $channel, 'user-1');
        $this->createInboundMessage($moreMessages, $channel, 'user-2');
        $this->createInboundMessage($moreMessages, $channel, 'user-3');

        $result = app(SelectPrimaryContactForMergeAction::class)->handle($fewerMessages, $moreMessages);

        $this->assertNotNull($result);
        $this->assertSame($moreMessages->id, $result->primary->id);
        $this->assertSame($fewerMessages->id, $result->secondary->id);
    }

    public function test_it_prefers_earlier_created_contact_when_profile_and_message_counts_are_equal(): void
    {
        $earlier = Contact::factory()->create([
            'first_name' => 'Alice',
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);
        $later = Contact::factory()->create([
            'first_name' => 'Alice',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = app(SelectPrimaryContactForMergeAction::class)->handle($later, $earlier);

        $this->assertNotNull($result);
        $this->assertSame($earlier->id, $result->primary->id);
        $this->assertSame($later->id, $result->secondary->id);
    }

    public function test_it_prefers_lower_id_when_everything_else_is_equal(): void
    {
        $timestamp = now()->subHour();

        $first = Contact::factory()->create([
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        $second = Contact::factory()->create([
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $result = app(SelectPrimaryContactForMergeAction::class)->handle($second, $first);

        $this->assertNotNull($result);
        $this->assertSame(min($first->id, $second->id), $result->primary->id);
        $this->assertSame(max($first->id, $second->id), $result->secondary->id);
    }

    public function test_it_returns_null_when_contacts_already_resolve_to_same_root(): void
    {
        $root = Contact::factory()->create();
        $merged = Contact::factory()->create([
            'merged_into_contact_id' => $root->id,
            'merged_at' => now(),
            'merge_reason' => 'phone_exact_match',
            'merge_trigger_phone' => '+79991234567',
        ]);

        $result = app(SelectPrimaryContactForMergeAction::class)->handle($root, $merged);

        $this->assertNull($result);
    }

    private function createInboundMessage(Contact $contact, Channel $channel, string $externalUserId): Message
    {
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => $externalUserId,
        ]);

        return Message::factory()->create([
            'contact_identity_id' => $identity->id,
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
        ]);
    }
}
