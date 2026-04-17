<?php

namespace App\Filament\Resources\Scenarios\Pages;

use App\Filament\Resources\Scenarios\ScenarioResource;
use App\Filament\Resources\Pages\ManageRecords;
use App\Models\Scenario;
use Filament\Actions\CreateAction;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;

class ManageScenarios extends ManageRecords
{
    protected static string $resource = ScenarioResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Добавить сценарий')
                ->modalWidth(Width::FiveExtraLarge)
                ->modalFooterActionsAlignment(Alignment::End)
                ->extraModalWindowAttributes(['class' => 'ac-scenario-form-modal'])
                ->using(fn (array $data): Scenario => ScenarioResource::saveScenario($data))
                ->createAnother(false),
        ];
    }
}
