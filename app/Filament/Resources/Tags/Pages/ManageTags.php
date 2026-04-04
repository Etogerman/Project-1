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

    public function getPageClasses(): array
    {
        return [
            ...parent::getPageClasses(),
            'ac-inline-list-page',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Добавить тег')
                ->modalWidth(Width::Medium)
                ->modalFooterActionsAlignment(Alignment::End)
                ->extraModalWindowAttributes(['class' => 'ac-tag-form-modal'])
                ->createAnother(false),
        ];
    }
}
