<?php

namespace App\Services\Bitrix24;

use App\Models\Dialog;

class ResolveBitrix24LiveChatKeyAction
{
    public function handle(Dialog|int $dialog): string
    {
        $dialogId = $dialog instanceof Dialog
            ? $dialog->id
            : (int) $dialog;

        if ($dialogId <= 0) {
            throw new Bitrix24ApiException('Bitrix24 Open Lines chat key requires a valid dialog id.');
        }

        return 'abrikosoff-dialog:'.$dialogId;
    }
}
