<?php

namespace App\Data\AI;

final readonly class AiGenerationContext
{
    public function __construct(
        public string $taskKey,
        public int $contactId,
        public ?int $dialogId = null,
        public ?int $channelId = null,
        public ?int $scenarioId = null,
        public ?string $scenarioBlockId = null,
        public ?string $promptKey = null,
        public ?string $correlationId = null,
    ) {}
}
