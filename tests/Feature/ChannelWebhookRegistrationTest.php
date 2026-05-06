<?php

namespace Tests\Feature;

use App\Filament\Resources\Channels\Pages\ManageChannels;
use App\Models\Channel;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ChannelWebhookRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();

        config()->set('app.url', 'https://connector.example');
    }

    public function test_admin_can_register_telegram_webhook_from_filament_resource(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
            ],
        ]);

        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push([
                    'ok' => true,
                ], 200)
                ->push([
                    'ok' => true,
                    'result' => [
                        'id' => 123456789,
                        'is_bot' => true,
                        'first_name' => 'Staging Bot',
                        'username' => 'stagin_g_1_bot',
                    ],
                ], 200)
                ->push([
                    'ok' => true,
                    'result' => [
                        'url' => "https://connector.example/webhooks/telegram/{$channel->id}",
                    ],
                ], 200),
        ]);

        Livewire::actingAs($admin)
            ->test(ManageChannels::class)
            ->callTableAction('registerWebhook', $channel)
            ->assertHasNoTableActionErrors();

        $channel->refresh();

        $this->assertNotNull($channel->getWebhookSecret());
        $this->assertSame('123456789', $channel->bot_external_id);
        $this->assertSame('stagin_g_1_bot', $channel->bot_username);
        $this->assertSame('Staging Bot', $channel->bot_name);
        $this->assertSame('https://t.me/stagin_g_1_bot', $channel->getBotProfileUrl());
        $this->assertSame(Channel::CONNECTION_STATUS_CONNECTED, $channel->connection_status);
        $this->assertSame(Channel::WEBHOOK_STATUS_INSTALLED, $channel->webhook_status);

        Http::assertSent(function ($request) use ($channel): bool {
            return $request->url() === 'https://api.telegram.org/bottelegram-token/setWebhook'
                && $request['url'] === "https://connector.example/webhooks/telegram/{$channel->id}"
                && $request['secret_token'] === $channel->getWebhookSecret()
                && $request['allowed_updates'] === ['message', 'callback_query', 'my_chat_member'];
        });

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/getMe');
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/getWebhookInfo');

        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'webhook.registration_completed',
        ]);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'bot.metadata_synced',
        ]);
    }

    public function test_admin_can_register_max_webhook_from_filament_resource(): void
    {
        Http::fake([
            'https://platform-api.max.ru/subscriptions' => Http::sequence()
                ->push([
                    'subscriptions' => [
                        ['url' => 'https://old.example/webhook'],
                    ],
                ], 200)
                ->push([
                    'subscription' => [],
                ], 200)
                ->push([
                    'subscriptions' => [
                        [
                            'url' => 'https://connector.example/webhooks/max/1',
                        ],
                    ],
                ], 200),
            'https://platform-api.max.ru/subscriptions?url=https%3A%2F%2Fold.example%2Fwebhook' => Http::response([], 200),
            'https://platform-api.max.ru/me' => Http::response([
                'user_id' => 987654321,
                'name' => 'Стейджинг-1',
                'first_name' => 'MAX',
                'last_name' => 'Bot',
                'username' => 'max_stage_bot',
            ], 200),
        ]);

        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create([
            'id' => 1,
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
            ],
        ]);

        Livewire::actingAs($admin)
            ->test(ManageChannels::class)
            ->callTableAction('registerWebhook', $channel)
            ->assertHasNoTableActionErrors();

        $channel->refresh();

        $this->assertNotNull($channel->getWebhookSecret());
        $this->assertSame('987654321', $channel->bot_external_id);
        $this->assertSame('max_stage_bot', $channel->bot_username);
        $this->assertSame('Стейджинг-1', $channel->bot_name);
        $this->assertSame('https://max.ru/max_stage_bot', $channel->getBotProfileUrl());
        $this->assertSame(Channel::CONNECTION_STATUS_CONNECTED, $channel->connection_status);
        $this->assertSame(Channel::WEBHOOK_STATUS_INSTALLED, $channel->webhook_status);
        $this->assertSame("https://connector.example/webhooks/max/{$channel->id}", $channel->expected_webhook_url);
        $this->assertSame("https://connector.example/webhooks/max/{$channel->id}", $channel->provider_webhook_url);

        Http::assertSent(function ($request) use ($channel): bool {
            if ($request->method() !== 'POST' || $request->url() !== 'https://platform-api.max.ru/subscriptions') {
                return false;
            }

            return $request->hasHeader('Authorization', 'max-token')
                && $request['url'] === "https://connector.example/webhooks/max/{$channel->id}"
                && $request['secret'] === $channel->getWebhookSecret()
                && $request['update_types'] === ['message_created', 'bot_started'];
        });

        Http::assertSent(fn ($request): bool => $request->url() === 'https://platform-api.max.ru/me');
    }

    public function test_admin_sees_notification_when_registering_webhook_without_bot_token(): void
    {
        Http::fake();

        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [],
        ]);

        Livewire::actingAs($admin)
            ->test(ManageChannels::class)
            ->callTableAction('registerWebhook', $channel)
            ->assertHasNoTableActionErrors()
            ->assertNotified();

        $channel->refresh();

        $this->assertNull($channel->getWebhookSecret());
        $this->assertSame(Channel::CONNECTION_STATUS_NOT_CONNECTED, $channel->connection_status);
        $this->assertSame(Channel::CONNECTION_ERROR_NO_TOKEN, $channel->connection_error_message);

        Http::assertSentCount(0);
    }

    public function test_admin_can_sync_telegram_bot_metadata_without_reregistering_webhook(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'id' => 100200300,
                    'is_bot' => true,
                    'first_name' => 'Support Bot',
                    'username' => 'support_stage_bot',
                ],
            ], 200),
        ]);

        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
            ],
            'last_error_at' => now(),
            'last_error_message' => 'Old error',
        ]);

        Livewire::actingAs($admin)
            ->test(ManageChannels::class)
            ->callTableAction('syncBotMetadata', $channel)
            ->assertHasNoTableActionErrors();

        $channel->refresh();

        $this->assertSame('100200300', $channel->bot_external_id);
        $this->assertSame('support_stage_bot', $channel->bot_username);
        $this->assertSame('Support Bot', $channel->bot_name);
        $this->assertSame('https://t.me/support_stage_bot', $channel->getBotProfileUrl());
        $this->assertNull($channel->last_error_at);
        $this->assertNull($channel->last_error_message);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottelegram-token/getMe');
    }

    public function test_failed_metadata_sync_is_logged_in_channel_activity_logs(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => false,
            ], 500),
        ]);

        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => [
                'token' => 'telegram-token',
            ],
        ]);

        Livewire::actingAs($admin)
            ->test(ManageChannels::class)
            ->callTableAction('syncBotMetadata', $channel)
            ->assertHasNoTableActionErrors()
            ->assertNotified();

        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'bot.metadata_sync_failed',
            'level' => 'error',
        ]);
    }
}
