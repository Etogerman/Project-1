<?php

namespace App\Services\Bots;

use App\Models\ChannelConnectionCheckRun;

class ResolveChannelConnectionCheckerHealthAction
{
    public const FRESH_FOR_MINUTES = 10;

    public const CRITICAL_AFTER_MINUTES = 30;

    /**
     * @return array{
     *     status: string,
     *     label: string,
     *     tone: string,
     *     description: string,
     *     stale_channel_reason: ?string,
     *     show_banner: bool,
     *     last_finished_at: mixed,
     *     last_status: ?string,
     *     processed_count: int,
     *     failure_count: int,
     *     app_rev: ?string,
     *     environment: ?string
     * }
     */
    public function handle(?string $environment = null): array
    {
        $environment ??= app()->environment();

        $run = ChannelConnectionCheckRun::query()
            ->when($environment !== null, fn ($query) => $query->where('environment', $environment))
            ->latest('started_at')
            ->latest('id')
            ->first();

        if (! $run instanceof ChannelConnectionCheckRun) {
            return $this->make(
                status: 'unknown',
                label: 'Планировщик проверок ещё не запускался',
                tone: 'warning',
                description: 'Команда channels:check-connections ещё не завершалась в этом окружении.',
                staleChannelReason: 'Планировщик проверок каналов ещё не записывал heartbeat.',
            );
        }

        if ($run->finished_at === null) {
            $isCritical = $run->started_at?->lt(now()->subMinutes(self::CRITICAL_AFTER_MINUTES)) === true;

            return $this->makeFromRun(
                $run,
                status: $isCritical ? 'stuck' : 'running',
                label: $isCritical ? 'Проверка каналов зависла' : 'Проверка каналов выполняется',
                tone: $isCritical ? 'danger' : 'warning',
                description: $isCritical
                    ? 'Последний запуск channels:check-connections давно не записал завершение.'
                    : 'Последний запуск channels:check-connections ещё не записал завершение.',
                staleChannelReason: 'Планировщик проверок каналов не записал завершение последнего запуска.',
            );
        }

        if ($run->finished_at->lt(now()->subMinutes(self::CRITICAL_AFTER_MINUTES))) {
            return $this->makeFromRun(
                $run,
                status: 'critical',
                label: 'Планировщик проверок не работает',
                tone: 'danger',
                description: 'Последний heartbeat channels:check-connections старше критического окна.',
                staleChannelReason: 'Планировщик проверок каналов давно не обновлял каналы.',
            );
        }

        if ($run->status === ChannelConnectionCheckRun::STATUS_FAILED) {
            return $this->makeFromRun(
                $run,
                status: 'failed',
                label: 'Проверка каналов падает',
                tone: 'danger',
                description: $run->last_error_message ?: 'Последний запуск channels:check-connections завершился ошибкой.',
                staleChannelReason: 'Последний запуск планировщика проверок завершился ошибкой.',
            );
        }

        if ($run->finished_at->lt(now()->subMinutes(self::FRESH_FOR_MINUTES))) {
            return $this->makeFromRun(
                $run,
                status: 'stale',
                label: 'Задержка проверки каналов',
                tone: 'warning',
                description: 'Последний heartbeat channels:check-connections старше нормального окна.',
                staleChannelReason: 'Планировщик проверок каналов не обновлял каналы в нормальном окне.',
            );
        }

        if ($run->status === ChannelConnectionCheckRun::STATUS_PARTIAL) {
            return $this->makeFromRun(
                $run,
                status: 'partial',
                label: 'Проверка каналов ограничена',
                tone: 'warning',
                description: $run->last_error_message ?: 'Часть каналов не удалось проверить.',
                staleChannelReason: 'Последний запуск планировщика проверил каналы частично.',
            );
        }

        return $this->makeFromRun(
            $run,
            status: 'ok',
            label: 'Планировщик проверок работает',
            tone: 'success',
            description: 'channels:check-connections завершился в нормальном окне.',
            staleChannelReason: null,
            showBanner: false,
        );
    }

    /**
     * @return array{
     *     status: string,
     *     label: string,
     *     tone: string,
     *     description: string,
     *     stale_channel_reason: ?string,
     *     show_banner: bool,
     *     last_finished_at: mixed,
     *     last_status: ?string,
     *     processed_count: int,
     *     failure_count: int,
     *     app_rev: ?string,
     *     environment: ?string
     * }
     */
    protected function makeFromRun(
        ChannelConnectionCheckRun $run,
        string $status,
        string $label,
        string $tone,
        string $description,
        ?string $staleChannelReason,
        bool $showBanner = true,
    ): array {
        return $this->make(
            status: $status,
            label: $label,
            tone: $tone,
            description: $description,
            staleChannelReason: $staleChannelReason,
            showBanner: $showBanner,
            lastFinishedAt: $run->finished_at,
            lastStatus: $run->status,
            processedCount: $run->processed_count,
            failureCount: $run->failure_count,
            appRev: $run->app_rev,
            environment: $run->environment,
        );
    }

    /**
     * @return array{
     *     status: string,
     *     label: string,
     *     tone: string,
     *     description: string,
     *     stale_channel_reason: ?string,
     *     show_banner: bool,
     *     last_finished_at: mixed,
     *     last_status: ?string,
     *     processed_count: int,
     *     failure_count: int,
     *     app_rev: ?string,
     *     environment: ?string
     * }
     */
    protected function make(
        string $status,
        string $label,
        string $tone,
        string $description,
        ?string $staleChannelReason,
        bool $showBanner = true,
        mixed $lastFinishedAt = null,
        ?string $lastStatus = null,
        int $processedCount = 0,
        int $failureCount = 0,
        ?string $appRev = null,
        ?string $environment = null,
    ): array {
        return [
            'status' => $status,
            'label' => $label,
            'tone' => $tone,
            'description' => $description,
            'stale_channel_reason' => $staleChannelReason,
            'show_banner' => $showBanner,
            'last_finished_at' => $lastFinishedAt,
            'last_status' => $lastStatus,
            'processed_count' => $processedCount,
            'failure_count' => $failureCount,
            'app_rev' => $appRev,
            'environment' => $environment,
        ];
    }
}
