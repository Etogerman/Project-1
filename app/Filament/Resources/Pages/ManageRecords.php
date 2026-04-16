<?php

namespace App\Filament\Resources\Pages;

use App\Filament\Resources\Pages\Concerns\InteractsWithPersistentTableColumnPreferences;

abstract class ManageRecords extends \Filament\Resources\Pages\ManageRecords
{
    use InteractsWithPersistentTableColumnPreferences;
}
