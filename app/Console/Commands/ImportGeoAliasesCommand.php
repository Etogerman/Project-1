<?php

namespace App\Console\Commands;

use App\Services\Geo\GeoCsvImportService;
use App\Services\Geo\GeoImportReport;
use Illuminate\Console\Command;

class ImportGeoAliasesCommand extends Command
{
    protected $signature = 'geo:import-aliases
        {file : Путь к geo_aliases.csv}
        {--dry-run : Проверить файл без записи}
        {--delimiter=; : Разделитель CSV}';

    protected $description = 'Импортировать варианты написания городов из CSV.';

    public function handle(GeoCsvImportService $service): int
    {
        $report = $service->importAliases(
            path: (string) $this->argument('file'),
            dryRun: (bool) $this->option('dry-run'),
            delimiter: (string) $this->option('delimiter'),
        );

        $this->renderReport($report);

        return $report->exitCode();
    }

    private function renderReport(GeoImportReport $report): void
    {
        $this->line(sprintf(
            'processed=%d created=%d updated=%d skipped=%d dry_run=%s exit_code=%d',
            $report->processed,
            $report->created,
            $report->updated,
            $report->skipped,
            $report->dryRun ? 'yes' : 'no',
            $report->exitCode(),
        ));

        foreach ($report->warnings as $warning) {
            $this->warn($this->formatIssue($warning));
        }

        foreach ($report->errors as $error) {
            $this->error($this->formatIssue($error));
        }
    }

    /**
     * @param  array{line:int|null,code:string,message:string,context?:array<string,mixed>}  $issue
     */
    private function formatIssue(array $issue): string
    {
        $line = $issue['line'] === null ? 'file' : 'line '.$issue['line'];

        return sprintf('%s [%s] %s', $line, $issue['code'], $issue['message']);
    }
}
