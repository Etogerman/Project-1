<?php

use App\Models\Channel;
use App\Services\Bots\CheckChannelConnectionAction;
use App\Services\Bots\RecordChannelConnectionCheckRunAction;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('channels:check-connections {--channel= : ID одного канала} {--limit=100 : Максимум каналов за запуск}', function () {
    $lock = Cache::lock('channels:check-connections', 55);

    if (! $lock->get()) {
        $this->warn('Проверка подключений каналов уже выполняется.');

        return 0;
    }

    try {
        /** @var CheckChannelConnectionAction $checker */
        $checker = app(CheckChannelConnectionAction::class);
        $channelId = $this->option('channel');

        if (filled($channelId)) {
            $channel = Channel::query()->find((int) $channelId);

            if (! $channel instanceof Channel) {
                $this->error('Канал не найден.');

                return 1;
            }

            try {
                $checker->handle($channel);
                $this->info("Канал #{$channel->id} проверен.");

                return 0;
            } catch (Throwable $throwable) {
                report($throwable);
                $this->error("Не удалось проверить канал #{$channel->id}: {$throwable->getMessage()}");

                return 1;
            }
        }

        $limit = min(max((int) $this->option('limit'), 1), 100);
        /** @var RecordChannelConnectionCheckRunAction $runRecorder */
        $runRecorder = app(RecordChannelConnectionCheckRunAction::class);
        $run = $runRecorder->start();
        $processedCount = 0;
        $successCount = 0;
        $failureCount = 0;
        $lastErrorCode = null;
        $lastErrorMessage = null;

        try {
            $channels = Channel::query()
                ->orderByRaw('CASE WHEN connection_checked_at IS NULL THEN 0 ELSE 1 END')
                ->orderBy('connection_checked_at')
                ->orderBy('id')
                ->limit($limit)
                ->get();

            foreach ($channels as $channel) {
                $processedCount++;

                try {
                    $checker->handle($channel);
                    $successCount++;
                } catch (Throwable $throwable) {
                    $failureCount++;
                    $lastErrorCode = class_basename($throwable);
                    $lastErrorMessage = $throwable->getMessage();
                    report($throwable);
                    $this->warn("Канал #{$channel->id} не удалось проверить из-за ошибки checker-а.");
                }
            }

            $runRecorder->finish(
                $run,
                $processedCount,
                $successCount,
                $failureCount,
                $lastErrorCode,
                $lastErrorMessage,
            );

            $this->info("Проверено каналов: {$processedCount}.");

            if ($failureCount > 0) {
                $this->warn("Ошибок checker-а: {$failureCount}.");

                return 1;
            }

            return 0;
        } catch (Throwable $throwable) {
            $runRecorder->fail($run, $throwable, $processedCount, $successCount, $failureCount + 1);
            report($throwable);
            $this->error("Не удалось завершить проверку каналов: {$throwable->getMessage()}");

            return 1;
        }
    } finally {
        $lock->release();
    }
})->purpose('Проверить фактическое подключение каналов к текущей админке');

Schedule::command('channels:check-connections --limit=100')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('channels:prune-connection-check-runs --days=30')
    ->daily()
    ->withoutOverlapping();

Schedule::command('bot-constructor:run-scheduled-arrows')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('bot-constructor:cleanup-processing-runs')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('bot-media:download-pending-images --force --limit=25')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('media:reap-stale-downloads --limit=100')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('media:reap-expired-reservations --limit=100')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('media:prune-storage --limit=100')
    ->daily()
    ->withoutOverlapping();

Schedule::command('media:reconcile-storage --repair --limit=5000 --orphan-limit=5000')
    ->dailyAt('03:00')
    ->withoutOverlapping();

Schedule::command('media:reconcile-quota --repair')
    ->dailyAt('03:30')
    ->withoutOverlapping();
