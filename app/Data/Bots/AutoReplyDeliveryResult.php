<?php

namespace App\Data\Bots;

final readonly class AutoReplyDeliveryResult
{
    /**
     * @param  array<string, mixed>  $rawPayload
     */
    public function __construct(
        public string $text,
        public ?string $externalMessageId,
        public array $rawPayload,
    ) {}
}
