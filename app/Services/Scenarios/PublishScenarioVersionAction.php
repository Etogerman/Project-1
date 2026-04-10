<?php

namespace App\Services\Scenarios;

use App\Models\Scenario;
use App\Models\ScenarioVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PublishScenarioVersionAction
{
    public function __construct(
        private readonly ValidateScenarioSchemaPayloadAction $validateScenarioSchemaPayloadAction,
    ) {}

    public function handle(ScenarioVersion $version): ScenarioVersion
    {
        if (! $version->isDraft()) {
            throw ValidationException::withMessages([
                'version' => 'Публиковать можно только черновик сценария.',
            ]);
        }

        $scenario = $version->scenario()->first();

        if (! $scenario instanceof Scenario) {
            throw ValidationException::withMessages([
                'scenario' => 'Сценарий для версии не найден.',
            ]);
        }

        if ((bool) $scenario->is_archived) {
            throw ValidationException::withMessages([
                'scenario' => 'Нельзя публиковать версию архивного сценария.',
            ]);
        }

        $normalizedSchemaPayload = $this->validateScenarioSchemaPayloadAction->handle(
            is_array($version->schema_payload) ? $version->schema_payload : [],
        );

        $publishedVersion = DB::transaction(function () use ($version, $normalizedSchemaPayload): ScenarioVersion {
            ScenarioVersion::query()
                ->where('scenario_id', $version->scenario_id)
                ->where('status', ScenarioVersion::STATUS_PUBLISHED)
                ->update([
                    'status' => ScenarioVersion::STATUS_ARCHIVED,
                    'updated_at' => now(),
                ]);

            $version->forceFill([
                'schema_payload' => $normalizedSchemaPayload,
                'status' => ScenarioVersion::STATUS_PUBLISHED,
            ])->save();

            return $version->fresh();
        });

        app(ScenarioRegistry::class)->forgetCachedDefinitions();

        return $publishedVersion;
    }
}
