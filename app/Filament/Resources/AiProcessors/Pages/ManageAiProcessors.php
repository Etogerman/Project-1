<?php

namespace App\Filament\Resources\AiProcessors\Pages;

use App\Filament\Resources\AiProcessors\AiProcessorResource;
use App\Filament\Resources\Pages\ManageRecords;
use Filament\Actions\CreateAction;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;

class ManageAiProcessors extends ManageRecords
{
    protected static string $resource = AiProcessorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Добавить обработчик')
                ->modalWidth(Width::ThreeExtraLarge)
                ->modalFooterActionsAlignment(Alignment::End)
                ->mutateDataUsing(fn (array $data): array => AiProcessorResource::mutateProcessorData($data))
                ->createAnother(false),
        ];
    }
}
