<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\ChannelConnectionCheckRun;
use App\Services\Bots\ChannelWebhookUrlGenerator;
use App\Services\Bots\CheckChannelConnectionAction;
use App\Services\Bots\RecordChannelConnectionCheckRunAction;
use App\Services\Bots\ResolveChannelConnectionCheckerHealthAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChannelConnectionCheckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.url', 'https://connector.example');
    }

    public function test_telegram_bot_is_connected_when_webhook_matches_current_admin(): void
    {
        Http::fake([
            'https://api.telegram.org/*/getWebhookInfo' => Http::response([
                'ok' => true,
                'result' => [
                    'url' => 'https://connector.example/webhooks/telegram/1',
                ],
            ]),
        ]);

        $channel = Channel::factory()->create([
            'id' => 1,
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'credentials' => ['token' => 'telegram-token'],
            'is_active' => true,
        ]);

        app(CheckChannelConnectionAction::class)->handle($channel);

        $channel->refresh();

        $this->assertSame(Channel::CONNECTION_STATUS_CONNECTED, $channel->connection_status);
        $this->assertSame(Channel::WEBHOOK_STATUS_INSTALLED, $channel->webhook_status);
        $this->assertNull($channel->connection_error_message);
        $this->assertSame("https://connector.example/webhooks/telegram/{$channel->id}", $channel->expected_webhook_url);
        $this->assertSame("https://connector.example/webhooks/telegram/{$channel->id}", $channel->provider_webhook_url);
        $this->assertNotNull($channel->connection_checked_at);
    }

    public function test_webhook_url_uses_configured_app_url_instead_of_current_request_host(): void
    {
        config()->set('app.url', 'https://old-admin.example');

        $channel = Channel::factory()->create([
            'id' => 1,
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
        ]);

        $this->bindRequest('https://fresh-admin.example/admin/channels');

        $this->assertSame(
            "https://old-admin.example/webhooks/telegram/{$channel->id}",
            app(ChannelWebhookUrlGenerator::class)->for($channel),
        );
    }

    public function test_webhook_url_ignores_forwarded_public_https_host_from_request(): void
    {
        config()->set('app.url', 'https://old-admin.example');

        $channel = Channel::factory()->create([
            'id' => 1,
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
        ]);

        $this->bindRequest('http://127.0.0.1:8002/admin/channels', [
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => 'fresh-tunnel.trycloudflare.com',
        ]);

        $this->assertSame(
            "https://old-admin.example/webhooks/telegram/{$channel->id}",
            app(ChannelWebhookUrlGenerator::class)->for($channel),
        );
    }

    public function test_webhook_url_ignores_local_admin_request_and_keeps_configured_app_url(): void
    {
        config()->set('app.url', 'https://connector.example');

        $channel = Channel::factory()->create([
            'id' => 1,
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
        ]);

        $this->bindRequest('http://127.0.0.1:8002/admin/channels');

        $this->assertSame(
            "https://connector.example/webhooks/telegram/{$channel->id}",
            app(ChannelWebhookUrlGenerator::class)->for($channel),
        );
    }

    public function test_telegram_bot_is_not_connected_when_webhook_points_elsewhere(): void
    {
        Http::fake([
            'https://api.telegram.org/*/getWebhookInfo' => Http::response([
                'ok' => true,
                'result' => [
                    'url' => 'https://other-local.example/webhooks/telegram/1',
                ],
            ]),
        ]);

        $channel = Channel::factory()->create([
            'id' => 1,
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'credentials' => ['token' => 'telegram-token'],
            'is_active' => true,
        ]);

        app(CheckChannelConnectionAction::class)->handle($channel);

        $channel->refresh();

        $this->assertSame(Channel::CONNECTION_STATUS_NOT_CONNECTED, $channel->connection_status);
        $this->assertSame(Channel::WEBHOOK_STATUS_NOT_INSTALLED, $channel->webhook_status);
        $this->assertSame('Webhook установлен не на эту админку', $channel->connection_error_message);
        $this->assertSame('https://other-local.example/webhooks/telegram/1', $channel->provider_webhook_url);
    }

    public function test_max_bot_is_connected_when_webhook_matches_current_admin(): void
    {
        Http::fake([
            'https://platform-api.max.ru/subscriptions' => Http::response([
                'subscriptions' => [
                    [
                        'url' => 'https://connector.example/webhooks/max/1',
                    ],
                ],
            ]),
        ]);

        $channel = Channel::factory()->create([
            'id' => 1,
            'platform' => Channel::PLATFORM_MAX,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'credentials' => ['token' => 'max-token'],
            'is_active' => true,
        ]);

        app(CheckChannelConnectionAction::class)->handle($channel);

        $channel->refresh();

        $this->assertSame(Channel::CONNECTION_STATUS_CONNECTED, $channel->connection_status);
        $this->assertSame(Channel::WEBHOOK_STATUS_INSTALLED, $channel->webhook_status);
        $this->assertNull($channel->connection_error_message);
        $this->assertSame("https://connector.example/webhooks/max/{$channel->id}", $channel->expected_webhook_url);
        $this->assertSame("https://connector.example/webhooks/max/{$channel->id}", $channel->provider_webhook_url);
        $this->assertNotNull($channel->connection_checked_at);
    }

    public function test_max_bot_is_not_connected_when_webhook_points_elsewhere(): void
    {
        Http::fake([
            'https://platform-api.max.ru/subscriptions' => Http::response([
                'subscriptions' => [
                    [
                        'url' => 'https://other-local.example/webhooks/max/1',
                    ],
                ],
            ]),
        ]);

        $channel = Channel::factory()->create([
            'id' => 1,
            'platform' => Channel::PLATFORM_MAX,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'credentials' => ['token' => 'max-token'],
            'is_active' => true,
        ]);

        app(CheckChannelConnectionAction::class)->handle($channel);

        $channel->refresh();

        $this->assertSame(Channel::CONNECTION_STATUS_NOT_CONNECTED, $channel->connection_status);
        $this->assertSame(Channel::WEBHOOK_STATUS_NOT_INSTALLED, $channel->webhook_status);
        $this->assertSame('Webhook установлен не на эту админку', $channel->connection_error_message);
        $this->assertSame('https://other-local.example/webhooks/max/1', $channel->provider_webhook_url);
    }

    public function test_max_bot_is_not_connected_when_expected_webhook_has_extra_subscriptions(): void
    {
        Http::fake([
            'https://platform-api.max.ru/subscriptions' => Http::response([
                'subscriptions' => [
                    [
                        'url' => 'https://connector.example/webhooks/max/1',
                    ],
                    [
                        'url' => 'https://old-local.example/webhooks/max/1',
                    ],
                ],
            ]),
        ]);

        $channel = Channel::factory()->create([
            'id' => 1,
            'platform' => Channel::PLATFORM_MAX,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'credentials' => ['token' => 'max-token'],
            'is_active' => true,
        ]);

        app(CheckChannelConnectionAction::class)->handle($channel);

        $channel->refresh();

        $this->assertSame(Channel::CONNECTION_STATUS_NOT_CONNECTED, $channel->connection_status);
        $this->assertSame(Channel::WEBHOOK_STATUS_NOT_INSTALLED, $channel->webhook_status);
        $this->assertSame(
            'В MAX найдены лишние webhook subscriptions: https://old-local.example/webhooks/max/1',
            $channel->connection_error_message,
        );
        $this->assertSame(
            'https://connector.example/webhooks/max/1, https://old-local.example/webhooks/max/1',
            $channel->provider_webhook_url,
        );
        $this->assertSame("https://connector.example/webhooks/max/{$channel->id}", $channel->expected_webhook_url);
        $this->assertNotNull($channel->connection_checked_at);
    }

    public function test_max_bot_truncates_long_subscription_list_to_provider_webhook_url_limit(): void
    {
        $longOldWebhookUrl = 'https://old-local.example/webhooks/max/'.str_repeat('very-long-segment-', 140);
        $anotherLongOldWebhookUrl = 'https://another-old-local.example/webhooks/max/'.str_repeat('another-long-segment-', 140);

        Http::fake([
            'https://platform-api.max.ru/subscriptions' => Http::response([
                'subscriptions' => [
                    [
                        'url' => 'https://connector.example/webhooks/max/1',
                    ],
                    [
                        'url' => $longOldWebhookUrl,
                    ],
                    [
                        'url' => $anotherLongOldWebhookUrl,
                    ],
                ],
            ]),
        ]);

        $channel = Channel::factory()->create([
            'id' => 1,
            'platform' => Channel::PLATFORM_MAX,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'credentials' => ['token' => 'max-token'],
            'is_active' => true,
        ]);

        app(CheckChannelConnectionAction::class)->handle($channel);

        $channel->refresh();

        $this->assertSame(Channel::CONNECTION_STATUS_NOT_CONNECTED, $channel->connection_status);
        $this->assertSame(Channel::WEBHOOK_STATUS_NOT_INSTALLED, $channel->webhook_status);
        $this->assertLessThanOrEqual(2048, strlen((string) $channel->provider_webhook_url));
        $this->assertFalse(str_ends_with((string) $channel->provider_webhook_url, '...'));
        $this->assertStringContainsString('https://connector.example/webhooks/max/1', (string) $channel->provider_webhook_url);
        $this->assertStringContainsString('В MAX найдены лишние webhook subscriptions:', (string) $channel->connection_error_message);
        $this->assertLessThanOrEqual(1000, mb_strlen((string) $channel->connection_error_message));
    }

    public function test_disabled_channel_is_marked_not_connected_without_calling_telegram(): void
    {
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'credentials' => ['token' => 'telegram-token'],
            'is_active' => false,
            'connection_status' => Channel::CONNECTION_STATUS_CONNECTED,
            'webhook_status' => Channel::WEBHOOK_STATUS_INSTALLED,
        ]);

        app(CheckChannelConnectionAction::class)->handle($channel);

        $channel->refresh();

        $this->assertSame(Channel::CONNECTION_STATUS_NOT_CONNECTED, $channel->connection_status);
        $this->assertSame(Channel::WEBHOOK_STATUS_NOT_INSTALLED, $channel->webhook_status);
        $this->assertSame(Channel::CONNECTION_ERROR_DISABLED, $channel->connection_error_message);

        Http::assertNothingSent();
    }

    public function test_effective_state_keeps_stale_successful_connection_sendable_with_warning(): void
    {
        $channel = Channel::factory()->create([
            'id' => 1,
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'credentials' => ['token' => 'telegram-token'],
            'is_active' => true,
            'connection_status' => Channel::CONNECTION_STATUS_CONNECTED,
            'webhook_status' => Channel::WEBHOOK_STATUS_INSTALLED,
            'connection_checked_at' => now()->subMinutes(11),
            'expected_webhook_url' => 'https://connector.example/webhooks/telegram/1',
            'provider_webhook_url' => 'https://connector.example/webhooks/telegram/1',
        ]);

        $state = app(CheckChannelConnectionAction::class)->resolveEffectiveState($channel);

        $this->assertSame(Channel::CONNECTION_STATUS_CONNECTED, $state['connection_status']);
        $this->assertSame(Channel::WEBHOOK_STATUS_INSTALLED, $state['webhook_status']);
        $this->assertSame(Channel::CONNECTION_ERROR_STALE, $state['connection_error_message']);
    }

    public function test_effective_state_treats_changed_expected_url_as_not_connected(): void
    {
        $channel = Channel::factory()->create([
            'id' => 1,
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'credentials' => ['token' => 'telegram-token'],
            'is_active' => true,
            'connection_status' => Channel::CONNECTION_STATUS_CONNECTED,
            'webhook_status' => Channel::WEBHOOK_STATUS_INSTALLED,
            'connection_checked_at' => now(),
            'expected_webhook_url' => 'https://old-admin.example/webhooks/telegram/1',
            'provider_webhook_url' => 'https://old-admin.example/webhooks/telegram/1',
        ]);

        $state = app(CheckChannelConnectionAction::class)->resolveEffectiveState($channel);

        $this->assertSame(Channel::CONNECTION_STATUS_NOT_CONNECTED, $state['connection_status']);
        $this->assertSame(Channel::CONNECTION_ERROR_EXPECTED_URL_CHANGED, $state['connection_error_message']);
    }

    public function test_effective_state_ignores_request_host_when_configured_url_matches_saved_url(): void
    {
        config()->set('app.url', 'https://old-admin.example');

        $channel = Channel::factory()->create([
            'id' => 1,
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'credentials' => ['token' => 'telegram-token'],
            'is_active' => true,
            'connection_status' => Channel::CONNECTION_STATUS_CONNECTED,
            'webhook_status' => Channel::WEBHOOK_STATUS_INSTALLED,
            'connection_checked_at' => now(),
            'expected_webhook_url' => 'https://old-admin.example/webhooks/telegram/1',
            'provider_webhook_url' => 'https://old-admin.example/webhooks/telegram/1',
        ]);

        $this->bindRequest('http://127.0.0.1:8002/admin/channels', [
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => 'attacker.example',
        ]);

        $state = app(CheckChannelConnectionAction::class)->resolveEffectiveState($channel);

        $this->assertSame(Channel::CONNECTION_STATUS_CONNECTED, $state['connection_status']);
        $this->assertNull($state['connection_error_message']);
        $this->assertSame('https://old-admin.example/webhooks/telegram/1', $state['expected_webhook_url']);
        $this->assertSame('https://old-admin.example/webhooks/telegram/1', $state['provider_webhook_url']);
    }

    public function test_console_command_updates_local_failure_statuses_without_telegram_api(): void
    {
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'credentials' => [],
            'bot_token_present' => false,
            'is_active' => true,
            'connection_status' => Channel::CONNECTION_STATUS_CONNECTED,
            'webhook_status' => Channel::WEBHOOK_STATUS_INSTALLED,
        ]);

        $exitCode = Artisan::call('channels:check-connections', ['--channel' => $channel->id]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(Channel::CONNECTION_STATUS_NOT_CONNECTED, $channel->fresh()->connection_status);
        $this->assertSame(Channel::CONNECTION_ERROR_NO_TOKEN, $channel->fresh()->connection_error_message);

        $run = ChannelConnectionCheckRun::query()->latest('id')->first();

        $this->assertInstanceOf(ChannelConnectionCheckRun::class, $run);
        $this->assertSame(ChannelConnectionCheckRun::STATUS_SUCCESS, $run->status);
        $this->assertSame(1, $run->processed_count);
        $this->assertSame(1, $run->success_count);
        $this->assertSame(0, $run->failure_count);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->finished_at);
        $this->assertSame(app()->environment(), $run->environment);

        Http::assertNothingSent();
    }

    public function test_bulk_console_command_marks_run_failed_when_unexpected_runtime_error_happens(): void
    {
        Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'credentials' => [],
            'bot_token_present' => false,
            'is_active' => true,
        ]);

        $this->app->bind(
            RecordChannelConnectionCheckRunAction::class,
            fn (): RecordChannelConnectionCheckRunAction => new class extends RecordChannelConnectionCheckRunAction
            {
                public function finish(
                    ChannelConnectionCheckRun $run,
                    int $processedCount,
                    int $successCount,
                    int $failureCount,
                    ?string $lastErrorCode = null,
                    ?string $lastErrorMessage = null,
                ): ChannelConnectionCheckRun {
                    throw new \RuntimeException('Unexpected finish failure');
                }
            },
        );

        $exitCode = Artisan::call('channels:check-connections');

        $this->assertSame(1, $exitCode);

        $run = ChannelConnectionCheckRun::query()->latest('id')->first();

        $this->assertInstanceOf(ChannelConnectionCheckRun::class, $run);
        $this->assertSame(ChannelConnectionCheckRun::STATUS_FAILED, $run->status);
        $this->assertSame(1, $run->processed_count);
        $this->assertSame(1, $run->success_count);
        $this->assertSame(1, $run->failure_count);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->finished_at);
        $this->assertSame('RuntimeException', $run->last_error_code);
        $this->assertSame('Unexpected finish failure', $run->last_error_message);
    }

    public function test_connection_checker_health_reports_missing_stale_and_fresh_scheduler_runs(): void
    {
        $resolver = app(ResolveChannelConnectionCheckerHealthAction::class);

        $missing = $resolver->handle();

        $this->assertSame('unknown', $missing['status']);
        $this->assertSame('warning', $missing['tone']);
        $this->assertTrue($missing['show_banner']);
        $this->assertNotNull($missing['stale_channel_reason']);

        $runningRun = ChannelConnectionCheckRun::query()->create([
            'started_at' => now()->subMinute(),
            'finished_at' => null,
            'status' => ChannelConnectionCheckRun::STATUS_STARTED,
            'processed_count' => 0,
            'success_count' => 0,
            'failure_count' => 0,
            'environment' => app()->environment(),
        ]);

        $running = $resolver->handle();

        $this->assertSame('running', $running['status']);
        $this->assertSame('warning', $running['tone']);
        $this->assertTrue($running['show_banner']);

        $runningRun->delete();

        ChannelConnectionCheckRun::query()->create([
            'started_at' => now()->subMinutes(35),
            'finished_at' => now()->subMinutes(31),
            'status' => ChannelConnectionCheckRun::STATUS_SUCCESS,
            'processed_count' => 2,
            'success_count' => 2,
            'failure_count' => 0,
            'environment' => app()->environment(),
        ]);

        $critical = $resolver->handle();

        $this->assertSame('critical', $critical['status']);
        $this->assertSame('danger', $critical['tone']);
        $this->assertTrue($critical['show_banner']);
        $this->assertSame(2, $critical['processed_count']);

        ChannelConnectionCheckRun::query()->create([
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'status' => ChannelConnectionCheckRun::STATUS_SUCCESS,
            'processed_count' => 3,
            'success_count' => 3,
            'failure_count' => 0,
            'environment' => app()->environment(),
        ]);

        $fresh = $resolver->handle();

        $this->assertSame('ok', $fresh['status']);
        $this->assertSame('success', $fresh['tone']);
        $this->assertFalse($fresh['show_banner']);
        $this->assertNull($fresh['stale_channel_reason']);
        $this->assertSame(3, $fresh['processed_count']);
    }

    public function test_connection_checker_health_keeps_fresh_successful_run_green_while_next_run_is_running(): void
    {
        $resolver = app(ResolveChannelConnectionCheckerHealthAction::class);

        ChannelConnectionCheckRun::query()->create([
            'started_at' => now()->subMinutes(2),
            'finished_at' => now()->subMinute(),
            'status' => ChannelConnectionCheckRun::STATUS_SUCCESS,
            'processed_count' => 3,
            'success_count' => 3,
            'failure_count' => 0,
            'environment' => app()->environment(),
        ]);

        ChannelConnectionCheckRun::query()->create([
            'started_at' => now(),
            'finished_at' => null,
            'status' => ChannelConnectionCheckRun::STATUS_STARTED,
            'processed_count' => 0,
            'success_count' => 0,
            'failure_count' => 0,
            'environment' => app()->environment(),
        ]);

        $health = $resolver->handle();

        $this->assertSame('ok', $health['status']);
        $this->assertSame('success', $health['tone']);
        $this->assertFalse($health['show_banner']);
        $this->assertNull($health['stale_channel_reason']);
        $this->assertSame(3, $health['processed_count']);
    }

    public function test_prune_connection_check_runs_command_deletes_only_records_older_than_retention(): void
    {
        $oldRun = ChannelConnectionCheckRun::query()->create([
            'started_at' => now()->subDays(45),
            'finished_at' => now()->subDays(45)->addMinute(),
            'status' => ChannelConnectionCheckRun::STATUS_SUCCESS,
            'processed_count' => 1,
            'success_count' => 1,
            'failure_count' => 0,
            'environment' => app()->environment(),
        ]);
        $oldRun->forceFill([
            'created_at' => now()->subDays(45),
            'updated_at' => now()->subDays(45),
        ])->saveQuietly();
        $recentRun = ChannelConnectionCheckRun::query()->create([
            'started_at' => now()->subDays(2),
            'finished_at' => now()->subDays(2)->addMinute(),
            'status' => ChannelConnectionCheckRun::STATUS_SUCCESS,
            'processed_count' => 2,
            'success_count' => 2,
            'failure_count' => 0,
            'environment' => app()->environment(),
        ]);
        $recentRun->forceFill([
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ])->saveQuietly();

        $exitCode = Artisan::call('channels:prune-connection-check-runs', ['--days' => 30]);

        $this->assertSame(0, $exitCode);
        $this->assertDatabaseMissing('channel_connection_check_runs', ['id' => $oldRun->id]);
        $this->assertDatabaseHas('channel_connection_check_runs', ['id' => $recentRun->id]);
        $this->assertStringContainsString('Удалено heartbeat-запусков проверки каналов: 1.', Artisan::output());
    }

    public function test_connection_check_migration_keeps_existing_active_supported_bots_sendable_until_first_check(): void
    {
        $migration = require database_path('migrations/2026_04_30_000000_add_connection_check_fields_to_channels_table.php');

        $activeBot = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'credentials' => ['token' => 'telegram-token'],
            'is_active' => true,
        ]);
        $inactiveBot = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'credentials' => ['token' => 'telegram-token'],
            'is_active' => false,
        ]);
        $missingTokenBot = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'credentials' => [],
            'bot_token_present' => false,
            'is_active' => true,
        ]);
        $activeMaxBot = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'credentials' => ['token' => 'max-token'],
            'is_active' => true,
        ]);

        $migration->down();
        $migration->up();

        $activeRow = DB::table('channels')->where('id', $activeBot->id)->first();
        $inactiveRow = DB::table('channels')->where('id', $inactiveBot->id)->first();
        $missingTokenRow = DB::table('channels')->where('id', $missingTokenBot->id)->first();
        $activeMaxRow = DB::table('channels')->where('id', $activeMaxBot->id)->first();

        $this->assertSame(Channel::CONNECTION_STATUS_CONNECTED, $activeRow->connection_status);
        $this->assertSame(Channel::WEBHOOK_STATUS_INSTALLED, $activeRow->webhook_status);
        $this->assertNotNull($activeRow->connection_checked_at);
        $this->assertNull($activeRow->connection_error_message);

        $this->assertSame(Channel::CONNECTION_STATUS_NOT_CONNECTED, $inactiveRow->connection_status);
        $this->assertSame(Channel::CONNECTION_ERROR_DISABLED, $inactiveRow->connection_error_message);

        $this->assertSame(Channel::CONNECTION_STATUS_NOT_CONNECTED, $missingTokenRow->connection_status);
        $this->assertSame(Channel::CONNECTION_ERROR_NO_TOKEN, $missingTokenRow->connection_error_message);

        $this->assertSame(Channel::CONNECTION_STATUS_CONNECTED, $activeMaxRow->connection_status);
        $this->assertSame(Channel::WEBHOOK_STATUS_INSTALLED, $activeMaxRow->webhook_status);
        $this->assertNotNull($activeMaxRow->connection_checked_at);
        $this->assertNull($activeMaxRow->connection_error_message);
    }

    public function test_reenabled_channel_is_marked_unchecked_until_next_connection_check(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'credentials' => ['token' => 'telegram-token'],
            'is_active' => false,
        ]);

        $channel->update(['is_active' => true]);

        $channel->refresh();

        $this->assertSame(Channel::CONNECTION_STATUS_NOT_CONNECTED, $channel->connection_status);
        $this->assertSame(Channel::WEBHOOK_STATUS_NOT_INSTALLED, $channel->webhook_status);
        $this->assertSame(Channel::CONNECTION_ERROR_NOT_CHECKED, $channel->connection_error_message);
        $this->assertNull($channel->connection_checked_at);
    }

    /**
     * @param  array<string, string>  $server
     */
    protected function bindRequest(string $url, array $server = []): void
    {
        $this->app->instance('request', Request::create($url, 'GET', [], [], [], $server));
    }
}
