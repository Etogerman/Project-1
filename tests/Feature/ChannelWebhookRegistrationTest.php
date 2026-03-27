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
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
            ]),
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
            ->callTableAction('registerWebhook', $channel)
            ->assertHasNoTableActionErrors();

        $channel->refresh();

        $this->assertNotNull($channel->getWebhookSecret());

        Http::assertSent(function ($request) use ($channel): bool {
            return $request->url() === 'https://api.telegram.org/bottelegram-token/setWebhook'
                && $request['url'] === "https://connector.example/webhooks/telegram/{$channel->id}"
                && $request['secret_token'] === $channel->getWebhookSecret()
                && $request['allowed_updates'] === ['message'];
        });
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
                ], 200),
            'https://platform-api.max.ru/subscriptions?url=https%3A%2F%2Fold.example%2Fwebhook' => Http::response([], 200),
        ]);

        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create([
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

        Http::assertSent(function ($request) use ($channel): bool {
            if ($request->method() !== 'POST' || $request->url() !== 'https://platform-api.max.ru/subscriptions') {
                return false;
            }

            return $request->hasHeader('Authorization', 'max-token')
                && $request['url'] === "https://connector.example/webhooks/max/{$channel->id}"
                && $request['secret'] === $channel->getWebhookSecret()
                && $request['update_types'] === ['message_created'];
        });
    }
}
