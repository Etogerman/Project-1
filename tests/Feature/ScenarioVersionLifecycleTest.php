<?php

namespace Tests\Feature;

use App\Models\Scenario;
use App\Models\ScenarioVersion;
use App\Services\Scenarios\ArchiveScenarioAction;
use App\Services\Scenarios\CreateNextScenarioDraftAction;
use App\Services\Scenarios\CreateScenarioAction;
use App\Services\Scenarios\PublishScenarioVersionAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ScenarioVersionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_scenario_action_creates_initial_draft_version(): void
    {
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'needs_discovery_builder',
            'name' => 'Выявление потребностей v2',
        ]);

        $draftVersion = $scenario->fresh()->draftVersion;

        $this->assertInstanceOf(ScenarioVersion::class, $draftVersion);
        $this->assertSame(1, $draftVersion->version_number);
        $this->assertSame(ScenarioVersion::STATUS_DRAFT, $draftVersion->status);
        $this->assertSame([], $draftVersion->schema_payload);
        $this->assertNull($scenario->fresh()->publishedVersion);
    }

    public function test_publish_scenario_version_rejects_non_draft_version(): void
    {
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'warmup_builder',
            'name' => 'Прогрев v2',
        ]);

        $draftVersion = $scenario->fresh()->draftVersion;
        $draftVersion->forceFill([
            'schema_payload' => $this->sliceOneSchema('warmup_builder'),
        ])->save();
        $publishedVersion = app(PublishScenarioVersionAction::class)->handle($draftVersion);

        $this->expectException(ValidationException::class);

        app(PublishScenarioVersionAction::class)->handle($publishedVersion);
    }

    public function test_publish_scenario_version_rejects_empty_schema_payload(): void
    {
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'empty_builder',
            'name' => 'Пустой сценарий',
        ]);

        $this->expectException(ValidationException::class);

        app(PublishScenarioVersionAction::class)->handle($scenario->fresh()->draftVersion);
    }

    public function test_publish_scenario_version_archives_previous_published_version(): void
    {
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'warmup_builder',
            'name' => 'Прогрев v2',
        ]);

        $firstDraft = $scenario->fresh()->draftVersion;
        $firstDraft->forceFill([
            'schema_payload' => $this->sliceOneSchema('warmup_builder'),
        ])->save();

        $firstPublished = app(PublishScenarioVersionAction::class)->handle($firstDraft);

        $secondDraft = app(CreateNextScenarioDraftAction::class)->handle($scenario->fresh());
        $secondDraft->forceFill([
            'schema_payload' => $this->sliceOneSchema('warmup_builder', 'Какую тему обсудим?'),
        ])->save();

        $secondPublished = app(PublishScenarioVersionAction::class)->handle($secondDraft);

        $this->assertDatabaseHas('scenario_versions', [
            'id' => $firstPublished->id,
            'status' => ScenarioVersion::STATUS_ARCHIVED,
        ]);
        $this->assertDatabaseHas('scenario_versions', [
            'id' => $secondPublished->id,
            'status' => ScenarioVersion::STATUS_PUBLISHED,
        ]);
        $this->assertSame($secondPublished->id, $scenario->fresh()->publishedVersion?->id);
    }

    public function test_create_next_draft_copies_schema_from_published_version(): void
    {
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'needs_discovery_builder',
            'name' => 'Выявление потребностей v2',
        ]);

        $draftVersion = $scenario->fresh()->draftVersion;
        $draftVersion->forceFill([
            'schema_payload' => $this->sliceOneSchema('needs_discovery_builder'),
        ])->save();

        app(PublishScenarioVersionAction::class)->handle($draftVersion);

        $nextDraft = app(CreateNextScenarioDraftAction::class)->handle($scenario->fresh());

        $this->assertSame(2, $nextDraft->version_number);
        $this->assertSame(ScenarioVersion::STATUS_DRAFT, $nextDraft->status);
        $this->assertEquals($this->sliceOneSchema('needs_discovery_builder'), $nextDraft->schema_payload);
    }

    public function test_create_next_draft_rejects_when_draft_already_exists(): void
    {
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'needs_discovery_builder',
            'name' => 'Выявление потребностей v2',
        ]);

        $this->expectException(ValidationException::class);

        app(CreateNextScenarioDraftAction::class)->handle($scenario->fresh());
    }

    public function test_create_next_draft_rejects_when_no_published_version_exists(): void
    {
        $scenario = Scenario::query()->create([
            'code' => 'manual_scenario',
            'name' => 'Ручной сценарий',
            'is_active' => true,
            'is_archived' => false,
        ]);

        $this->expectException(ValidationException::class);

        app(CreateNextScenarioDraftAction::class)->handle($scenario);
    }

    public function test_archive_scenario_action_archives_and_disables_scenario(): void
    {
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'warmup_builder',
            'name' => 'Прогрев v2',
            'is_active' => true,
        ]);

        $archivedScenario = app(ArchiveScenarioAction::class)->handle($scenario);

        $this->assertTrue((bool) $archivedScenario->is_archived);
        $this->assertFalse((bool) $archivedScenario->is_active);
        $this->assertDatabaseHas('scenarios', [
            'id' => $scenario->id,
            'is_archived' => true,
            'is_active' => false,
        ]);
    }

    public function test_archived_scenario_cannot_publish_or_create_new_draft(): void
    {
        $scenario = app(CreateScenarioAction::class)->handle([
            'code' => 'warmup_builder',
            'name' => 'Прогрев v2',
        ]);

        $archivedScenario = app(ArchiveScenarioAction::class)->handle($scenario);

        $this->expectException(ValidationException::class);

        app(CreateNextScenarioDraftAction::class)->handle($archivedScenario);
    }

    /**
     * @return array<string, mixed>
     */
    private function sliceOneSchema(string $triggerValue, string $questionText = 'Как вас зовут?'): array
    {
        return [
            'version' => 1,
            'start_block_id' => 'welcome',
            'triggers' => [
                [
                    'type' => 'parameter',
                    'value' => $triggerValue,
                ],
            ],
            'blocks' => [
                'welcome' => [
                    'type' => 'message',
                    'text' => 'Добро пожаловать',
                    'text_format' => 'plain_text',
                    'next' => 'ask_name',
                ],
                'ask_name' => [
                    'type' => 'question',
                    'text' => $questionText,
                    'text_format' => 'plain_text',
                    'expects' => 'text',
                    'save_to' => 'run.first_name',
                    'next' => 'done',
                ],
                'done' => [
                    'type' => 'complete',
                ],
            ],
        ];
    }
}
