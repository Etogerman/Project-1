<?php

namespace App\Services\Contacts;

class ContactCardViewBlockRegistry
{
    /**
     * @return array<string, string>
     */
    public function options(): array
    {
        return [
            SyncSystemContactCardViewAction::BLOCK_CONTACT_PHONES => 'Телефоны',
            SyncSystemContactCardViewAction::BLOCK_CONTACT_EMAILS => 'Email',
            SyncSystemContactCardViewAction::BLOCK_CONTACT_TAGS => 'Теги',
            SyncSystemContactCardViewAction::BLOCK_CONTACT_DIALOGS => 'Диалоги',
            SyncSystemContactCardViewAction::BLOCK_CONTACT_DEDUP => 'Склейки',
            SyncSystemContactCardViewAction::BLOCK_CONTACT_HISTORY => 'История',
            SyncSystemContactCardViewAction::BLOCK_CONTACT_DIAGNOSTICS => 'Диагностика',
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
