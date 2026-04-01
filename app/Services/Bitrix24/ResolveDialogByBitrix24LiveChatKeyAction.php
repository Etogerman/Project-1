<?php

namespace App\Services\Bitrix24;

use App\Models\Dialog;

class ResolveDialogByBitrix24LiveChatKeyAction
{
    public function handle(string $chatKey): Dialog
    {
        $normalizedChatKey = trim($chatKey);

        if (! str_starts_with($normalizedChatKey, 'abrikosoff-dialog:')) {
            throw new Bitrix24ApiException('Bitrix24 Open Lines chat id does not belong to Abrikosoff.');
        }

        $dialogId = (int) substr($normalizedChatKey, strlen('abrikosoff-dialog:'));

        if ($dialogId <= 0) {
            throw new Bitrix24ApiException('Bitrix24 Open Lines chat id does not contain a valid dialog id.');
        }

        $dialog = Dialog::query()
            ->with(['contact', 'channel', 'currentContactIdentity'])
            ->find($dialogId);

        if (! $dialog instanceof Dialog) {
            throw new Bitrix24ApiException('Bitrix24 Open Lines dialog anchor does not exist locally.');
        }

        if (
            filled($dialog->bitrix24_live_chat_id)
            && $dialog->bitrix24_live_chat_id !== $normalizedChatKey
        ) {
            throw new Bitrix24ApiException('Bitrix24 Open Lines dialog anchor does not match stored live chat id.');
        }

        return $dialog;
    }
}
