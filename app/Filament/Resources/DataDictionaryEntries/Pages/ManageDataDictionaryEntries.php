<?php

namespace App\Filament\Resources\DataDictionaryEntries\Pages;

use App\Filament\Resources\DataDictionaryEntries\DataDictionaryEntryResource;
use App\Filament\Resources\Pages\ManageRecords;
use App\Models\DataDictionaryEntry;
use App\Services\DataDictionaries\ExportDataDictionaryEntriesCsvAction;
use App\Services\DataDictionaries\ImportDataDictionaryEntriesCsvAction;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ManageDataDictionaryEntries extends ManageRecords
{
    protected static string $resource = DataDictionaryEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportCsv')
                ->label('Экспорт CSV')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->action(function () {
                    $csv = app(ExportDataDictionaryEntriesCsvAction::class)
                        ->handle(DataDictionaryEntry::DICTIONARY_NAMES);

                    return response()->streamDownload(
                        function () use ($csv): void {
                            echo $csv;
                        },
                        'names-'.now()->format('Ymd-His').'.csv',
                        ['Content-Type' => 'text/csv; charset=UTF-8'],
                    );
                }),
            Action::make('importCsv')
                ->label('Импорт CSV')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->modalWidth(Width::Large)
                ->modalDescription('Загрузите CSV с колонками: Вариант от клиента, Полное имя, Пол, Авто, Активно, Комментарий. ID можно оставить пустым.')
                ->form([
                    FileUpload::make('csv')
                        ->label('CSV-файл')
                        ->disk('local')
                        ->directory('tmp/data-dictionary-imports')
                        ->acceptedFileTypes([
                            'text/csv',
                            'text/plain',
                            'application/csv',
                            'application/vnd.ms-excel',
                            'application/octet-stream',
                        ])
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $relativePath = (string) ($data['csv'] ?? '');

                    if ($relativePath === '') {
                        return;
                    }

                    $disk = Storage::disk('local');

                    try {
                        $summary = app(ImportDataDictionaryEntriesCsvAction::class)
                            ->handle($disk->path($relativePath), DataDictionaryEntry::DICTIONARY_NAMES);
                    } catch (ValidationException $exception) {
                        Notification::make()
                            ->title('Импорт не выполнен')
                            ->body($this->formatImportValidationMessage($exception))
                            ->danger()
                            ->send();

                        throw $exception;
                    } finally {
                        if ($disk->exists($relativePath)) {
                            $disk->delete($relativePath);
                        }
                    }

                    Notification::make()
                        ->title('Импорт выполнен')
                        ->body(sprintf(
                            'Создано: %d. Обновлено: %d. Пустых строк пропущено: %d.',
                            $summary['created'],
                            $summary['updated'],
                            $summary['skipped'],
                        ))
                        ->success()
                        ->send();
                }),
            CreateAction::make()
                ->label('Добавить имя')
                ->modalWidth(Width::ThreeExtraLarge)
                ->modalFooterActionsAlignment(Alignment::End)
                ->mutateDataUsing(function (array $data): array {
                    $data['dictionary_key'] = DataDictionaryEntry::DICTIONARY_NAMES;

                    return $data;
                })
                ->createAnother(false),
        ];
    }

    protected function formatImportValidationMessage(ValidationException $exception): string
    {
        return collect($exception->errors())
            ->flatten()
            ->filter(fn (mixed $message): bool => is_string($message) && $message !== '')
            ->implode(PHP_EOL);
    }
}
