<?php

namespace App\Filament\Resources\Dialogs\Pages;

use App\Filament\Resources\Dialogs\DialogResource;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListDialogs extends ListRecords
{
    protected static string $resource = DialogResource::class;

    protected function getTableQuery(): Builder
    {
        return DialogResource::getTableRecordQuery();
    }
}
