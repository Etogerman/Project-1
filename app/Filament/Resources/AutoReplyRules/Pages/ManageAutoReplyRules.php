<?php

namespace App\Filament\Resources\AutoReplyRules\Pages;

use App\Filament\Resources\AutoReplyRules\AutoReplyRuleResource;
use App\Models\AutoReplyRule;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;

class ManageAutoReplyRules extends ManageRecords
{
    protected static string $resource = AutoReplyRuleResource::class;

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
                ->label('Добавить правило')
                ->modalWidth(Width::FiveExtraLarge)
                ->modalFooterActionsAlignment(Alignment::End)
                ->extraModalWindowAttributes(['class' => 'ac-auto-reply-form-modal'])
                ->using(fn (array $data): AutoReplyRule => AutoReplyRuleResource::saveAutoReplyRule($data))
                ->createAnother(false),
        ];
    }
}
