<?php

use App\Models\CardView;
use App\Models\CardViewItem;
use App\Models\FieldDictionaryField;
use App\Services\Dialogs\SyncSystemDialogCardViewAction;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private string $blockKey = SyncSystemDialogCardViewAction::BLOCK_DIALOG_PEER_SYNC;

    private string $fieldKey = 'peer_sync';

    public function up(): void
    {
        FieldDictionaryField::syncSystemDefinitions();

        $field = FieldDictionaryField::query()
            ->where('entity', FieldDictionaryField::ENTITY_DIALOG)
            ->where('field_key', $this->fieldKey)
            ->first();

        if (! $field instanceof FieldDictionaryField) {
            return;
        }

        CardViewItem::query()
            ->where('item_type', CardViewItem::TYPE_BLOCK)
            ->where('item_key', $this->blockKey)
            ->whereHas('section.tab.view', fn ($query) => $query->where('entity', CardView::ENTITY_DIALOG))
            ->with('section.tab.view')
            ->get()
            ->each(function (CardViewItem $item) use ($field): void {
                if (! $item->section?->tab?->view instanceof CardView) {
                    return;
                }

                $duplicate = CardViewItem::query()
                    ->where('item_key', $this->fieldKey)
                    ->whereHas('section.tab.view', fn ($query) => $query->whereKey($item->section->tab->view->getKey()))
                    ->whereKeyNot($item->getKey())
                    ->first();

                if ($duplicate instanceof CardViewItem) {
                    $item->delete();

                    return;
                }

                $item->forceFill([
                    'item_key' => $this->fieldKey,
                    'item_type' => CardViewItem::TYPE_FIELD,
                    'field_dictionary_field_id' => $field->id,
                    'is_system' => (bool) $item->is_system || (bool) $field->is_system,
                ])->save();
            });
    }

    public function down(): void
    {
        CardViewItem::query()
            ->where('item_type', CardViewItem::TYPE_FIELD)
            ->where('item_key', $this->fieldKey)
            ->whereHas('section.tab.view', fn ($query) => $query->where('entity', CardView::ENTITY_DIALOG))
            ->get()
            ->each(function (CardViewItem $item): void {
                $item->forceFill([
                    'item_key' => $this->blockKey,
                    'item_type' => CardViewItem::TYPE_BLOCK,
                    'field_dictionary_field_id' => null,
                ])->save();
            });
    }
};
