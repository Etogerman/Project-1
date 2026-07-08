<?php

namespace App\Filament\Resources\DialogStages\Pages;

use App\Filament\Resources\DialogStages\DialogStageResource;
use App\Filament\Resources\Pages\ManageRecords;
use Filament\Actions\CreateAction;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;

class ManageDialogStages extends ManageRecords
{
    protected static string $resource = DialogStageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Добавить стадию')
                ->modalWidth(Width::ThreeExtraLarge)
                ->modalFooterActionsAlignment(Alignment::End)
                ->createAnother(false),
        ];
    }
}
