<?php

namespace App\Services\Dialogs;

use App\Models\CardView;
use App\Models\CardViewItem;
use App\Models\CardViewSection;
use App\Services\CardViews\CardViewFieldRendererRegistry;

class BuildDialogCardViewLayoutAction
{
    /**
     * @return ?list<array{tab_key:string,title:string}>
     */
    public function tabs(): ?array
    {
        $view = $this->activeView(['visibleTabs']);

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
        return $this->fieldTab(SyncSystemDialogCardViewAction::TAB_GENERAL);
    }

    /**
     * @return ?array{sections:list<array{section_key:string,title:string,fields:list<string>}>}
     */
    public function bitrix24(): ?array
    {
        return $this->fieldTab(SyncSystemDialogCardViewAction::TAB_BITRIX24);
    }

    /**
     * @return ?array{sections:list<array{section_key:string,title:string,fields:list<string>}>}
     */
    public function systemFields(): ?array
    {
        return $this->fieldTab(SyncSystemDialogCardViewAction::TAB_SYSTEM_FIELDS);
    }

    /**
     * @return ?array{sections:list<array{section_key:string,title:string,blocks:list<string>}>}
     */
    public function diagnostics(): ?array
    {
        return $this->rendererBlockTab(SyncSystemDialogCardViewAction::TAB_DIAGNOSTICS);
    }

    /**
     * @return ?array{sections:list<array{section_key:string,title:string,items:list<array{item_key:string,item_type:string,renderer_block_key:string}>}>}
     */
    public function itemsForTab(string $tabKey): ?array
    {
        $rendererRegistry = app(CardViewFieldRendererRegistry::class);

        $view = $this->activeView([
            'visibleTabs' => fn ($query) => $query
                ->where('tab_key', $tabKey)
                ->with([
                    'visibleSections' => fn ($sectionQuery) => $sectionQuery
                        ->with(['visibleItems' => fn ($itemQuery) => $itemQuery->with('field')]),
                ]),
        ]);

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

        return $sections !== [] ? ['sections' => $sections] : null;
    }

    /**
     * @return ?array{sections:list<array{section_key:string,title:string,fields:list<string>}>}
     */
    private function fieldTab(string $tabKey): ?array
    {
        $view = $this->activeView([
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
        ]);

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

        return $sections !== [] ? ['sections' => $sections] : null;
    }

    /**
     * @return ?array{sections:list<array{section_key:string,title:string,blocks:list<string>}>}
     */
    private function rendererBlockTab(string $tabKey): ?array
    {
        $view = $this->activeView([
            'visibleTabs' => fn ($query) => $query
                ->where('tab_key', $tabKey)
                ->with([
                    'visibleSections' => fn ($sectionQuery) => $sectionQuery
                        ->with([
                            'visibleItems' => fn ($itemQuery) => $itemQuery->with('field'),
                        ]),
                ]),
        ]);

        if (! $view instanceof CardView) {
            return null;
        }

        $tab = $view->visibleTabs->first();

        if ($tab === null) {
            return null;
        }

        $rendererRegistry = app(CardViewFieldRendererRegistry::class);
        $legacyBlockRegistry = app(DialogCardViewBlockRegistry::class);

        $sections = $tab->visibleSections
            ->map(function (CardViewSection $section) use ($rendererRegistry, $legacyBlockRegistry): array {
                $blocks = $section->visibleItems
                    ->map(function (CardViewItem $item) use ($rendererRegistry, $legacyBlockRegistry): string {
                        if ($item->item_type === CardViewItem::TYPE_BLOCK) {
                            $blockKey = (string) $item->item_key;

                            return $legacyBlockRegistry->contains($blockKey) ? $blockKey : '';
                        }

                        return $rendererRegistry->legacyBlockKeyForField($item->field) ?? '';
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

        return $sections !== [] ? ['sections' => $sections] : null;
    }

    /**
     * @param  array<mixed>  $with
     */
    private function activeView(array $with): ?CardView
    {
        return CardView::query()
            ->where('entity', CardView::ENTITY_DIALOG)
            ->where('context', CardView::CONTEXT_CARD)
            ->where('is_default', true)
            ->with($with)
            ->first();
    }
}
