<?php

namespace App\Filament\Resources\GeoRegions\Pages;

use App\Filament\Resources\Geo\Concerns\HasGeoCsvImportActions;
use App\Filament\Resources\GeoRegions\GeoRegionResource;
use App\Filament\Resources\Pages\ManageRecords;
use App\Models\GeoRegion;
use Filament\Actions\CreateAction;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Gate;

class ManageGeoRegions extends ManageRecords
{
    use HasGeoCsvImportActions;

    protected static string $resource = GeoRegionResource::class;

    public function mount(): void
    {
        abort_unless(Gate::allows('viewAny', GeoRegion::class), 403);

        parent::mount();
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->importGeoLocationsAction(),
            CreateAction::make()
                ->label('Добавить регион')
                ->modalWidth(Width::ThreeExtraLarge)
                ->modalFooterActionsAlignment(Alignment::End)
                ->using(fn (array $data): GeoRegion => GeoRegionResource::createRegion($data))
                ->createAnother(false),
        ];
    }
}
