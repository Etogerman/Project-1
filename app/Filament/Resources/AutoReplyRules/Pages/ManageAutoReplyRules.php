<?php

namespace App\Filament\Resources\AutoReplyRules\Pages;

use App\Filament\Resources\AutoReplyRules\AutoReplyRuleResource;
use App\Models\AutoReplyRule;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageAutoReplyRules extends ManageRecords
{
    protected static string $resource = AutoReplyRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Добавить правило')
                ->using(fn (array $data): AutoReplyRule => AutoReplyRule::query()->create(
                    AutoReplyRuleResource::mutateAutoReplyRuleData($data)
                ))
                ->createAnother(false),
        ];
    }
}
