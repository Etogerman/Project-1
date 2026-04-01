<?php

namespace Tests\Feature;

use App\Models\Channel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChannelBotTokenPresenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_channel_marks_bot_token_present_when_created_with_token(): void
    {
        $channel = Channel::factory()->create([
            'credentials' => ['token' => 'telegram-token'],
        ]);

        $this->assertTrue($channel->fresh()->bot_token_present);
        $this->assertTrue($channel->fresh()->hasBotTokenConfigured());
    }

    public function test_channel_marks_bot_token_present_false_when_token_is_removed(): void
    {
        $channel = Channel::factory()->create([
            'credentials' => ['token' => 'telegram-token'],
        ]);

        $channel->update([
            'credentials' => [],
        ]);

        $this->assertFalse($channel->fresh()->bot_token_present);
        $this->assertFalse($channel->fresh()->hasBotTokenConfigured());
    }

    public function test_channel_put_credential_syncs_bot_token_presence(): void
    {
        $channel = Channel::factory()->create([
            'credentials' => [],
        ]);

        $channel->putCredential(Channel::CREDENTIAL_TOKEN, 'fresh-token')->save();

        $this->assertTrue($channel->fresh()->bot_token_present);
        $this->assertTrue($channel->fresh()->hasBotTokenConfigured());
    }
}
