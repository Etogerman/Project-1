<?php

namespace App\Filament\Resources\GeoAliases\Pages;

use App\Filament\Resources\Geo\Concerns\HasGeoAddressNavigationActions;
use App\Filament\Resources\Geo\Concerns\HasGeoCsvImportActions;
use App\Filament\Resources\GeoAliases\GeoAliasResource;
use App\Filament\Resources\Pages\ManageRecords;
use App\Models\GeoAlias;
use Filament\Actions\CreateAction;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Gate;

class ManageGeoAliases extends ManageRecords
{
    use HasGeoAddressNavigationActions;
    use HasGeoCsvImportActions;

    protected static string $resource = GeoAliasResource::class;

    public function mount(): void
    {
        abort_unless(Gate::allows('viewAny', GeoAlias::class), 403);

        parent::mount();
    }

    protected function getHeaderActions(): array
    {
        return [
            ...$this->geoAddressNavigationActions('aliases'),
            $this->importGeoAliasesAction(),
            CreateAction::make()
                ->label('Добавить вариант')
                ->modalWidth(Width::ThreeExtraLarge)
                ->modalFooterActionsAlignment(Alignment::End)
                ->using(fn (array $data): GeoAlias => GeoAliasResource::createAlias($data))
                ->createAnother(false),
        ];
    }
}
