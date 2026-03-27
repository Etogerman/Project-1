<?php

namespace App\Filament\Resources\Channels\Pages;

use App\Filament\Resources\Channels\ChannelResource;
use App\Models\Channel;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageChannels extends ManageRecords
{
    protected static string $resource = ChannelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Добавить канал связи')
                ->using(fn (array $data): Channel => Channel::query()->create(
                    ChannelResource::mutateChannelData($data)
                ))
                ->createAnother(false),
        ];
    }
}
