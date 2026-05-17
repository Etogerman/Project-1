<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Scenario;
use App\Models\User;
use App\Services\Scenarios\BuildScenarioBuilderV3StateAction;
use App\Services\Scenarios\PublishScenarioBuilderV3Action;
use App\Services\Scenarios\SaveScenarioBuilderV3StateAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScenarioBuilderV3StateController extends Controller
{
    public function show(
        Request $request,
        Scenario $scenario,
        BuildScenarioBuilderV3StateAction $buildScenarioBuilderV3StateAction,
    ): JsonResponse {
        $user = $this->authorizeScenarioBuilderAccess($request, $scenario);

        return response()->json($buildScenarioBuilderV3StateAction->handle($scenario, $user));
    }

    public function update(
        Request $request,
        Scenario $scenario,
        SaveScenarioBuilderV3StateAction $saveScenarioBuilderV3StateAction,
    ): JsonResponse {
        $this->authorizeScenarioBuilderAccess($request, $scenario);

        return response()->json($saveScenarioBuilderV3StateAction->handle($scenario, $request->all()));
    }

    public function publish(
        Request $request,
        Scenario $scenario,
        PublishScenarioBuilderV3Action $publishScenarioBuilderV3Action,
        BuildScenarioBuilderV3StateAction $buildScenarioBuilderV3StateAction,
    ): JsonResponse {
        $user = $this->authorizeScenarioBuilderAccess($request, $scenario);
        $draftVersionId = (int) ($request->integer('draft_version_id') ?: $request->integer('editable_version_id'));
        $baseRevision = trim((string) $request->input('base_revision', ''));

        $result = $publishScenarioBuilderV3Action->handle($scenario, $draftVersionId, $baseRevision, $user);
        $state = $buildScenarioBuilderV3StateAction->handle(
            $scenario->fresh(['draftVersion', 'publishedVersion']),
            $user,
        );
        $state['published'] = [
            'version_id' => (int) $result['published_version']->id,
            'version_number' => (int) $result['published_version']->version_number,
        ];

        return response()->json($state);
    }

    private function authorizeScenarioBuilderAccess(Request $request, Scenario $scenario): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);
        abort_unless($user->hasRolePermission('scenarios.edit'), 403);
        abort_unless($user->can('update', $scenario), 403);

        return $user;
    }
}
