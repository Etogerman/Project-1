<?php

namespace App\Filament\Resources\AutoReplyCategories\Pages;

use App\Filament\Resources\AutoReplyCategories\AutoReplyCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;

class ManageAutoReplyCategories extends ManageRecords
{
    protected static string $resource = AutoReplyCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Добавить категорию')
                ->modalWidth(Width::ThreeExtraLarge)
                ->modalFooterActionsAlignment(Alignment::End)
                ->extraModalWindowAttributes(['class' => 'ac-auto-reply-form-modal'])
                ->createAnother(false),
        ];
    }
}
