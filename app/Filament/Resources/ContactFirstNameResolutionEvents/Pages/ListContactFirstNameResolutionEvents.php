<?php

namespace App\Filament\Resources\ContactFirstNameResolutionEvents\Pages;

use App\Filament\Resources\ContactFirstNameResolutionEvents\ContactFirstNameResolutionEventResource;
use App\Filament\Resources\ContactFirstNameResolutionEvents\Widgets\ContactFirstNameResolutionEventStats;
use App\Filament\Resources\Pages\ListRecords;
use Filament\Pages\Concerns\ExposesTableToWidgets;

class ListContactFirstNameResolutionEvents extends ListRecords
{
    use ExposesTableToWidgets;

    protected static string $resource = ContactFirstNameResolutionEventResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            ContactFirstNameResolutionEventStats::class,
        ];
    }
}
