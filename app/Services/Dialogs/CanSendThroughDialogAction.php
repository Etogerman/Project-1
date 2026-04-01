<?php

namespace App\Services\Dialogs;

use App\Models\Dialog;

class CanSendThroughDialogAction
{
    public function __construct(
        private readonly ResolveDialogRouteStatusAction $resolveDialogRouteStatusAction,
    ) {}

    public function handle(Dialog $dialog): bool
    {
        return $this->resolveDialogRouteStatusAction->handle($dialog)->isSendable;
    }
}
