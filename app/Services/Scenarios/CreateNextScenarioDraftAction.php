<?php

namespace App\Services\Scenarios;

use App\Models\Scenario;
use App\Models\ScenarioVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateNextScenarioDraftAction
{
    public function handle(Scenario $scenario): ScenarioVersion
    {
        if ((bool) $scenario->is_archived) {
            throw ValidationException::withMessages([
                'scenario' => 'Нельзя создавать черновик для архивного сценария.',
            ]);
        }

        if ($scenario->draftVersion()->exists()) {
            throw ValidationException::withMessages([
                'scenario' => 'У сценария уже есть активный черновик.',
            ]);
        }

        $publishedVersion = $scenario->publishedVersion()->first();

        if (! $publishedVersion instanceof ScenarioVersion) {
            throw ValidationException::withMessages([
                'scenario' => 'Нельзя создать следующий черновик без опубликованной версии.',
            ]);
        }

        return DB::transaction(function () use ($scenario, $publishedVersion): ScenarioVersion {
            $nextVersionNumber = (int) $scenario->versions()->max('version_number') + 1;

            return ScenarioVersion::query()->create([
                'scenario_id' => $scenario->id,
                'version_number' => $nextVersionNumber,
                'status' => ScenarioVersion::STATUS_DRAFT,
                'schema_payload' => $publishedVersion->schema_payload,
            ]);
        });
    }
}
