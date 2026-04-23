<?php

namespace App\Filament\Resources\Dialogs\Pages;

use App\Filament\Resources\Dialogs\DialogResource;
use App\Filament\Resources\Pages\ListRecords;
use Filament\Actions\Action;
use Illuminate\Database\Eloquent\Builder;

class ListDialogs extends ListRecords
{
    protected static string $resource = DialogResource::class;

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
}
