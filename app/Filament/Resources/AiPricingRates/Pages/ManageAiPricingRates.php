<?php

namespace App\Filament\Resources\AiPricingRates\Pages;

use App\Filament\Resources\AiPricingRates\AiPricingRateResource;
use App\Filament\Resources\Pages\ManageRecords;
use Filament\Actions\CreateAction;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;

class ManageAiPricingRates extends ManageRecords
{
    protected static string $resource = AiPricingRateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Добавить тариф')
                ->modalWidth(Width::ThreeExtraLarge)
                ->modalFooterActionsAlignment(Alignment::End)
                ->createAnother(false),
        ];
    }
}
