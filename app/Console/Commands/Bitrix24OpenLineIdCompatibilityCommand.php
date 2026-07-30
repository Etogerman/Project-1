<?php

namespace App\Console\Commands;

use App\Services\Bitrix24\Bitrix24OpenLineIdCompatibilityException;
use App\Services\Bitrix24\Bitrix24OpenLineIdCompatibilityService;
use Illuminate\Console\Command;

class Bitrix24OpenLineIdCompatibilityCommand extends Command
{
    protected $signature = 'bitrix24:openlines-line-id-compatibility
        {--storage-dir= : Каталог с current/previous registry и active leases}
        {--artifact= : Путь для проверяемого JSON artifact}
        {--migrate : Выполнить collision-safe migration после успешного preflight}';

    protected $description = 'Проверить и явно мигрировать неканонические LINE_ID во всех доступных источниках';

    public function handle(Bitrix24OpenLineIdCompatibilityService $service): int
    {
        $storageDirectory = trim((string) $this->option('storage-dir'));

        if ($storageDirectory === '') {
            $this->error('Укажите --storage-dir с route_registry.json, previous snapshot и active leases.');

            return self::INVALID;
        }

        try {
            if ((bool) $this->option('migrate')) {
                $artifactPath = trim((string) $this->option('artifact'));

                if ($artifactPath === '') {
                    $this->error('Для migration обязателен --artifact.');

                    return self::INVALID;
                }

                $report = $service->migrate($storageDirectory, $artifactPath);
            } else {
                $artifactPath = trim((string) $this->option('artifact'));
                $report = $artifactPath === ''
                    ? $service->preflight($storageDirectory)
                    : $service->preflightArtifact($storageDirectory, $artifactPath);
            }
        } catch (Bitrix24OpenLineIdCompatibilityException $exception) {
            $this->error(sprintf('[%s] %s', $exception->errorCode, $exception->getMessage()));

            return self::FAILURE;
        }

        $this->table(
            ['Источник', 'Записей'],
            collect($report['source_counts'] ?? [])
                ->map(fn (mixed $count, string $source): array => [$source, (int) $count])
                ->values()
                ->all(),
        );
        $this->line('Migration candidates: '.count($report['migrations'] ?? []));
        $this->line('Collisions: '.count($report['collisions'] ?? []));
        $this->line('Invalid: '.count($report['invalid'] ?? []));

        if (($report['ready'] ?? false) !== true) {
            $this->error('Compatibility preflight заблокировал rollout.');

            return self::FAILURE;
        }

        $this->info(
            (bool) ($report['migration_applied'] ?? false)
                ? 'Collision-safe migration завершена; artifact сохранён.'
                : 'Compatibility preflight пройден.',
        );

        return self::SUCCESS;
    }
}
