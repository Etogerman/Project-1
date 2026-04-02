<?php

namespace App\Filament\Resources\Bitrix24Connections\Pages;

use App\Filament\Resources\Bitrix24Connections\Bitrix24ConnectionResource;
use Filament\Resources\Pages\ListRecords;

class ListBitrix24Connections extends ListRecords
{
    protected static string $resource = Bitrix24ConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
