<?php

namespace App\Services\CardViews;

use App\Models\FieldDictionaryField;
use App\Services\Contacts\SyncSystemContactCardViewAction;
use App\Services\Dialogs\SyncSystemDialogCardViewAction;

class CardViewFieldRendererRegistry
{
    public const CONTACT_PHONE_LIST = 'contact_phone_list';

    public const CONTACT_EMAIL_LIST = 'contact_email_list';

    public const CONTACT_TAG_LIST = 'contact_tag_list';

    public const DIALOG_PEER_SYNC = 'dialog_peer_sync';

    /**
     * @return array<string, array{renderer_key:string, legacy_block_key:string}>
     */
    private function contactRenderersByDisplayType(): array
    {
        return [
            FieldDictionaryField::CARD_DISPLAY_PHONE_LIST => [
                'renderer_key' => self::CONTACT_PHONE_LIST,
                'legacy_block_key' => SyncSystemContactCardViewAction::BLOCK_CONTACT_PHONES,
            ],
            FieldDictionaryField::CARD_DISPLAY_EMAIL_LIST => [
                'renderer_key' => self::CONTACT_EMAIL_LIST,
                'legacy_block_key' => SyncSystemContactCardViewAction::BLOCK_CONTACT_EMAILS,
            ],
            FieldDictionaryField::CARD_DISPLAY_TAG_LIST => [
                'renderer_key' => self::CONTACT_TAG_LIST,
                'legacy_block_key' => SyncSystemContactCardViewAction::BLOCK_CONTACT_TAGS,
            ],
        ];
    }

    /**
     * @return array<string, array{renderer_key:string, legacy_block_key:string}>
     */
    private function dialogRenderersByDisplayType(): array
    {
        return [
            FieldDictionaryField::CARD_DISPLAY_DIALOG_PEER_SYNC => [
                'renderer_key' => self::DIALOG_PEER_SYNC,
                'legacy_block_key' => SyncSystemDialogCardViewAction::BLOCK_DIALOG_PEER_SYNC,
            ],
        ];
    }

    public function hasCustomRenderer(?FieldDictionaryField $field): bool
    {
        return $this->rendererKeyForField($field) !== null;
    }

    public function rendererKeyForField(?FieldDictionaryField $field): ?string
    {
        if (! $field instanceof FieldDictionaryField) {
            return null;
        }

        return $this->definitionForField($field)['renderer_key'] ?? null;
    }

    public function legacyBlockKeyForField(?FieldDictionaryField $field): ?string
    {
        if (! $field instanceof FieldDictionaryField) {
            return null;
        }

        return $this->definitionForField($field)['legacy_block_key'] ?? null;
    }

    /**
     * @return ?array{renderer_key:string, legacy_block_key:string}
     */
    private function definitionForField(FieldDictionaryField $field): ?array
    {
        $displayType = (string) ($field->card_display_type ?? FieldDictionaryField::CARD_DISPLAY_VALUE);

        return match ($field->entity) {
            FieldDictionaryField::ENTITY_CONTACT => $this->contactRenderersByDisplayType()[$displayType] ?? null,
            FieldDictionaryField::ENTITY_DIALOG => $this->dialogRenderersByDisplayType()[$displayType] ?? null,
            default => null,
        };
    }
}
