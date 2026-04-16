<?php

namespace App\Filament\Resources\Pages;

use App\Filament\Resources\Pages\Concerns\InteractsWithPersistentTableColumnPreferences;

abstract class ListRecords extends \Filament\Resources\Pages\ListRecords
{
    use InteractsWithPersistentTableColumnPreferences;
}
