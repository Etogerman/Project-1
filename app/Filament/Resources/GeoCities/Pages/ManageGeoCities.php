<?php

namespace App\Filament\Resources\GeoCities\Pages;

use App\Filament\Resources\Geo\Concerns\HasGeoAddressNavigationActions;
use App\Filament\Resources\Geo\Concerns\HasGeoCsvImportActions;
use App\Filament\Resources\GeoCities\GeoCityResource;
use App\Filament\Resources\Pages\ManageRecords;
use App\Models\GeoCity;
use Filament\Actions\CreateAction;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Gate;

class ManageGeoCities extends ManageRecords
{
    use HasGeoAddressNavigationActions;
    use HasGeoCsvImportActions;

    protected static string $resource = GeoCityResource::class;

    public function mount(): void
    {
        abort_unless(Gate::allows('viewAny', GeoCity::class), 403);

        parent::mount();
    }

    protected function getHeaderActions(): array
    {
        return [
            ...$this->geoAddressNavigationActions('cities'),
            $this->importGeoLocationsAction(),
            CreateAction::make()
                ->label('Добавить город')
                ->modalWidth(Width::ThreeExtraLarge)
                ->modalFooterActionsAlignment(Alignment::End)
                ->using(fn (array $data): GeoCity => GeoCityResource::createCity($data))
                ->createAnother(false),
        ];
    }
}
