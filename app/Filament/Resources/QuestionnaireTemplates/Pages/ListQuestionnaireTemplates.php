<?php

namespace App\Filament\Resources\QuestionnaireTemplates\Pages;

use App\Filament\Resources\Pages\ListRecords;
use App\Filament\Resources\QuestionnaireTemplates\QuestionnaireTemplateResource;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

class ListQuestionnaireTemplates extends ListRecords
{
    protected static string $resource = QuestionnaireTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createTemplate')
                ->label('Добавить анкету')
                ->icon(Heroicon::OutlinedPlus)
                ->url(QuestionnaireTemplateResource::getUrl('create')),
        ];
    }
}
