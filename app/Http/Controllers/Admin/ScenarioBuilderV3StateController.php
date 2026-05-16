<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Scenario;
use App\Models\User;
use App\Services\Scenarios\BuildScenarioBuilderV3StateAction;
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

    private function authorizeScenarioBuilderAccess(Request $request, Scenario $scenario): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);
        abort_unless($user->hasRolePermission('scenarios.edit'), 403);
        abort_unless($user->can('update', $scenario), 403);

        return $user;
    }
}
