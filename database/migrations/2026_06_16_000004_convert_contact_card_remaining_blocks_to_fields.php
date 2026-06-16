<?php

use App\Models\CardView;
use App\Models\CardViewItem;
use App\Models\FieldDictionaryField;
use App\Services\Contacts\SyncSystemContactCardViewAction;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    private array $blockFieldMap = [
        SyncSystemContactCardViewAction::BLOCK_CONTACT_DIALOGS => 'contact_dialogs',
        SyncSystemContactCardViewAction::BLOCK_CONTACT_HISTORY => 'contact_history',
        SyncSystemContactCardViewAction::BLOCK_CONTACT_DEDUP => 'contact_dedup',
        SyncSystemContactCardViewAction::BLOCK_CONTACT_DIAGNOSTICS => 'contact_diagnostics',
    ];

    public function up(): void
    {
        FieldDictionaryField::syncSystemDefinitions();

        $fields = FieldDictionaryField::query()
            ->where('entity', FieldDictionaryField::ENTITY_CONTACT)
            ->whereIn('field_key', array_values($this->blockFieldMap))
            ->get()
            ->keyBy('field_key');

        CardViewItem::query()
            ->where('item_type', CardViewItem::TYPE_BLOCK)
            ->whereIn('item_key', array_keys($this->blockFieldMap))
            ->whereHas('section.tab.view', fn ($query) => $query->where('entity', CardView::ENTITY_CONTACT))
            ->with('section.tab.view')
            ->get()
            ->each(function (CardViewItem $item) use ($fields): void {
                $fieldKey = $this->blockFieldMap[(string) $item->item_key] ?? null;
                $field = $fieldKey !== null ? $fields->get($fieldKey) : null;

                if (! $field instanceof FieldDictionaryField || ! $item->section?->tab?->view instanceof CardView) {
                    return;
                }

                $duplicate = CardViewItem::query()
                    ->where('item_key', $fieldKey)
                    ->whereHas('section.tab.view', fn ($query) => $query->whereKey($item->section->tab->view->getKey()))
                    ->whereKeyNot($item->getKey())
                    ->first();

                if ($duplicate instanceof CardViewItem) {
                    $item->delete();

                    return;
                }

                $item->forceFill([
                    'item_key' => $fieldKey,
                    'item_type' => CardViewItem::TYPE_FIELD,
                    'field_dictionary_field_id' => $field->id,
                    'is_system' => (bool) $item->is_system || (bool) $field->is_system,
                ])->save();
            });
    }

    public function down(): void
    {
        $fieldBlockMap = array_flip($this->blockFieldMap);

        CardViewItem::query()
            ->where('item_type', CardViewItem::TYPE_FIELD)
            ->whereIn('item_key', array_keys($fieldBlockMap))
            ->whereHas('section.tab.view', fn ($query) => $query->where('entity', CardView::ENTITY_CONTACT))
            ->get()
            ->each(function (CardViewItem $item) use ($fieldBlockMap): void {
                $blockKey = $fieldBlockMap[(string) $item->item_key] ?? null;

                if ($blockKey === null) {
                    return;
                }

                $item->forceFill([
                    'item_key' => $blockKey,
                    'item_type' => CardViewItem::TYPE_BLOCK,
                    'field_dictionary_field_id' => null,
                ])->save();
            });
    }
};
