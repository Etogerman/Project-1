<?php

namespace App\Filament\Resources\Dialogs;

use App\Filament\Resources\Dialogs\Pages\ViewDialog;
use App\Models\Dialog;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DialogResource extends Resource
{
    protected static ?string $model = Dialog::class;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $modelLabel = 'Диалог';

    protected static ?string $pluralModelLabel = 'Диалоги';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with([
                'channel',
                'currentContactIdentity',
                'contact.assignedUser',
                'contact.phoneNumbers',
                'contact.primaryIdentity',
            ]);
    }

    public static function getRecordTitle(?Model $record): ?string
    {
        if (! $record instanceof Dialog) {
            return parent::getRecordTitle($record);
        }

        $channelName = $record->channel?->name ?? 'Канал';

        return sprintf('#%d %s', $record->id, $channelName);
    }

    public static function getPages(): array
    {
        return [
            'view' => ViewDialog::route('/{record}'),
        ];
    }
}
