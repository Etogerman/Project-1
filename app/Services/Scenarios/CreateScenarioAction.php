<?php

namespace App\Services\Scenarios;

use App\Models\Scenario;
use App\Models\ScenarioVersion;
use Illuminate\Support\Facades\DB;

class CreateScenarioAction
{
    /**
     * @param  array{name: string, code: string, is_active?: bool}  $data
     */
    public function handle(array $data): Scenario
    {
        $scenario = DB::transaction(function () use ($data): Scenario {
            $scenario = Scenario::query()->create([
                'code' => $data['code'],
                'name' => $data['name'],
                'is_active' => (bool) ($data['is_active'] ?? true),
                'is_archived' => false,
            ]);

            ScenarioVersion::query()->create([
                'scenario_id' => $scenario->id,
                'version_number' => 1,
                'status' => ScenarioVersion::STATUS_DRAFT,
                'schema_payload' => [],
            ]);

            return $scenario->fresh(['draftVersion', 'publishedVersion']);
        });

        app(ScenarioRegistry::class)->forgetCachedDefinitions();

        return $scenario;
    }
}
