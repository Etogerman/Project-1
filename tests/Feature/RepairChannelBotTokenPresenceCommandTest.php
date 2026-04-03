<?php

namespace Tests\Feature;

use App\Models\Channel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class RepairChannelBotTokenPresenceCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_reports_mismatches_without_writing(): void
    {
        [$matchingChannel, $mismatchedChannel] = $this->seedChannelFixture();

        $this->artisan('channels:repair-token-presence')
            ->expectsOutput('Channel bot token presence repair dry-run completed.')
            ->expectsTable(
                ['Metric', 'Count'],
                [
                    ['total_channels', '2'],
                    ['matching_channels', '1'],
                    ['mismatched_channels', '1'],
                    ['updated_channels', '0'],
                ],
            )
            ->assertSuccessful();

        $this->assertTrue($matchingChannel->fresh()->bot_token_present);
        $this->assertFalse($mismatchedChannel->fresh()->bot_token_present);
    }

    public function test_force_updates_desynced_rows(): void
    {
        [, $mismatchedChannel] = $this->seedChannelFixture();

        $this->artisan('channels:repair-token-presence', ['--force' => true])
            ->expectsOutput('Channel bot token presence repair completed.')
            ->assertSuccessful();

        $this->assertTrue($mismatchedChannel->fresh()->bot_token_present);
        $this->assertTrue($mismatchedChannel->fresh()->hasBotTokenConfigured());
    }

    public function test_force_leaves_already_synced_rows_untouched(): void
    {
        [$matchingChannel] = $this->seedChannelFixture();

        $beforeUpdatedAt = $matchingChannel->fresh()->updated_at;

        $this->artisan('channels:repair-token-presence', ['--force' => true])
            ->assertSuccessful();

        $matchingChannel->refresh();

        $this->assertTrue($matchingChannel->bot_token_present);
        $this->assertTrue($matchingChannel->hasBotTokenConfigured());
        $this->assertTrue($matchingChannel->updated_at?->equalTo($beforeUpdatedAt));
    }

    public function test_command_logs_summary_event(): void
    {
        Log::spy();

        $this->seedChannelFixture();

        $this->artisan('channels:repair-token-presence', ['--force' => true])
            ->assertSuccessful();

        Log::shouldHaveReceived('info')
            ->once()
            ->with('channels.bot_token_presence_repaired', \Mockery::on(function (array $context): bool {
                return $context['environment'] === app()->environment()
                    && $context['dry_run'] === false
                    && $context['force'] === true
                    && $context['total_channels'] === 2
                    && $context['matching_channels'] === 1
                    && $context['mismatched_channels'] === 1
                    && $context['updated_channels'] === 1
                    && is_string($context['driver'])
                    && is_string($context['repaired_at']);
            }));
    }

    /**
     * @return array{0: Channel, 1: Channel}
     */
    private function seedChannelFixture(): array
    {
        $matchingChannel = Channel::factory()->create([
            'credentials' => ['token' => 'matching-token'],
            'bot_token_present' => true,
        ]);

        $mismatchedChannel = Channel::factory()->create([
            'credentials' => ['token' => 'desynced-token'],
            'bot_token_present' => true,
        ]);

        DB::table('channels')
            ->where('id', $mismatchedChannel->id)
            ->update([
                'bot_token_present' => false,
            ]);

        return [
            $matchingChannel,
            $mismatchedChannel->fresh(),
        ];
    }
}
