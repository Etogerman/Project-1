<?php

namespace App\Console\Commands;

use App\Services\Bitrix24\BackfillBitrix24OpenLineRoutesAction;
use Illuminate\Console\Command;

class Bitrix24BackfillOpenLineRoutesCommand extends Command
{
    protected $signature = 'bitrix24:backfill-openline-routes';

    protected $description = 'Create legacy Bitrix24 Open Lines routes from old profile fields and pin existing dialogs.';

    public function __construct(
        private readonly BackfillBitrix24OpenLineRoutesAction $backfillBitrix24OpenLineRoutesAction,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $result = $this->backfillBitrix24OpenLineRoutesAction->handle();

        $this->info('Legacy-маршруты открытых линий пересобраны.');
        $this->newLine();
        $this->table(
            ['Показатель', 'Количество'],
            [
                ['Создано маршрутов', (string) $result['routes_created']],
                ['Обновлено маршрутов', (string) $result['routes_updated']],
                ['Привязано диалогов', (string) $result['dialogs_pinned']],
                ['Пропущено', (string) $result['skipped']],
            ],
        );

        foreach ($result['warnings'] as $warning) {
            $this->warn($warning);
        }

        return self::SUCCESS;
    }
}
