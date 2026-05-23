<?php

namespace App\Filament\Resources\QuestionnaireTemplates\Pages;

use App\Filament\Resources\Pages\ManageRecords;
use App\Filament\Resources\QuestionnaireTemplates\QuestionnaireTemplateResource;
use App\Models\QuestionnaireTemplate;
use Filament\Actions\CreateAction;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;

class ManageQuestionnaireTemplates extends ManageRecords
{
    protected static string $resource = QuestionnaireTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Добавить анкету')
                ->modalWidth(Width::Large)
                ->modalFooterActionsAlignment(Alignment::End)
                ->mutateDataUsing(fn (array $data): array => [
                    ...$data,
                    'status' => QuestionnaireTemplate::STATUS_DRAFT,
                    'created_by' => auth()->id(),
                    'updated_by' => auth()->id(),
                ])
                ->createAnother(false),
        ];
    }
}
