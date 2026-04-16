<?php

namespace App\Filament\Resources\Pages\Concerns;

use App\Models\User;

trait InteractsWithPersistentTableColumnPreferences
{
    protected function loadTableColumnsFromSession(): array
    {
        if (! $this->getTable()->persistsColumnsInSession()) {
            return parent::loadTableColumnsFromSession();
        }

        $user = $this->resolveTablePreferenceUser();

        if (! $user instanceof User) {
            return parent::loadTableColumnsFromSession();
        }

        $preference = $user->getTablePreference($this->getPersistentTablePreferenceKey());
        $columns = is_array($preference['columns'] ?? null)
            ? $preference['columns']
            : $this->getDefaultTableColumnState();
        $hasReorderedColumns = (bool) ($preference['has_reordered_columns'] ?? false);

        session()->put($this->getTableColumnsSessionKey(), $columns);
        session()->put($this->getHasReorderedTableColumnsSessionKey(), $hasReorderedColumns);

        return $columns;
    }

    protected function persistTableColumns(): void
    {
        parent::persistTableColumns();

        if (! $this->getTable()->persistsColumnsInSession()) {
            return;
        }

        $user = $this->resolveTablePreferenceUser();

        if (! $user instanceof User) {
            return;
        }

        $preferenceKey = $this->getPersistentTablePreferenceKey();
        $hasReorderedColumns = $this->hasReorderableTableColumns() && $this->hasReorderedTableColumns();
        $shouldPersistPreference = $hasReorderedColumns || ($this->tableColumns !== $this->getDefaultTableColumnState());

        if (! $shouldPersistPreference) {
            if ($user->getTablePreference($preferenceKey) !== null) {
                $user->forgetTablePreference($preferenceKey);
            }

            return;
        }

        $user->putTablePreference($preferenceKey, [
            'columns' => $this->tableColumns,
            'has_reordered_columns' => $hasReorderedColumns,
        ]);
    }

    public function resetTableColumnManager(): void
    {
        $this->tableColumns = $this->getDefaultTableColumnState();

        if ($this->hasReorderableTableColumns()) {
            $this->updateTableColumns();
        }

        session()->forget($this->getTableColumnsSessionKey());
        session()->forget($this->getHasReorderedTableColumnsSessionKey());

        $this->resolveTablePreferenceUser()?->forgetTablePreference($this->getPersistentTablePreferenceKey());
    }

    protected function getPersistentTablePreferenceKey(): string
    {
        return $this::class;
    }

    protected function resolveTablePreferenceUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
