<?php

namespace Tests\Feature;

use App\Models\Channel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_successful_webhook_receipt_clears_stale_operational_error(): void
    {
        $channel = Channel::factory()->create([
            'credentials' => [
                'token' => 'telegram-token',
                'webhook_secret' => 'webhook-secret',
            ],
            'last_webhook_received_at' => null,
            'last_reply_sent_at' => null,
            'last_error_at' => now(),
            'last_error_message' => 'Старая ошибка',
        ]);

        $channel->markWebhookReceived();

        $channel = $channel->fresh();

        $this->assertNull($channel->last_error_at);
        $this->assertNull($channel->last_error_message);
        $this->assertSame('Webhook', $channel->getHealthStatusLabel());
        $this->assertTrue($channel->isReadyForConstructorAutoReplies());
    }

    public function test_operational_state_can_be_saved_when_credentials_are_unreadable(): void
    {
        $channel = Channel::factory()->create([
            'credentials' => [
                'token' => 'telegram-token',
            ],
        ]);

        DB::table('channels')
            ->where('id', $channel->id)
            ->update(['credentials' => 'broken-encrypted-value']);

        $channel->fresh()->markError('Ошибка проверки');

        $channel = $channel->fresh();

        $this->assertSame('Ошибка проверки', $channel->last_error_message);

        $channel->clearOperationalError();

        $channel = $channel->fresh();

        $this->assertNull($channel->last_error_at);
        $this->assertNull($channel->last_error_message);
        $this->assertSame('Ошибка настроек', $channel->getHealthStatusLabel());
    }
}
