<?php

namespace App\Data\Messages;

final readonly class InboundMediaDownloadFailureDecision
{
    public function __construct(
        public string $reason,
        public bool $retryable,
        public ?int $retryAfterSeconds = null,
    ) {}
}
