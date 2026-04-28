<?php

namespace App\Filament\Resources\Bitrix24Connections\Pages;

use App\Filament\Resources\Bitrix24Connections\Bitrix24ConnectionResource;
use App\Models\Bitrix24Connection;
use App\Filament\Resources\Pages\ListRecords;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

class ListBitrix24Connections extends ListRecords
{
    protected static string $resource = Bitrix24ConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('connectBitrix24')
                ->label(fn (): string => Bitrix24Connection::query()->exists()
                    ? 'Переподключить Bitrix24'
                    : 'Подключить Bitrix24')
                ->icon(Heroicon::OutlinedLink)
                ->color('success')
                ->visible(fn (): bool => (bool) auth()->user()?->isSuperadmin())
                ->url(route('admin.bitrix24.oauth.start')),
        ];
    }
}
