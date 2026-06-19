<?php

namespace App\Services\Bots;

use App\Models\ChannelConnectionCheckRun;
use App\Support\AppVersion;
use Illuminate\Support\Str;
use Throwable;

class RecordChannelConnectionCheckRunAction
{
    public function start(): ChannelConnectionCheckRun
    {
        return ChannelConnectionCheckRun::query()->create([
            'started_at' => now(),
            'status' => ChannelConnectionCheckRun::STATUS_STARTED,
            'app_rev' => AppVersion::resolve(),
            'environment' => app()->environment(),
        ]);
    }

    public function finish(
        ChannelConnectionCheckRun $run,
        int $processedCount,
        int $successCount,
        int $failureCount,
        ?string $lastErrorCode = null,
        ?string $lastErrorMessage = null,
    ): ChannelConnectionCheckRun {
        $status = match (true) {
            $failureCount === 0 => ChannelConnectionCheckRun::STATUS_SUCCESS,
            $successCount > 0 => ChannelConnectionCheckRun::STATUS_PARTIAL,
            default => ChannelConnectionCheckRun::STATUS_FAILED,
        };

        return $this->complete(
            $run,
            $status,
            $processedCount,
            $successCount,
            $failureCount,
            $lastErrorCode,
            $lastErrorMessage,
        );
    }

    public function fail(
        ChannelConnectionCheckRun $run,
        Throwable $throwable,
        int $processedCount = 0,
        int $successCount = 0,
        int $failureCount = 1,
    ): ChannelConnectionCheckRun {
        return $this->complete(
            $run,
            ChannelConnectionCheckRun::STATUS_FAILED,
            $processedCount,
            $successCount,
            max($failureCount, 1),
            class_basename($throwable),
            $throwable->getMessage(),
        );
    }

    protected function complete(
        ChannelConnectionCheckRun $run,
        string $status,
        int $processedCount,
        int $successCount,
        int $failureCount,
        ?string $lastErrorCode,
        ?string $lastErrorMessage,
    ): ChannelConnectionCheckRun {
        $startedAt = $run->started_at;
        $finishedAt = now();

        $run->forceFill([
            'finished_at' => $finishedAt,
            'status' => $status,
            'processed_count' => max(0, $processedCount),
            'success_count' => max(0, $successCount),
            'failure_count' => max(0, $failureCount),
            'duration_ms' => $startedAt !== null ? max(0, (int) $startedAt->diffInMilliseconds($finishedAt)) : null,
            'last_error_code' => filled($lastErrorCode) ? Str::limit((string) $lastErrorCode, 120, '') : null,
            'last_error_message' => filled($lastErrorMessage) ? Str::limit((string) $lastErrorMessage, 1000, '') : null,
            'app_rev' => $run->app_rev ?? AppVersion::resolve(),
            'environment' => $run->environment ?? app()->environment(),
        ])->save();

        return $run;
    }
}
