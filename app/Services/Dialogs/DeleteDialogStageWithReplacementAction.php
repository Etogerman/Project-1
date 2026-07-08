<?php

namespace App\Services\Dialogs;

use App\Models\Dialog;
use App\Models\DialogStage;
use App\Models\ScenarioBuilderBlock;
use App\Models\ScenarioVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteDialogStageWithReplacementAction
{
    private const STAGE_START_EVENTS = [
        'message_in_stage',
        'stage_changed',
    ];

    private const TRANSFERABLE_SCENARIO_VERSION_STATUSES = [
        ScenarioVersion::STATUS_DRAFT,
        ScenarioVersion::STATUS_PUBLISHED,
    ];

    /**
     * @return array{dialogs:int,scenario_references:int}
     */
    public function handle(DialogStage|int $stage, DialogStage|int $replacementStage): array
    {
        return DB::transaction(function () use ($stage, $replacementStage): array {
            $stage = DialogStage::query()
                ->lockForUpdate()
                ->findOrFail($stage instanceof DialogStage ? $stage->getKey() : $stage);

            $replacementStage = DialogStage::query()
                ->lockForUpdate()
                ->findOrFail($replacementStage instanceof DialogStage ? $replacementStage->getKey() : $replacementStage);

            if ($stage->isSystemDerivedStage()) {
                throw ValidationException::withMessages([
                    'stage' => 'Автоматическую системную стадию нельзя удалить.',
                ]);
            }

            if ((int) $stage->getKey() === (int) $replacementStage->getKey()) {
                throw ValidationException::withMessages([
                    'replacement_stage_id' => 'Нужно выбрать другую стадию для переноса диалогов.',
                ]);
            }

            $dialogsCount = Dialog::query()
                ->where(function ($query) use ($stage): void {
                    $query
                        ->where('stage_id', $stage->getKey())
                        ->orWhere('stage', $stage->key);
                })
                ->update([
                    'stage' => $replacementStage->key,
                    'stage_id' => $replacementStage->getKey(),
                ]);

            $scenarioReferencesCount = $this->replaceScenarioReferences($stage->key, $replacementStage->key);

            $stage->delete();

            return [
                'dialogs' => $dialogsCount,
                'scenario_references' => $scenarioReferencesCount,
            ];
        });
    }

    public function countScenarioReferences(DialogStage|string $stage): int
    {
        $stageKey = $stage instanceof DialogStage ? $stage->key : trim($stage);

        if ($stageKey === '') {
            return 0;
        }

        return $this->countScenarioBuilderBlockReferences($stageKey)
            + $this->countScenarioVersionRuntimeReferences($stageKey);
    }

    private function replaceScenarioReferences(string $fromStageKey, string $toStageKey): int
    {
        return $this->replaceScenarioBuilderBlockReferences($fromStageKey, $toStageKey)
            + $this->replaceScenarioVersionRuntimeReferences($fromStageKey, $toStageKey);
    }

    private function countScenarioBuilderBlockReferences(string $stageKey): int
    {
        return ScenarioBuilderBlock::query()
            ->whereHas('scenarioVersion', fn ($query) => $query->whereIn('status', self::TRANSFERABLE_SCENARIO_VERSION_STATUSES))
            ->get(['id', 'settings_payload'])
            ->sum(fn (ScenarioBuilderBlock $block): int => $this->countSettingsPayloadReferences($block->settings_payload, $stageKey));
    }

    private function countScenarioVersionRuntimeReferences(string $stageKey): int
    {
        return ScenarioVersion::query()
            ->whereIn('status', self::TRANSFERABLE_SCENARIO_VERSION_STATUSES)
            ->get(['id', 'schema_payload'])
            ->sum(fn (ScenarioVersion $version): int => $this->countSchemaPayloadReferences($version->schema_payload, $stageKey));
    }

    private function replaceScenarioBuilderBlockReferences(string $fromStageKey, string $toStageKey): int
    {
        $count = 0;

        ScenarioBuilderBlock::query()
            ->whereHas('scenarioVersion', fn ($query) => $query->whereIn('status', self::TRANSFERABLE_SCENARIO_VERSION_STATUSES))
            ->lockForUpdate()
            ->get(['id', 'settings_payload'])
            ->each(function (ScenarioBuilderBlock $block) use ($fromStageKey, $toStageKey, &$count): void {
                [$settingsPayload, $replacements] = $this->replaceSettingsPayloadReferences(
                    $block->settings_payload,
                    $fromStageKey,
                    $toStageKey,
                );

                if ($replacements === 0) {
                    return;
                }

                $block->settings_payload = $settingsPayload;
                $block->save();

                $count += $replacements;
            });

        return $count;
    }

    private function replaceScenarioVersionRuntimeReferences(string $fromStageKey, string $toStageKey): int
    {
        $count = 0;

        ScenarioVersion::query()
            ->whereIn('status', self::TRANSFERABLE_SCENARIO_VERSION_STATUSES)
            ->lockForUpdate()
            ->get()
            ->each(function (ScenarioVersion $version) use ($fromStageKey, $toStageKey, &$count): void {
                [$schemaPayload, $replacements] = $this->replaceSchemaPayloadReferences(
                    $version->schema_payload,
                    $fromStageKey,
                    $toStageKey,
                );

                if ($replacements === 0) {
                    return;
                }

                $version->schema_payload = $schemaPayload;
                $version->save();

                $count += $replacements;
            });

        return $count;
    }

    /**
     * @param  array<string, mixed>|null  $settingsPayload
     */
    private function countSettingsPayloadReferences(?array $settingsPayload, string $stageKey): int
    {
        return $this->replaceSettingsPayloadReferences($settingsPayload, $stageKey, $stageKey)[1];
    }

    /**
     * @param  array<string, mixed>|null  $schemaPayload
     */
    private function countSchemaPayloadReferences(?array $schemaPayload, string $stageKey): int
    {
        return $this->replaceSchemaPayloadReferences($schemaPayload, $stageKey, $stageKey)[1];
    }

    /**
     * @param  array<string, mixed>|null  $settingsPayload
     * @return array{0:array<string, mixed>,1:int}
     */
    private function replaceSettingsPayloadReferences(?array $settingsPayload, string $fromStageKey, string $toStageKey): array
    {
        $settingsPayload ??= [];
        $modules = $settingsPayload['modules'] ?? [];

        if (! is_array($modules)) {
            return [$settingsPayload, 0];
        }

        $count = 0;

        foreach ($modules as $index => $module) {
            if (! is_array($module) || ($module['type'] ?? null) !== 'start_condition') {
                continue;
            }

            $payload = is_array($module['payload'] ?? null) ? $module['payload'] : [];
            $startEvent = trim((string) ($payload['start_event'] ?? 'message'));

            if (! in_array($startEvent, self::STAGE_START_EVENTS, true)) {
                continue;
            }

            if (trim((string) ($payload['stage_key'] ?? '')) !== $fromStageKey) {
                continue;
            }

            $payload['stage_key'] = $toStageKey;
            $module['payload'] = $payload;
            $modules[$index] = $module;
            $count++;
        }

        if ($count > 0) {
            $settingsPayload['modules'] = $modules;
        }

        return [$settingsPayload, $count];
    }

    /**
     * @param  array<string, mixed>|null  $schemaPayload
     * @return array{0:array<string, mixed>,1:int}
     */
    private function replaceSchemaPayloadReferences(?array $schemaPayload, string $fromStageKey, string $toStageKey): array
    {
        $schemaPayload ??= [];
        $entrypoints = data_get($schemaPayload, 'builder_v3_runtime.entrypoints', []);

        if (! is_array($entrypoints)) {
            return [$schemaPayload, 0];
        }

        $count = 0;

        foreach ($entrypoints as $index => $entrypoint) {
            if (! is_array($entrypoint)) {
                continue;
            }

            $event = trim((string) ($entrypoint['event'] ?? 'message'));

            if (! in_array($event, self::STAGE_START_EVENTS, true)) {
                continue;
            }

            if (trim((string) ($entrypoint['stage_key'] ?? '')) !== $fromStageKey) {
                continue;
            }

            $entrypoint['stage_key'] = $toStageKey;
            $entrypoints[$index] = $entrypoint;
            $count++;
        }

        if ($count > 0) {
            data_set($schemaPayload, 'builder_v3_runtime.entrypoints', $entrypoints);
        }

        return [$schemaPayload, $count];
    }
}
