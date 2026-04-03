<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactStartTag;
use App\Models\Message;
use App\Services\Contacts\AssignContactStartTagAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AssignContactStartTagActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_assigns_start_tag_for_telegram_start_payload(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $contact = Contact::factory()->create();
        $message = Message::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'text' => '/start TEXT_1',
            'received_at' => Carbon::parse('2026-04-03 11:00:00'),
        ]);

        $tag = app(AssignContactStartTagAction::class)->handle($contact, $message, $channel);

        $this->assertNotNull($tag);
        $this->assertSame(ContactStartTag::CATEGORY_START_PAYLOAD, $tag->category);
        $this->assertSame('TEXT_1', $tag->code);
        $this->assertSame(ContactStartTag::SOURCE_TELEGRAM_START, $tag->source);
        $this->assertSame($message->id, $tag->source_message_id);
        $this->assertSame('2026-04-03 11:00:00', $tag->assigned_at?->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('contact_start_tags', [
            'contact_id' => $contact->id,
            'category' => ContactStartTag::CATEGORY_START_PAYLOAD,
            'code' => 'TEXT_1',
            'source' => ContactStartTag::SOURCE_TELEGRAM_START,
            'source_message_id' => $message->id,
        ]);
    }

    public function test_assigns_start_tag_for_max_bot_started_payload(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
        ]);
        $contact = Contact::factory()->create();
        $message = Message::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'text' => null,
            'raw_payload' => [
                'update_type' => 'bot_started',
                'payload' => 'TEXT_1',
            ],
            'received_at' => Carbon::parse('2026-04-03 12:00:00'),
        ]);

        $tag = app(AssignContactStartTagAction::class)->handle($contact, $message, $channel);

        $this->assertNotNull($tag);
        $this->assertSame('TEXT_1', $tag->code);
        $this->assertSame(ContactStartTag::SOURCE_MAX_START, $tag->source);
        $this->assertDatabaseHas('contact_start_tags', [
            'contact_id' => $contact->id,
            'category' => ContactStartTag::CATEGORY_START_PAYLOAD,
            'code' => 'TEXT_1',
            'source' => ContactStartTag::SOURCE_MAX_START,
        ]);
    }

    public function test_does_not_assign_start_tag_for_plain_telegram_start(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $contact = Contact::factory()->create();
        $message = Message::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'text' => '/start',
        ]);

        $tag = app(AssignContactStartTagAction::class)->handle($contact, $message, $channel);

        $this->assertNull($tag);
        $this->assertDatabaseCount('contact_start_tags', 0);
    }

    public function test_does_not_assign_start_tag_for_blank_telegram_payload(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $contact = Contact::factory()->create();
        $message = Message::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'text' => '/start     ',
        ]);

        $tag = app(AssignContactStartTagAction::class)->handle($contact, $message, $channel);

        $this->assertNull($tag);
        $this->assertDatabaseCount('contact_start_tags', 0);
    }

    public function test_does_not_assign_start_tag_for_empty_max_payload(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
        ]);
        $contact = Contact::factory()->create();
        $message = Message::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'raw_payload' => [
                'update_type' => 'bot_started',
                'payload' => '   ',
            ],
        ]);

        $tag = app(AssignContactStartTagAction::class)->handle($contact, $message, $channel);

        $this->assertNull($tag);
        $this->assertDatabaseCount('contact_start_tags', 0);
    }

    public function test_deduplicates_same_contact_start_payload(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $contact = Contact::factory()->create();
        $firstMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'text' => '/start TEXT_1',
            'received_at' => Carbon::parse('2026-04-03 13:00:00'),
        ]);
        $secondMessage = Message::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'text' => '/start TEXT_1',
            'received_at' => Carbon::parse('2026-04-03 13:05:00'),
        ]);

        $firstTag = app(AssignContactStartTagAction::class)->handle($contact, $firstMessage, $channel);
        $secondTag = app(AssignContactStartTagAction::class)->handle($contact, $secondMessage, $channel);

        $this->assertNotNull($firstTag);
        $this->assertNotNull($secondTag);
        $this->assertTrue($firstTag->is($secondTag));
        $this->assertSame($firstMessage->id, $secondTag->source_message_id);
        $this->assertDatabaseCount('contact_start_tags', 1);
    }
}
