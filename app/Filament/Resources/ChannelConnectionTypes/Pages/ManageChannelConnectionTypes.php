<?php

namespace App\Filament\Resources\ChannelConnectionTypes\Pages;

use App\Filament\Resources\ChannelConnectionTypes\ChannelConnectionTypeResource;
use App\Filament\Resources\Pages\ManageRecords;
use Filament\Actions\CreateAction;

class ManageChannelConnectionTypes extends ManageRecords
{
    protected static string $resource = ChannelConnectionTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Добавить тип подключения')
                ->createAnother(false),
        ];
    }
}
