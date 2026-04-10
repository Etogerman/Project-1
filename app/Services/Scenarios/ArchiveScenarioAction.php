<?php

namespace App\Services\Scenarios;

use App\Models\Scenario;

class ArchiveScenarioAction
{
    public function handle(Scenario $scenario): Scenario
    {
        $scenario->forceFill([
            'is_active' => false,
            'is_archived' => true,
        ])->save();

        app(ScenarioRegistry::class)->forgetCachedDefinitions();

        return $scenario->fresh(['draftVersion', 'publishedVersion']);
    }
}
