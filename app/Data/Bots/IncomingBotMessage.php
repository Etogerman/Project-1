<?php

namespace App\Data\Bots;

use Illuminate\Support\Carbon;

final readonly class IncomingBotMessage
{
    /**
     * @param  array<string, mixed>  $rawPayload
     */
    public function __construct(
        public string $platform,
        public int $channelId,
        public string $externalChatId,
        public string $externalUserId,
        public ?string $providerEventKey,
        public ?string $externalMessageId,
        public ?string $externalUsername,
        public ?string $contactName,
        public ?string $text,
        public array $rawPayload,
        public Carbon $receivedAt,
    ) {}
}
