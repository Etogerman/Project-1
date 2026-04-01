<?php

namespace App\Data\Bitrix24;

final readonly class Bitrix24WebhookEventData
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $headers
     * @param  array<string, mixed>  $query
     */
    public function __construct(
        public string $callbackType,
        public ?string $eventName,
        public Bitrix24AuthContextData $authContext,
        public array $payload,
        public array $headers,
        public array $query,
        public string $payloadHash,
    ) {}
}
