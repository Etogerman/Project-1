<?php

namespace App\Filament\Resources\Channels\Pages;

use App\Filament\Resources\Channels\ChannelResource;
use App\Filament\Resources\Pages\ManageRecords;
use App\Models\Channel;
use Filament\Actions\CreateAction;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;

class ManageChannels extends ManageRecords
{
    protected static string $resource = ChannelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Добавить канал связи')
                ->modalWidth(Width::FourExtraLarge)
                ->modalFooterActionsAlignment(Alignment::End)
                ->extraModalWindowAttributes(['class' => 'ac-channel-form-modal'])
                ->using(fn (array $data): Channel => Channel::query()->create(
                    ChannelResource::mutateChannelData($data)
                ))
                ->createAnother(false),
        ];
    }
}
