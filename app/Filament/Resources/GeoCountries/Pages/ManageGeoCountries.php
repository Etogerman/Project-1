<?php

namespace App\Filament\Resources\GeoCountries\Pages;

use App\Filament\Resources\Geo\Concerns\HasGeoAddressNavigationActions;
use App\Filament\Resources\Geo\Concerns\HasGeoCsvImportActions;
use App\Filament\Resources\GeoCountries\GeoCountryResource;
use App\Filament\Resources\Pages\ManageRecords;
use App\Models\GeoCountry;
use Filament\Actions\CreateAction;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Gate;

class ManageGeoCountries extends ManageRecords
{
    use HasGeoAddressNavigationActions;
    use HasGeoCsvImportActions;

    protected static string $resource = GeoCountryResource::class;

    public function mount(): void
    {
        abort_unless(Gate::allows('viewAny', GeoCountry::class), 403);

        parent::mount();
    }

    protected function getHeaderActions(): array
    {
        return [
            ...$this->geoAddressNavigationActions('countries'),
            $this->importGeoLocationsAction(),
            CreateAction::make()
                ->label('Добавить страну')
                ->modalWidth(Width::ThreeExtraLarge)
                ->modalFooterActionsAlignment(Alignment::End)
                ->using(fn (array $data): GeoCountry => GeoCountryResource::createCountry($data))
                ->createAnother(false),
        ];
    }
}
