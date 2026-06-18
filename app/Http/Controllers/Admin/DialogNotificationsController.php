<?php

namespace App\Http\Controllers\Admin;

use App\Models\Dialog;
use App\Models\User;
use App\Services\Dialogs\BuildDialogNotificationStateAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DialogNotificationsController
{
    public function show(Request $request, BuildDialogNotificationStateAction $buildState): JsonResponse
    {
        $user = $this->authorizedUser($request);
        $initialize = $request->boolean('initialize');

        return response()->json($buildState->handle($user, $initialize));
    }

    public function update(Request $request, BuildDialogNotificationStateAction $buildState): JsonResponse
    {
        $user = $this->authorizedUser($request);
        $scope = $request->string('scope')->toString();

        return response()->json($buildState->setScope($user, $scope));
    }

    public function markRead(Request $request, BuildDialogNotificationStateAction $buildState): JsonResponse
    {
        $user = $this->authorizedUser($request);
        $messageId = $request->filled('message_id')
            ? max(0, (int) $request->input('message_id'))
            : null;

        return response()->json($buildState->markRead($user, $messageId));
    }

    private function authorizedUser(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->can('viewAny', Dialog::class), 403);

        return $user;
    }
}
