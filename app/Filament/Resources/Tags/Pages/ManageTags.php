<?php

namespace App\Filament\Resources\Tags\Pages;

use App\Filament\Resources\Tags\TagResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;

class ManageTags extends ManageRecords
{
    protected static string $resource = TagResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Добавить тег')
                ->modalWidth(Width::ThreeExtraLarge)
                ->modalFooterActionsAlignment(Alignment::End)
                ->extraModalWindowAttributes([
                    'class' => 'ac-tag-form-modal',
                    'style' => 'width: min(56rem, 92vw); max-width: min(56rem, 92vw);',
                ])
                ->createAnother(false),
        ];
    }
}
