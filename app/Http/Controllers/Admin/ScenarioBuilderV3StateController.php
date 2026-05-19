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
use Illuminate\Validation\ValidationException;

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
        $scheduledTransitionPolicy = trim((string) $request->input('scheduled_transition_policy', ''));

        if (
            $scheduledTransitionPolicy !== ''
            && ! in_array($scheduledTransitionPolicy, [
                PublishScenarioBuilderV3Action::SCHEDULED_TRANSITIONS_CANCEL,
                PublishScenarioBuilderV3Action::SCHEDULED_TRANSITIONS_KEEP,
            ], true)
        ) {
            throw ValidationException::withMessages([
                'scheduled_transition_policy' => 'Неизвестное действие для запланированных переходов.',
            ]);
        }

        $scheduledTransitions = $publishScenarioBuilderV3Action->pendingScheduledTransitionsSummary($scenario);

        if ($scheduledTransitions['count'] > 0 && $scheduledTransitionPolicy === '') {
            return response()->json([
                'message' => 'Есть запланированные переходы. Выберите, сохранить их или отменить при публикации.',
                'code' => 'scheduled_transitions_pending',
                'warning' => [
                    'scheduled_transitions' => $scheduledTransitions,
                ],
            ], 409);
        }

        $result = $publishScenarioBuilderV3Action->handle(
            $scenario,
            $draftVersionId,
            $baseRevision,
            $user,
            $scheduledTransitionPolicy !== ''
                ? $scheduledTransitionPolicy
                : PublishScenarioBuilderV3Action::SCHEDULED_TRANSITIONS_KEEP,
        );
        $state = $buildScenarioBuilderV3StateAction->handle(
            $scenario->fresh(['draftVersion', 'publishedVersion']),
            $user,
        );
        $state['published'] = [
            'version_id' => (int) $result['published_version']->id,
            'version_number' => (int) $result['published_version']->version_number,
            'cancelled_scheduled_transitions' => (int) $result['cancelled_scheduled_transitions'],
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
