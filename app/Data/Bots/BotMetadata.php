<?php

namespace App\Data\Bots;

readonly class BotMetadata
{
    public function __construct(
        public ?string $externalId,
        public ?string $username,
        public ?string $name,
        public ?string $profileUrl,
    ) {}
}
