<?php

namespace App\Console\Commands;

use App\Models\BotConstructorArrowRun;
use App\Services\Bots\ProcessBotConstructorScheduledArrowAction;
use Illuminate\Console\Command;

class RunScheduledBotConstructorArrowsCommand extends Command
{
    protected $signature = 'bot-constructor:run-scheduled-arrows
        {--limit=100 : Максимум отложенных стрелок за запуск}';

    protected $description = 'Run due scheduled bot constructor arrows.';

    public function __construct(
        private readonly ProcessBotConstructorScheduledArrowAction $processScheduledArrowAction,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit = min(max((int) $this->option('limit'), 1), 500);
        $runs = BotConstructorArrowRun::query()
            ->where('status', BotConstructorArrowRun::STATUS_SCHEDULED)
            ->where('scheduled_for', '<=', now())
            ->orderBy('scheduled_for')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($runs as $run) {
            $this->processScheduledArrowAction->handle($run);
        }

        $this->info("Обработано отложенных стрелок: {$runs->count()}.");

        return self::SUCCESS;
    }
}
