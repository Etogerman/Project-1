<?php

namespace App\Data\Bots;

final readonly class MaxChatAvatarData
{
    public function __construct(
        public ?string $avatarUrl = null,
        public ?string $fullAvatarUrl = null,
    ) {}

    public function preferredAvatarUrl(): ?string
    {
        return $this->fullAvatarUrl ?? $this->avatarUrl;
    }
}
