<?php

namespace App\Services\Dialogs;

class DialogCardViewBlockRegistry
{
    /**
     * @return array<string, string>
     */
    public function options(): array
    {
        return [
            SyncSystemDialogCardViewAction::SECTION_DIALOG_FIELDS => 'Поля диалога',
            SyncSystemDialogCardViewAction::BLOCK_DIALOG_PEER_SYNC => 'Загрузка истории',
        ];
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->options());
    }

    public function contains(string $blockKey): bool
    {
        return in_array($blockKey, $this->keys(), true);
    }

    public function label(string $blockKey): string
    {
        return $this->options()[$blockKey] ?? $blockKey;
    }
}
