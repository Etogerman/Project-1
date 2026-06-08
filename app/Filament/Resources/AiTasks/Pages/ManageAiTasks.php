<?php

namespace App\Filament\Resources\AiTasks\Pages;

use App\Filament\Resources\AiTasks\AiTaskResource;
use App\Filament\Resources\Pages\ManageRecords;
use Filament\Actions\CreateAction;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;

class ManageAiTasks extends ManageRecords
{
    protected static string $resource = AiTaskResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Добавить задачу')
                ->modalWidth(Width::Large)
                ->modalFooterActionsAlignment(Alignment::End)
                ->createAnother(false),
        ];
    }
}
