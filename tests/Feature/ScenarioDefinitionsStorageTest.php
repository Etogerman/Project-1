<?php

namespace Tests\Feature;

use App\Models\Scenario;
use App\Models\ScenarioVersion;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ScenarioDefinitionsStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_scenario_definition_storage_tables_and_columns_exist(): void
    {
        $this->assertTrue(Schema::hasTable('scenarios'));
        $this->assertTrue(Schema::hasColumns('scenarios', [
            'code',
            'name',
            'is_active',
            'is_archived',
        ]));

        $this->assertTrue(Schema::hasTable('scenario_versions'));
        $this->assertTrue(Schema::hasColumns('scenario_versions', [
            'scenario_id',
            'version_number',
            'status',
            'schema_payload',
        ]));
    }

    public function test_scenario_can_be_created_and_normalizes_code_and_name(): void
    {
        $scenario = Scenario::query()->create([
            'code' => '  needs_discovery_v2  ',
            'name' => '  Выявление потребностей  ',
            'is_active' => true,
            'is_archived' => false,
        ]);

        $this->assertDatabaseHas('scenarios', [
            'id' => $scenario->id,
            'code' => 'needs_discovery_v2',
            'name' => 'Выявление потребностей',
            'is_active' => true,
            'is_archived' => false,
        ]);
    }

    public function test_scenario_code_is_unique(): void
    {
        Scenario::query()->create([
            'code' => 'warmup_builder',
            'name' => 'Прогрев v2',
            'is_active' => true,
            'is_archived' => false,
        ]);

        $this->expectException(QueryException::class);

        Scenario::query()->create([
            'code' => 'warmup_builder',
            'name' => 'Другой сценарий',
            'is_active' => true,
            'is_archived' => false,
        ]);
    }

    public function test_scenario_code_cannot_reuse_built_in_runtime_code(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Код сценария зарезервирован встроенным сценарием.');

        Scenario::query()->create([
            'code' => 'warmup',
            'name' => 'Прогрев через БД',
            'is_active' => true,
            'is_archived' => false,
        ]);
    }

    public function test_scenario_code_cannot_be_changed_after_creation(): void
    {
        $scenario = Scenario::query()->create([
            'code' => 'warmup_builder',
            'name' => 'Прогрев v2',
            'is_active' => true,
            'is_archived' => false,
        ]);

        $this->expectException(ValidationException::class);

        $scenario->forceFill([
            'code' => 'different_code',
        ])->save();
    }

    public function test_scenario_version_can_be_created_for_scenario(): void
    {
        $scenario = Scenario::query()->create([
            'code' => 'needs_discovery_builder',
            'name' => 'Выявление потребностей v2',
            'is_active' => true,
            'is_archived' => false,
        ]);

        $version = ScenarioVersion::query()->create([
            'scenario_id' => $scenario->id,
            'version_number' => 1,
            'status' => ScenarioVersion::STATUS_DRAFT,
            'schema_payload' => [
                'steps' => [],
            ],
        ]);

        $this->assertDatabaseHas('scenario_versions', [
            'id' => $version->id,
            'scenario_id' => $scenario->id,
            'version_number' => 1,
            'status' => ScenarioVersion::STATUS_DRAFT,
        ]);
        $this->assertSame(['steps' => []], $version->fresh()->schema_payload);
    }

    public function test_scenario_version_is_unique_per_scenario_and_version_number(): void
    {
        $scenario = Scenario::query()->create([
            'code' => 'warmup_builder',
            'name' => 'Прогрев v2',
            'is_active' => true,
            'is_archived' => false,
        ]);

        ScenarioVersion::query()->create([
            'scenario_id' => $scenario->id,
            'version_number' => 1,
            'status' => ScenarioVersion::STATUS_DRAFT,
            'schema_payload' => [],
        ]);

        $this->expectException(QueryException::class);

        ScenarioVersion::query()->create([
            'scenario_id' => $scenario->id,
            'version_number' => 1,
            'status' => ScenarioVersion::STATUS_PUBLISHED,
            'schema_payload' => [],
        ]);
    }

    public function test_scenario_version_rejects_unknown_status(): void
    {
        $scenario = Scenario::query()->create([
            'code' => 'warmup_builder',
            'name' => 'Прогрев v2',
            'is_active' => true,
            'is_archived' => false,
        ]);

        $this->expectException(ValidationException::class);

        ScenarioVersion::query()->create([
            'scenario_id' => $scenario->id,
            'version_number' => 1,
            'status' => 'unknown',
            'schema_payload' => [],
        ]);
    }

    public function test_published_version_relation_returns_latest_published_version(): void
    {
        $scenario = Scenario::query()->create([
            'code' => 'warmup_builder',
            'name' => 'Прогрев v2',
            'is_active' => true,
            'is_archived' => false,
        ]);

        ScenarioVersion::query()->create([
            'scenario_id' => $scenario->id,
            'version_number' => 1,
            'status' => ScenarioVersion::STATUS_ARCHIVED,
            'schema_payload' => [],
        ]);
        $publishedVersion = ScenarioVersion::query()->create([
            'scenario_id' => $scenario->id,
            'version_number' => 2,
            'status' => ScenarioVersion::STATUS_PUBLISHED,
            'schema_payload' => [
                'steps' => [],
            ],
        ]);

        $this->assertTrue($publishedVersion->isPublished());
        $this->assertSame($publishedVersion->id, $scenario->fresh()->publishedVersion?->id);
    }
}
