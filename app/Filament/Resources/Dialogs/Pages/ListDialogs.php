<?php

namespace App\Filament\Resources\Dialogs\Pages;

use App\Filament\Resources\Dialogs\DialogResource;
use App\Filament\Resources\Pages\ListRecords;
use Filament\Actions\Action;
use Illuminate\Database\Eloquent\Builder;

class ListDialogs extends ListRecords
{
    protected static string $resource = DialogResource::class;

    public function mount(): void
    {
        parent::mount();

        $this->rememberCurrentNavigationUrl();
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

        $this->rememberCurrentNavigationUrl();
    }

    public function applyTableFilters(): void
    {
        parent::applyTableFilters();

        $this->rememberCurrentNavigationUrl();
    }

    public function removeTableFilter(string $filterName, ?string $field = null, bool $isRemovingAllFilters = false): void
    {
        parent::removeTableFilter($filterName, $field, $isRemovingAllFilters);

        if ($isRemovingAllFilters) {
            return;
        }

        $this->rememberCurrentNavigationUrl();
    }

    public function removeTableFilters(): void
    {
        parent::removeTableFilters();

        $this->rememberCurrentNavigationUrl();
    }

    public function resetTableFiltersForm(): void
    {
        parent::resetTableFiltersForm();

        $this->rememberCurrentNavigationUrl();
    }

    public function updatedTableSearch(): void
    {
        parent::updatedTableSearch();

        $this->rememberCurrentNavigationUrl();
    }

    public function resetTableSearch(): void
    {
        parent::resetTableSearch();

        $this->rememberCurrentNavigationUrl();
    }

    /**
     * @param  string | null  $value
     */
    public function updatedTableColumnSearches($value = null, ?string $key = null): void
    {
        parent::updatedTableColumnSearches($value, $key);

        $this->rememberCurrentNavigationUrl();
    }

    public function resetTableColumnSearch(string $column): void
    {
        parent::resetTableColumnSearch($column);

        $this->rememberCurrentNavigationUrl();
    }

    public function resetTableColumnSearches(): void
    {
        parent::resetTableColumnSearches();

        $this->rememberCurrentNavigationUrl();
    }

    public function sortTable(?string $column = null, ?string $direction = null): void
    {
        parent::sortTable($column, $direction);

        $this->rememberCurrentNavigationUrl();
    }

    public function updatedTableSort(): void
    {
        parent::updatedTableSort();

        $this->rememberCurrentNavigationUrl();
    }

    public function updatedTableSortDirection(): void
    {
        parent::updatedTableSortDirection();

        $this->rememberCurrentNavigationUrl();
    }

    public function updatedActiveTab(): void
    {
        parent::updatedActiveTab();

        $this->rememberCurrentNavigationUrl();
    }

    public function toggleTableReordering(): void
    {
        parent::toggleTableReordering();

        $this->rememberCurrentNavigationUrl();
    }

    protected function getTableQuery(): Builder
    {
        return DialogResource::getTableRecordQuery();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('kanban')
                ->label('Канбан')
                ->icon('heroicon-m-view-columns')
                ->color('warning')
                ->url(DialogResource::getUrl('kanban')),
        ];
    }

    private function rememberCurrentNavigationUrl(): void
    {
        DialogResource::rememberNavigationUrl($this->currentTableUrl());
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

        if ($query === []) {
            return $baseUrl;
        }

        return $baseUrl.'?'.http_build_query($query);
    }
}
