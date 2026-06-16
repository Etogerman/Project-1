<?php

namespace App\Services\Contacts;

use App\Models\CardView;
use App\Models\CardViewItem;
use App\Models\CardViewSection;
use App\Services\CardViews\CardViewFieldRendererRegistry;

class BuildContactCardViewLayoutAction
{
    /**
     * @return ?list<array{tab_key:string,title:string}>
     */
    public function tabs(): ?array
    {
        $view = CardView::query()
            ->where('entity', CardView::ENTITY_CONTACT)
            ->where('context', CardView::CONTEXT_CARD)
            ->where('is_default', true)
            ->with('visibleTabs')
            ->first();

        if (! $view instanceof CardView) {
            return null;
        }

        $tabs = $view->visibleTabs
            ->map(fn ($tab): array => [
                'tab_key' => (string) $tab->tab_key,
                'title' => (string) $tab->name,
            ])
            ->filter(fn (array $tab): bool => $tab['tab_key'] !== '')
            ->values()
            ->all();

        return $tabs !== [] ? $tabs : null;
    }

    /**
     * @return ?array{sections:list<array{section_key:string,title:string,fields:list<string>}>}
     */
    public function general(): ?array
    {
        return $this->tab(SyncSystemContactCardViewAction::TAB_GENERAL);
    }

    /**
     * @return ?array{sections:list<array{section_key:string,title:string,blocks:list<string>}>}
     */
    public function generalBlocks(): ?array
    {
        return $this->generalComplexFieldBlocks();
    }

    /**
     * @return ?array{sections:list<array{section_key:string,title:string,fields:list<string>}>}
     */
    public function bitrix24(): ?array
    {
        return $this->tab(SyncSystemContactCardViewAction::TAB_BITRIX24);
    }

    /**
     * @return ?array{sections:list<array{section_key:string,title:string,blocks:list<string>}>}
     */
    public function dialogs(): ?array
    {
        return $this->blockTab(SyncSystemContactCardViewAction::TAB_DIALOGS);
    }

    /**
     * @return ?array{sections:list<array{section_key:string,title:string,blocks:list<string>}>}
     */
    public function history(): ?array
    {
        return $this->blockTab(SyncSystemContactCardViewAction::TAB_HISTORY);
    }

    /**
     * @return ?array{sections:list<array{section_key:string,title:string,blocks:list<string>}>}
     */
    public function dedup(): ?array
    {
        return $this->blockTab(SyncSystemContactCardViewAction::TAB_DEDUP);
    }

    /**
     * @return ?array{sections:list<array{section_key:string,title:string,blocks:list<string>}>}
     */
    public function diagnostics(): ?array
    {
        return $this->blockTab(SyncSystemContactCardViewAction::TAB_DIAGNOSTICS);
    }

    /**
     * @return ?array{sections:list<array{section_key:string,title:string,fields:list<string>}>}
     */
    public function systemFields(): ?array
    {
        return $this->tab(SyncSystemContactCardViewAction::TAB_SYSTEM_FIELDS);
    }

    /**
     * @return ?array{sections:list<array{section_key:string,title:string,items:list<array{item_key:string,item_type:string,renderer_block_key:string}>}>}
     */
    public function itemsForTab(string $tabKey): ?array
    {
        $rendererRegistry = app(CardViewFieldRendererRegistry::class);

        $view = CardView::query()
            ->where('entity', CardView::ENTITY_CONTACT)
            ->where('context', CardView::CONTEXT_CARD)
            ->where('is_default', true)
            ->with([
                'visibleTabs' => fn ($query) => $query
                    ->where('tab_key', $tabKey)
                    ->with([
                        'visibleSections' => fn ($sectionQuery) => $sectionQuery
                            ->with(['visibleItems' => fn ($itemQuery) => $itemQuery->with('field')]),
                    ]),
            ])
            ->first();

        if (! $view instanceof CardView) {
            return null;
        }

        $tab = $view->visibleTabs->first();

        if ($tab === null) {
            return null;
        }

        $sections = $tab->visibleSections
            ->map(function (CardViewSection $section) use ($rendererRegistry): array {
                $items = $section->visibleItems
                    ->map(function (CardViewItem $item) use ($rendererRegistry): array {
                        $itemType = (string) $item->item_type;

                        return [
                            'item_key' => (string) $item->item_key,
                            'item_type' => $itemType,
                            'renderer_block_key' => $itemType === CardViewItem::TYPE_FIELD
                                ? ($rendererRegistry->legacyBlockKeyForField($item->field) ?? '')
                                : '',
                        ];
                    })
                    ->filter(fn (array $item): bool => ($item['item_key'] ?? '') !== '')
                    ->values()
                    ->all();

                return [
                    'section_key' => (string) $section->section_key,
                    'title' => (string) $section->name,
                    'items' => $items,
                ];
            })
            ->values()
            ->all();

        return ['sections' => $sections];
    }

    /**
     * @return ?array{sections:list<array{section_key:string,title:string,fields:list<string>}>}
     */
    private function tab(string $tabKey): ?array
    {
        $view = CardView::query()
            ->where('entity', CardView::ENTITY_CONTACT)
            ->where('context', CardView::CONTEXT_CARD)
            ->where('is_default', true)
            ->with([
                'visibleTabs' => fn ($query) => $query
                    ->where('tab_key', $tabKey)
                    ->with([
                        'visibleSections' => fn ($sectionQuery) => $sectionQuery
                            ->with([
                                'visibleItems' => fn ($itemQuery) => $itemQuery
                                    ->where('item_type', CardViewItem::TYPE_FIELD)
                                    ->with('field'),
                            ]),
                    ]),
            ])
            ->first();

        if (! $view instanceof CardView) {
            return null;
        }

        $tab = $view->visibleTabs->first();

        if ($tab === null) {
            return null;
        }

        $sections = $tab->visibleSections
            ->map(function (CardViewSection $section): array {
                $rendererRegistry = app(CardViewFieldRendererRegistry::class);
                $fields = $section->visibleItems
                    ->filter(fn (CardViewItem $item): bool => ! $rendererRegistry->hasCustomRenderer($item->field))
                    ->map(fn (CardViewItem $item): string => $item->field?->field_key ?? $item->item_key)
                    ->filter(fn (string $fieldKey): bool => $fieldKey !== '')
                    ->values()
                    ->all();

                return [
                    'section_key' => (string) $section->section_key,
                    'title' => (string) $section->name,
                    'fields' => $fields,
                ];
            })
            ->values()
            ->all();

        if ($sections === []) {
            return null;
        }

        return ['sections' => $sections];
    }

    /**
     * @return ?array{sections:list<array{section_key:string,title:string,blocks:list<string>}>}
     */
    private function generalComplexFieldBlocks(): ?array
    {
        $view = CardView::query()
            ->where('entity', CardView::ENTITY_CONTACT)
            ->where('context', CardView::CONTEXT_CARD)
            ->where('is_default', true)
            ->with([
                'visibleTabs' => fn ($query) => $query
                    ->where('tab_key', SyncSystemContactCardViewAction::TAB_GENERAL)
                    ->with([
                        'visibleSections' => fn ($sectionQuery) => $sectionQuery
                            ->with([
                                'visibleItems' => fn ($itemQuery) => $itemQuery->with('field'),
                            ]),
                    ]),
            ])
            ->first();

        if (! $view instanceof CardView) {
            return null;
        }

        $tab = $view->visibleTabs->first();

        if ($tab === null) {
            return null;
        }

        $sections = $tab->visibleSections
            ->map(function (CardViewSection $section): array {
                $blocks = $section->visibleItems
                    ->map(function (CardViewItem $item): string {
                        if ($item->item_type === CardViewItem::TYPE_BLOCK) {
                            return (string) $item->item_key;
                        }

                        return app(CardViewFieldRendererRegistry::class)->legacyBlockKeyForField($item->field) ?? '';
                    })
                    ->filter(fn (string $blockKey): bool => $blockKey !== '')
                    ->values()
                    ->all();

                return [
                    'section_key' => (string) $section->section_key,
                    'title' => (string) $section->name,
                    'blocks' => $blocks,
                ];
            })
            ->filter(fn (array $section): bool => ($section['blocks'] ?? []) !== [])
            ->values()
            ->all();

        return ['sections' => $sections];
    }

    /**
     * @return ?array{sections:list<array{section_key:string,title:string,blocks:list<string>}>}
     */
    private function blockTab(string $tabKey): ?array
    {
        $view = CardView::query()
            ->where('entity', CardView::ENTITY_CONTACT)
            ->where('context', CardView::CONTEXT_CARD)
            ->where('is_default', true)
            ->with([
                'visibleTabs' => fn ($query) => $query
                    ->where('tab_key', $tabKey)
                    ->with([
                        'visibleSections' => fn ($sectionQuery) => $sectionQuery
                            ->with([
                                'visibleItems' => fn ($itemQuery) => $itemQuery
                                    ->where('item_type', CardViewItem::TYPE_BLOCK),
                            ]),
                    ]),
            ])
            ->first();

        if (! $view instanceof CardView) {
            return null;
        }

        $tab = $view->visibleTabs->first();

        if ($tab === null) {
            return null;
        }

        $sections = $tab->visibleSections
            ->map(function (CardViewSection $section): array {
                $blocks = $section->visibleItems
                    ->map(fn (CardViewItem $item): string => (string) $item->item_key)
                    ->filter(fn (string $blockKey): bool => $blockKey !== '')
                    ->values()
                    ->all();

                return [
                    'section_key' => (string) $section->section_key,
                    'title' => (string) $section->name,
                    'blocks' => $blocks,
                ];
            })
            ->values()
            ->all();

        if ($sections === []) {
            return null;
        }

        return ['sections' => $sections];
    }
}
