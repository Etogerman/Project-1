<?php

namespace App\Filament\Resources\Geo\Concerns;

use App\Services\Geo\GeoCsvExportService;
use App\Services\Geo\GeoCsvImportService;
use App\Services\Geo\GeoImportReport;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;

trait HasGeoCsvImportActions
{
    protected function exportGeoLocationsAction(): Action
    {
        return Action::make('exportGeoLocations')
            ->label('Экспорт CSV')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->action(function () {
                $csv = app(GeoCsvExportService::class)->exportLocations();

                return response()->streamDownload(
                    function () use ($csv): void {
                        echo $csv;
                    },
                    'geo_locations-'.now()->format('Ymd-His').'.csv',
                    ['Content-Type' => 'text/csv; charset=UTF-8'],
                );
            });
    }

    protected function importGeoLocationsAction(): Action
    {
        return Action::make('importGeoLocations')
            ->label('Импорт CSV')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->modalWidth(Width::Large)
            ->extraModalWindowAttributes(['class' => 'ac-geo-form-modal'])
            ->form([
                FileUpload::make('file')
                    ->label('geo_locations.csv')
                    ->disk('local')
                    ->directory('tmp/geo-imports')
                    ->acceptedFileTypes(['text/csv', 'text/plain', 'application/octet-stream', 'application/vnd.ms-excel'])
                    ->required(),
                Toggle::make('dry_run')
                    ->label('Только проверить, без записи')
                    ->default(true),
            ])
            ->action(function (array $data): void {
                $this->handleGeoImportAction($data, 'locations');
            });
    }

    protected function exportGeoAliasesAction(): Action
    {
        return Action::make('exportGeoAliases')
            ->label('Экспорт CSV')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->action(function () {
                $csv = app(GeoCsvExportService::class)->exportAliases();

                return response()->streamDownload(
                    function () use ($csv): void {
                        echo $csv;
                    },
                    'geo_aliases-'.now()->format('Ymd-His').'.csv',
                    ['Content-Type' => 'text/csv; charset=UTF-8'],
                );
            });
    }

    protected function importGeoAliasesAction(): Action
    {
        return Action::make('importGeoAliases')
            ->label('Импорт CSV')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->modalWidth(Width::Large)
            ->extraModalWindowAttributes(['class' => 'ac-geo-form-modal'])
            ->form([
                FileUpload::make('file')
                    ->label('geo_aliases.csv')
                    ->disk('local')
                    ->directory('tmp/geo-imports')
                    ->acceptedFileTypes(['text/csv', 'text/plain', 'application/octet-stream', 'application/vnd.ms-excel'])
                    ->required(),
                Toggle::make('dry_run')
                    ->label('Только проверить, без записи')
                    ->default(true),
            ])
            ->action(function (array $data): void {
                $this->handleGeoImportAction($data, 'aliases');
            });
    }

    private function handleGeoImportAction(array $data, string $kind): void
    {
        $relativePath = (string) ($data['file'] ?? '');

        if ($relativePath === '') {
            return;
        }

        $disk = Storage::disk('local');

        try {
            $report = $kind === 'aliases'
                ? app(GeoCsvImportService::class)->importAliases($disk->path($relativePath), (bool) ($data['dry_run'] ?? false))
                : app(GeoCsvImportService::class)->importLocations($disk->path($relativePath), (bool) ($data['dry_run'] ?? false));

            $this->sendGeoImportNotification($report);
        } finally {
            if ($disk->exists($relativePath)) {
                $disk->delete($relativePath);
            }
        }
    }

    private function sendGeoImportNotification(GeoImportReport $report): void
    {
        $notification = Notification::make()
            ->title($report->dryRun ? 'CSV проверен' : 'CSV обработан')
            ->body($this->buildGeoImportSummary($report));

        match ($report->exitCode()) {
            0 => $notification->success(),
            1 => $notification->warning(),
            default => $notification->danger(),
        };

        $notification->send();
    }

    private function buildGeoImportSummary(GeoImportReport $report): string
    {
        $summary = sprintf(
            'Строк: %d · создано: %d · обновлено: %d · пропущено: %d',
            $report->processed,
            $report->created,
            $report->updated,
            $report->skipped,
        );

        $issues = collect([...$report->errors, ...$report->warnings])
            ->take(3)
            ->map(fn (array $issue): string => sprintf(
                '%s: %s',
                $issue['code'] ?? 'issue',
                $issue['message'] ?? ''
            ))
            ->filter()
            ->implode(PHP_EOL);

        return $issues === '' ? $summary : $summary.PHP_EOL.$issues;
    }
}
