<?php

namespace App\Data\Bots;

use Illuminate\Support\Carbon;

final readonly class IncomingBotMessageRemoval
{
    /**
     * @param  array<string, mixed>  $rawPayload
     */
    public function __construct(
        public string $platform,
        public int $channelId,
        public ?string $externalChatId,
        public ?string $externalUserId,
        public string $externalMessageId,
        public string $providerEventKey,
        public array $rawPayload,
        public Carbon $removedAt,
    ) {}
}
