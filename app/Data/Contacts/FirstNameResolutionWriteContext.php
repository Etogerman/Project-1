<?php

namespace App\Data\Contacts;

final readonly class FirstNameResolutionWriteContext
{
    public function __construct(
        public ?string $correlationId = null,
        public ?int $dialogId = null,
        public ?int $channelId = null,
        public ?int $scenarioId = null,
        public ?string $scenarioBlockId = null,
        public ?int $messageId = null,
        public ?int $resolutionAttemptEventId = null,
        public ?int $aiRequestId = null,
    ) {}
}
