<?php

namespace App\Data\Bots;

final readonly class IncomingBotMessage
{
    /**
     * @param  array<string, mixed>  $rawPayload
     */
    public function __construct(
        public string $platform,
        public int $channelId,
        public string|int|null $externalChatId,
        public string|int|null $externalUserId,
        public ?string $text,
        public array $rawPayload,
    ) {}
}
