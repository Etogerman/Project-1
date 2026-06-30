<?php

namespace App\Filament\Resources\Dialogs\Pages;

use App\Filament\Resources\Dialogs\DialogResource;
use App\Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ListDialogs extends ListRecords
{
    protected static string $resource = DialogResource::class;

    protected function loadTableColumnsFromSession(): array
    {
        return array_map(
            static function (mixed $column): mixed {
                if (is_array($column) && ($column['name'] ?? null) === 'id') {
                    $column['isToggled'] = true;
                }

                return $column;
            },
            parent::loadTableColumnsFromSession(),
        );
    }

    public function mount(): void
    {
        parent::mount();

        $this->clearDialogTableSelection();
        $this->rememberNavigationUrlFromRequest();
    }

    public function rendering($view = null, array $data = []): void
    {
        $this->clearStaleDialogTableSelection();
    }

    public function updated(string $name, mixed $value): void
    {
        if (
            ! in_array($name, [
                'tableGrouping',
                'isTableReordering',
            ], true)
            && ! str_starts_with($name, 'tableFilters.')
        ) {
            return;
        }

        $this->rememberCurrentNavigationUrl();
    }

    public function updatedTableFilters(): void
    {
        parent::updatedTableFilters();
        $this->clearDialogTableSelection();

        $this->rememberCurrentNavigationUrl();
    }

    public function applyTableFilters(): void
    {
        parent::applyTableFilters();
        $this->clearDialogTableSelection();

        $this->rememberCurrentNavigationUrl();
    }

    public function removeTableFilter(string $filterName, ?string $field = null, bool $isRemovingAllFilters = false): void
    {
        parent::removeTableFilter($filterName, $field, $isRemovingAllFilters);
        $this->clearDialogTableSelection();

        if ($isRemovingAllFilters) {
            return;
        }

        $this->rememberCurrentNavigationUrl();
    }

    public function removeTableFilters(): void
    {
        parent::removeTableFilters();
        $this->clearDialogTableSelection();

        $this->rememberCurrentNavigationUrl();
    }

    public function resetTableFiltersForm(): void
    {
        parent::resetTableFiltersForm();
        $this->clearDialogTableSelection();

        $this->rememberCurrentNavigationUrl();
    }

    public function updatedTableSearch(): void
    {
        parent::updatedTableSearch();
        $this->clearDialogTableSelection();

        $this->rememberCurrentNavigationUrl();
    }

    public function resetTableSearch(): void
    {
        parent::resetTableSearch();
        $this->clearDialogTableSelection();

        $this->rememberCurrentNavigationUrl();
    }

    /**
     * @param  string | null  $value
     */
    public function updatedTableColumnSearches($value = null, ?string $key = null): void
    {
        parent::updatedTableColumnSearches($value, $key);
        $this->clearDialogTableSelection();

        $this->rememberCurrentNavigationUrl();
    }

    public function resetTableColumnSearch(string $column): void
    {
        parent::resetTableColumnSearch($column);
        $this->clearDialogTableSelection();

        $this->rememberCurrentNavigationUrl();
    }

    public function resetTableColumnSearches(): void
    {
        parent::resetTableColumnSearches();
        $this->clearDialogTableSelection();

        $this->rememberCurrentNavigationUrl();
    }

    public function sortTable(?string $column = null, ?string $direction = null): void
    {
        parent::sortTable($column, $direction);
        $this->clearDialogTableSelection();

        $this->rememberCurrentNavigationUrl();
    }

    public function updatedTableSort(): void
    {
        parent::updatedTableSort();
        $this->clearDialogTableSelection();

        $this->rememberCurrentNavigationUrl();
    }

    public function updatedTableSortDirection(): void
    {
        parent::updatedTableSortDirection();
        $this->clearDialogTableSelection();

        $this->rememberCurrentNavigationUrl();
    }

    public function updatedActiveTab(): void
    {
        parent::updatedActiveTab();
        $this->clearDialogTableSelection();

        $this->rememberCurrentNavigationUrl();
    }

    public function toggleTableReordering(): void
    {
        parent::toggleTableReordering();
        $this->clearDialogTableSelection();

        $this->rememberCurrentNavigationUrl();
    }

    public function setPage(int | string $page, ?string $pageName = null): void
    {
        parent::setPage($page, $pageName);
        $this->clearDialogTableSelection();

        $this->rememberCurrentNavigationUrl();
    }

    public function resetPage(?string $pageName = null): void
    {
        parent::resetPage($pageName);
        $this->clearDialogTableSelection();

        $this->rememberCurrentNavigationUrl();
    }

    public function clearDialogTableSelection(): void
    {
        $this->selectedTableRecords = [];
        $this->deselectedTableRecords = [];
        $this->isTrackingDeselectedTableRecords = false;

        $this->deselectAllTableRecords();
    }

    private function clearStaleDialogTableSelection(): void
    {
        if ($this->isTrackingDeselectedTableRecords || $this->selectedTableRecords === []) {
            return;
        }

        $visibleRecordKeys = $this->currentVisibleDialogTableRecordKeyLookup();

        foreach ($this->selectedTableRecords as $recordKey) {
            if (array_key_exists((string) $recordKey, $visibleRecordKeys)) {
                continue;
            }

            $this->clearDialogTableSelection();

            return;
        }
    }

    /**
     * @return array<string, true>
     */
    private function currentVisibleDialogTableRecordKeyLookup(): array
    {
        $records = $this->getTableRecords();

        if (method_exists($records, 'getCollection')) {
            $records = $records->getCollection();
        }

        return collect($records)
            ->mapWithKeys(function (Model | array $record, int | string $key): array {
                if ($record instanceof Model) {
                    return [(string) $record->getKey() => true];
                }

                return [(string) $key => true];
            })
            ->all();
    }

    protected function getTableQuery(): Builder
    {
        return DialogResource::getTableRecordQuery();
    }

    private function rememberCurrentNavigationUrl(): void
    {
        DialogResource::rememberNavigationUrl($this->currentTableUrl());
    }

    private function rememberNavigationUrlFromRequest(): void
    {
        $url = request()->fullUrl();

        if (! is_string($url) || $url === '') {
            return;
        }

        DialogResource::rememberNavigationUrl($url);
    }

    private function currentTableUrl(): string
    {
        $baseUrl = DialogResource::getUrl('index');
        $query = [];

        if (is_array($this->tableFilters) && $this->tableFilters !== []) {
            $query['filters'] = $this->tableFilters;
        }

        if (is_string($this->tableSearch) && $this->tableSearch !== '') {
            $query['search'] = $this->tableSearch;
        }

        if (is_string($this->tableSort) && $this->tableSort !== '') {
            $query['sort'] = $this->tableSort;
        }

        if (is_string($this->tableGrouping) && $this->tableGrouping !== '') {
            $query['grouping'] = $this->tableGrouping;
        }

        if (is_string($this->activeTab) && $this->activeTab !== '') {
            $query['tab'] = $this->activeTab;
        }

        if ($this->isTableReordering) {
            $query['reordering'] = 1;
        }

        $tablePage = $this->getTablePage();

        if (((int) $tablePage) > 1) {
            $query[$this->getTablePaginationPageName()] = $tablePage;
        }

        if ($query === []) {
            return $baseUrl;
        }

        return $baseUrl.'?'.http_build_query($query);
    }
}
