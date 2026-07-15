<?php

namespace App\Services\Messages;

use App\Data\Messages\InboundMediaDownloadFailureDecision;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class InboundMediaDownloadFailureClassifier
{
    public function classify(
        Throwable $throwable,
        ?string $previousReason = null,
    ): InboundMediaDownloadFailureDecision {
        if ($throwable instanceof MediaDownloadIntegrityException) {
            return new InboundMediaDownloadFailureDecision(
                reason: 'integrity_mismatch',
                retryable: true,
            );
        }

        if ($throwable instanceof ConnectionException) {
            return new InboundMediaDownloadFailureDecision(
                reason: 'network_error',
                retryable: true,
            );
        }

        if ($throwable instanceof RequestException) {
            return $this->classifyHttpFailure($throwable, $previousReason);
        }

        if ($throwable instanceof InvalidArgumentException) {
            return new InboundMediaDownloadFailureDecision(
                reason: 'bot_media_download_invalid_payload',
                retryable: false,
            );
        }

        if ($throwable instanceof RuntimeException) {
            return new InboundMediaDownloadFailureDecision(
                reason: 'temporary_failure',
                retryable: true,
            );
        }

        return new InboundMediaDownloadFailureDecision(
            reason: 'unexpected_failure',
            retryable: true,
        );
    }

    private function classifyHttpFailure(
        RequestException $exception,
        ?string $previousReason,
    ): InboundMediaDownloadFailureDecision {
        $status = $exception->response->status();

        if ($status === 429) {
            return new InboundMediaDownloadFailureDecision(
                reason: 'rate_limited',
                retryable: true,
                retryAfterSeconds: $this->retryAfterSeconds($exception),
            );
        }

        if (in_array($status, [408, 425], true) || $status >= 500) {
            return new InboundMediaDownloadFailureDecision(
                reason: $status >= 500 ? 'provider_unavailable' : 'provider_timeout',
                retryable: true,
            );
        }

        if (in_array($status, [401, 403], true)) {
            return new InboundMediaDownloadFailureDecision(
                reason: 'provider_authorization_failed',
                retryable: $previousReason !== 'provider_authorization_failed',
            );
        }

        if ($status === 404) {
            return new InboundMediaDownloadFailureDecision(
                reason: 'source_unavailable',
                retryable: false,
            );
        }

        return new InboundMediaDownloadFailureDecision(
            reason: 'provider_request_failed',
            retryable: false,
        );
    }

    private function retryAfterSeconds(RequestException $exception): ?int
    {
        $header = trim((string) $exception->response->header('Retry-After'));

        if ($header === '') {
            return null;
        }

        if (ctype_digit($header)) {
            return max(0, (int) $header);
        }

        try {
            $retryAt = new DateTimeImmutable($header);
        } catch (Throwable) {
            return null;
        }

        return max(0, $retryAt->getTimestamp() - time());
    }
}
