<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Scenario;
use App\Models\ScenarioChannelBinding;
use App\Models\ScenarioRun;
use App\Models\ScenarioVersion;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ScenarioStorageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('scenarios', [
            'dummy' => \stdClass::class,
        ]);
    }

    public function test_scenario_storage_tables_and_columns_exist(): void
    {
        $this->assertTrue(Schema::hasTable('scenario_channel_bindings'));
        $this->assertTrue(Schema::hasColumns('scenario_channel_bindings', [
            'channel_id',
            'scenario_code',
            'is_active',
        ]));

        $this->assertTrue(Schema::hasTable('scenario_runs'));
        $this->assertTrue(Schema::hasColumns('scenario_runs', [
            'dialog_id',
            'scenario_code',
            'status',
            'current_step',
            'state_payload',
            'exit_outcome',
            'started_at',
            'finished_at',
        ]));
    }

    public function test_channel_binding_can_be_created_with_registered_scenario_code(): void
    {
        $channel = Channel::factory()->create();

        $binding = ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => 'dummy',
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('scenario_channel_bindings', [
            'id' => $binding->id,
            'channel_id' => $channel->id,
            'scenario_code' => 'dummy',
            'is_active' => true,
        ]);
    }

    public function test_channel_binding_rejects_unknown_scenario_code(): void
    {
        $channel = Channel::factory()->create();

        $this->expectException(ValidationException::class);

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => 'unknown',
            'is_active' => true,
        ]);
    }

    public function test_channel_binding_accepts_published_database_backed_scenario_code(): void
    {
        $channel = Channel::factory()->create();
        $scenario = $this->createPublishedScenario(code: 'vip_ibiza_apply');

        $binding = ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $scenario->code,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('scenario_channel_bindings', [
            'id' => $binding->id,
            'channel_id' => $channel->id,
            'scenario_code' => 'vip_ibiza_apply',
        ]);
    }

    public function test_channel_binding_is_unique_per_channel_and_scenario_code(): void
    {
        $channel = Channel::factory()->create();

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => 'dummy',
            'is_active' => true,
        ]);

        $this->expectException(QueryException::class);

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => 'dummy',
            'is_active' => false,
        ]);
    }

    public function test_run_can_be_created_with_registered_scenario_code(): void
    {
        $dialog = $this->createDialog();

        $run = ScenarioRun::query()->create([
            'dialog_id' => $dialog->id,
            'scenario_code' => 'dummy',
            'status' => ScenarioRun::STATUS_ACTIVE,
            'current_step' => 'intro',
            'state_payload' => ['foo' => 'bar'],
            'started_at' => Carbon::parse('2026-04-03 12:00:00'),
        ]);

        $this->assertDatabaseHas('scenario_runs', [
            'id' => $run->id,
            'dialog_id' => $dialog->id,
            'scenario_code' => 'dummy',
            'status' => ScenarioRun::STATUS_ACTIVE,
            'current_step' => 'intro',
            'exit_outcome' => null,
        ]);
        $this->assertSame(['foo' => 'bar'], $run->fresh()->state_payload);
    }

    public function test_run_rejects_unknown_scenario_code(): void
    {
        $dialog = $this->createDialog();

        $this->expectException(ValidationException::class);

        ScenarioRun::query()->create([
            'dialog_id' => $dialog->id,
            'scenario_code' => 'unknown',
            'status' => ScenarioRun::STATUS_ACTIVE,
            'started_at' => Carbon::parse('2026-04-03 12:00:00'),
        ]);
    }

    public function test_run_accepts_published_database_backed_scenario_code(): void
    {
        $dialog = $this->createDialog();
        $scenario = $this->createPublishedScenario(code: 'vip_ibiza_apply');

        $run = ScenarioRun::query()->create([
            'dialog_id' => $dialog->id,
            'scenario_code' => $scenario->code,
            'status' => ScenarioRun::STATUS_ACTIVE,
            'started_at' => Carbon::parse('2026-04-03 12:00:00'),
        ]);

        $this->assertDatabaseHas('scenario_runs', [
            'id' => $run->id,
            'dialog_id' => $dialog->id,
            'scenario_code' => 'vip_ibiza_apply',
            'status' => ScenarioRun::STATUS_ACTIVE,
        ]);
    }

    public function test_only_one_active_run_is_allowed_per_dialog(): void
    {
        $dialog = $this->createDialog();

        ScenarioRun::query()->create([
            'dialog_id' => $dialog->id,
            'scenario_code' => 'dummy',
            'status' => ScenarioRun::STATUS_ACTIVE,
            'started_at' => Carbon::parse('2026-04-03 12:00:00'),
        ]);

        $this->expectException(QueryException::class);

        ScenarioRun::query()->create([
            'dialog_id' => $dialog->id,
            'scenario_code' => 'dummy',
            'status' => ScenarioRun::STATUS_ACTIVE,
            'started_at' => Carbon::parse('2026-04-03 12:05:00'),
        ]);
    }

    public function test_new_active_run_can_be_created_after_previous_run_is_completed(): void
    {
        $dialog = $this->createDialog();

        $run = ScenarioRun::query()->create([
            'dialog_id' => $dialog->id,
            'scenario_code' => 'dummy',
            'status' => ScenarioRun::STATUS_ACTIVE,
            'started_at' => Carbon::parse('2026-04-03 12:00:00'),
        ]);

        $run->forceFill([
            'status' => ScenarioRun::STATUS_COMPLETED,
            'finished_at' => Carbon::parse('2026-04-03 12:03:00'),
        ])->save();

        $nextRun = ScenarioRun::query()->create([
            'dialog_id' => $dialog->id,
            'scenario_code' => 'dummy',
            'status' => ScenarioRun::STATUS_ACTIVE,
            'started_at' => Carbon::parse('2026-04-03 12:04:00'),
        ]);

        $this->assertDatabaseHas('scenario_runs', [
            'id' => $nextRun->id,
            'dialog_id' => $dialog->id,
            'status' => ScenarioRun::STATUS_ACTIVE,
        ]);
    }

    public function test_active_run_cannot_have_finished_at(): void
    {
        $dialog = $this->createDialog();

        $this->expectException(ValidationException::class);

        ScenarioRun::query()->create([
            'dialog_id' => $dialog->id,
            'scenario_code' => 'dummy',
            'status' => ScenarioRun::STATUS_ACTIVE,
            'started_at' => Carbon::parse('2026-04-03 12:00:00'),
            'finished_at' => Carbon::parse('2026-04-03 12:01:00'),
        ]);
    }

    public function test_active_run_cannot_have_exit_outcome(): void
    {
        $dialog = $this->createDialog();

        $this->expectException(ValidationException::class);

        ScenarioRun::query()->create([
            'dialog_id' => $dialog->id,
            'scenario_code' => 'dummy',
            'status' => ScenarioRun::STATUS_ACTIVE,
            'started_at' => Carbon::parse('2026-04-03 12:00:00'),
            'exit_outcome' => 'interrupted',
        ]);
    }

    public function test_terminal_run_requires_finished_at(): void
    {
        $dialog = $this->createDialog();

        $this->expectException(ValidationException::class);

        ScenarioRun::query()->create([
            'dialog_id' => $dialog->id,
            'scenario_code' => 'dummy',
            'status' => ScenarioRun::STATUS_COMPLETED,
            'started_at' => Carbon::parse('2026-04-03 12:00:00'),
        ]);
    }

    public function test_run_requires_started_at(): void
    {
        $dialog = $this->createDialog();

        $this->expectException(ValidationException::class);

        ScenarioRun::query()->create([
            'dialog_id' => $dialog->id,
            'scenario_code' => 'dummy',
            'status' => ScenarioRun::STATUS_ACTIVE,
        ]);
    }

    private function createDialog(): \App\Models\Dialog
    {
        return \App\Models\Dialog::factory()->create();
    }

    private function createPublishedScenario(string $code): Scenario
    {
        $scenario = Scenario::query()->create([
            'code' => $code,
            'name' => 'VIP Ibiza',
            'is_active' => true,
            'is_archived' => false,
        ]);

        ScenarioVersion::query()->create([
            'scenario_id' => $scenario->id,
            'version_number' => 1,
            'status' => ScenarioVersion::STATUS_PUBLISHED,
            'schema_payload' => [
                'version' => 1,
                'start_block_id' => 'welcome',
                'triggers' => [
                    [
                        'type' => 'parameter',
                        'value' => $code,
                    ],
                ],
                'blocks' => [
                    'welcome' => [
                        'type' => 'message',
                        'text' => 'Добро пожаловать',
                        'next' => 'end',
                    ],
                    'end' => [
                        'type' => 'complete',
                    ],
                ],
            ],
        ]);

        return $scenario->fresh('publishedVersion');
    }
}
