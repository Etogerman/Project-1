<?php

namespace App\Filament\Resources\Geo\Concerns;

use App\Filament\Resources\GeoAliases\GeoAliasResource;
use App\Filament\Resources\GeoCities\GeoCityResource;
use App\Filament\Resources\GeoCountries\GeoCountryResource;
use App\Filament\Resources\GeoRegions\GeoRegionResource;
use Filament\Actions\Action;

trait HasGeoAddressNavigationActions
{
    /**
     * @return list<Action>
     */
    protected function geoAddressNavigationActions(string $active): array
    {
        return [
            $this->geoAddressNavigationAction('countries', 'Страны', GeoCountryResource::getUrl(), $active),
            $this->geoAddressNavigationAction('regions', 'Регионы', GeoRegionResource::getUrl(), $active),
            $this->geoAddressNavigationAction('cities', 'Города', GeoCityResource::getUrl(), $active),
            $this->geoAddressNavigationAction('aliases', 'Варианты', GeoAliasResource::getUrl(), $active),
        ];
    }

    protected function geoAddressNavigationAction(string $key, string $label, string $url, string $active): Action
    {
        return Action::make('geoNavigation'.$key)
            ->label($label)
            ->url($url)
            ->color($active === $key ? 'primary' : 'gray');
    }
}
