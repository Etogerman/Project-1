<?php

namespace App\Services\Bots;

use App\Jobs\ProcessDeferredParameterAutoReplyJob;
use App\Models\Dialog;

class QueueDeferredParameterAutoReplyAction
{
    public function handle(Dialog|int $dialog): bool
    {
        $dialog = $dialog instanceof Dialog
            ? $dialog
            : Dialog::query()->findOrFail($dialog);

        if (! filled($dialog->pending_auto_reply_source_message_id)) {
            return false;
        }

        ProcessDeferredParameterAutoReplyJob::dispatch($dialog->id)->afterCommit();

        return true;
    }
}
