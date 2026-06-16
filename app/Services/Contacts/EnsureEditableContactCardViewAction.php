<?php

namespace App\Services\Contacts;

use App\Models\CardView;
use App\Models\CardViewItem;
use App\Models\CardViewSection;
use App\Models\CardViewTab;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class EnsureEditableContactCardViewAction
{
    public function handle(): CardView
    {
        return DB::transaction(function (): CardView {
            app(SyncSystemContactCardViewAction::class)->handle();

            $editableView = CardView::query()
                ->where('entity', CardView::ENTITY_CONTACT)
                ->where('context', CardView::CONTEXT_CARD)
                ->where('view_key', SyncSystemContactCardViewAction::EDITABLE_VIEW_KEY)
                ->first();

            if ($editableView instanceof CardView) {
                $this->makeDefault($editableView);

                return $editableView->refresh();
            }

            $systemView = CardView::query()
                ->where('entity', CardView::ENTITY_CONTACT)
                ->where('context', CardView::CONTEXT_CARD)
                ->where('view_key', SyncSystemContactCardViewAction::VIEW_KEY)
                ->with('tabs.sections.items')
                ->first();

            if (! $systemView instanceof CardView) {
                throw new RuntimeException('Системный вид карточки контакта не найден.');
            }

            CardView::query()
                ->where('entity', CardView::ENTITY_CONTACT)
                ->where('context', CardView::CONTEXT_CARD)
                ->where('is_default', true)
                ->update(['is_default' => false]);

            $editableView = CardView::query()->create([
                'entity' => CardView::ENTITY_CONTACT,
                'context' => CardView::CONTEXT_CARD,
                'view_key' => SyncSystemContactCardViewAction::EDITABLE_VIEW_KEY,
                'name' => 'Карточка контакта',
                'is_system' => false,
                'scope' => CardView::SCOPE_SYSTEM,
                'is_default' => true,
            ]);

            $this->copyViewStructure($systemView, $editableView);

            return $editableView->refresh();
        });
    }

    private function makeDefault(CardView $view): void
    {
        CardView::query()
            ->where('entity', CardView::ENTITY_CONTACT)
            ->where('context', CardView::CONTEXT_CARD)
            ->where('is_default', true)
            ->whereKeyNot($view->getKey())
            ->update(['is_default' => false]);

        if (! $view->is_default) {
            $view->forceFill(['is_default' => true])->save();
        }
    }

    private function copyViewStructure(CardView $sourceView, CardView $targetView): void
    {
        foreach ($sourceView->tabs as $sourceTab) {
            $targetTab = CardViewTab::query()->create([
                'card_view_id' => $targetView->id,
                'tab_key' => $sourceTab->tab_key,
                'name' => $sourceTab->name,
                'sort_order' => $sourceTab->sort_order,
                'is_visible' => $sourceTab->is_visible,
                'is_system' => $sourceTab->is_system,
            ]);

            foreach ($sourceTab->sections as $sourceSection) {
                $targetSection = CardViewSection::query()->create([
                    'card_view_tab_id' => $targetTab->id,
                    'section_key' => $sourceSection->section_key,
                    'name' => $sourceSection->name,
                    'sort_order' => $sourceSection->sort_order,
                    'is_visible' => $sourceSection->is_visible,
                    'is_collapsed_by_default' => $sourceSection->is_collapsed_by_default,
                    'is_system' => $sourceSection->is_system,
                ]);

                foreach ($sourceSection->items as $sourceItem) {
                    CardViewItem::query()->create([
                        'card_view_section_id' => $targetSection->id,
                        'item_key' => $sourceItem->item_key,
                        'item_type' => $sourceItem->item_type,
                        'field_dictionary_field_id' => $sourceItem->field_dictionary_field_id,
                        'sort_order' => $sourceItem->sort_order,
                        'is_visible' => $sourceItem->is_visible,
                        'is_system' => $sourceItem->is_system,
                    ]);
                }
            }
        }
    }
}
