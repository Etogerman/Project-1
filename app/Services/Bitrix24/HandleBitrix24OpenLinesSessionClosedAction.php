<?php

namespace App\Services\Bitrix24;

use App\Models\Dialog;

class HandleBitrix24OpenLinesSessionClosedAction
{
    public function __construct(
        private readonly ResolveDialogByBitrix24LiveChatKeyAction $resolveDialogByBitrix24LiveChatKeyAction,
    ) {}

    public function handle(string $chatId): ?Dialog
    {
        try {
            $dialog = $this->resolveBitrix24LiveChatKeyAction($chatId);
        } catch (Bitrix24ApiException) {
            return null;
        }

        $dialog->forceFill([
            'bitrix24_live_status' => Dialog::BITRIX24_LIVE_STATUS_CLOSED,
        ])->save();

        return $dialog->fresh();
    }

    private function resolveBitrix24LiveChatKeyAction(string $chatId): Dialog
    {
        return $this->resolveDialogByBitrix24LiveChatKeyAction->handle($chatId);
    }
}
