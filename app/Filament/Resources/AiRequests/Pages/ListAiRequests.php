<?php

namespace App\Filament\Resources\AiRequests\Pages;

use App\Filament\Resources\AiRequests\AiRequestResource;
use App\Filament\Resources\AiRequests\Widgets\AiRequestStats;
use App\Filament\Resources\Pages\ListRecords;
use App\Models\AiRequest;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\ExposesTableToWidgets;

class ListAiRequests extends ListRecords
{
    use ExposesTableToWidgets;

    protected static string $resource = AiRequestResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            AiRequestStats::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('clearOldRawBodies')
                ->label('Очистить raw старше N дней')
                ->visible(fn (): bool => auth()->user()?->canCleanupAiRequestRawBodies() === true)
                ->form([
                    TextInput::make('days')
                        ->label('Старше дней')
                        ->numeric()
                        ->minValue(1)
                        ->default(30)
                        ->required(),
                ])
                ->requiresConfirmation()
                ->action(function (array $data): void {
                    $days = max(1, (int) ($data['days'] ?? 30));
                    $updated = AiRequest::query()
                        ->where('created_at', '<', now()->subDays($days))
                        ->where(function ($query): void {
                            $query
                                ->whereNotNull('request_body_raw')
                                ->orWhereNotNull('response_body_raw');
                        })
                        ->update([
                            'request_body_raw' => null,
                            'response_body_raw' => null,
                            'raw_body_truncated' => false,
                        ]);

                    Notification::make()
                        ->title("Raw-данные очищены: {$updated}")
                        ->success()
                        ->send();
                }),
        ];
    }
}
