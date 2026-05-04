<?php

use App\Models\Channel;
use App\Services\Bots\CheckChannelConnectionAction;
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

            $checker->handle($channel);
            $this->info("Канал #{$channel->id} проверен.");

            return 0;
        }

        $limit = min(max((int) $this->option('limit'), 1), 100);
        $channels = Channel::query()
            ->orderByRaw('CASE WHEN connection_checked_at IS NULL THEN 0 ELSE 1 END')
            ->orderBy('connection_checked_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($channels as $channel) {
            $checker->handle($channel);
        }

        $this->info("Проверено каналов: {$channels->count()}.");

        return 0;
    } finally {
        $lock->release();
    }
})->purpose('Проверить фактическое подключение каналов к текущей админке');

Schedule::command('channels:check-connections --limit=100')
    ->everyMinute()
    ->withoutOverlapping();
