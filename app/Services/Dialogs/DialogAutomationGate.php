<?php

namespace App\Services\Dialogs;

use App\Models\Dialog;
use App\Models\Message;

class DialogAutomationGate
{
    public const REASON_BLACKLIST_STAGE = 'dialog_blacklist_stage';

    public function __construct(
        private readonly DialogStageCatalog $dialogStageCatalog,
    ) {}

    public function accepts(?Dialog $dialog): bool
    {
        return $this->rejectReason($dialog) === null;
    }

    public function acceptsMessage(Message $message): bool
    {
        $message->loadMissing('dialog.dialogStage');

        return $this->accepts($message->dialog);
    }

    public function rejectReason(?Dialog $dialog): ?string
    {
        if (! $dialog instanceof Dialog) {
            return null;
        }

        return $this->dialogStageCatalog->isBlacklistDialog($dialog)
            ? self::REASON_BLACKLIST_STAGE
            : null;
    }
}
