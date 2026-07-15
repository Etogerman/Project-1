<?php

namespace App\Services\Messages;

use Illuminate\Support\Carbon;

class InboundMediaRetrySchedule
{
    public function maxAttempts(): int
    {
        return max(1, (int) config('inbound_media.max_attempts', 5));
    }

    public function willRetry(int $attemptNumber): bool
    {
        return max(1, $attemptNumber) < $this->maxAttempts();
    }

    public function nextRetryAt(int $attemptNumber, ?int $retryAfterSeconds = null): Carbon
    {
        return now()->addSeconds($this->delaySeconds($attemptNumber, $retryAfterSeconds));
    }

    public function delaySeconds(int $attemptNumber, ?int $retryAfterSeconds = null): int
    {
        $delays = array_values(array_map(
            static fn (mixed $value): int => max(1, (int) $value),
            (array) config('inbound_media.retry_delays_seconds', [60, 300, 900, 3600, 10800]),
        ));
        $configuredDelay = $delays[max(0, $attemptNumber - 1)]
            ?? ($delays !== [] ? $delays[array_key_last($delays)] : 60);

        return max($configuredDelay, max(0, (int) $retryAfterSeconds));
    }

    public function terminalErrorCode(string $reason): string
    {
        return $reason === 'integrity_mismatch' ? $reason : 'retries_exhausted';
    }
}
