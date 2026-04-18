<?php

namespace App\Data\Bots;

final readonly class TelegramChatAvatarFetchResult
{
    public function __construct(
        public ?DownloadedAvatarData $avatar = null,
        public bool $photoMissing = false,
    ) {}

    public static function avatar(DownloadedAvatarData $avatar): self
    {
        return new self(
            avatar: $avatar,
            photoMissing: false,
        );
    }

    public static function photoMissing(): self
    {
        return new self(
            avatar: null,
            photoMissing: true,
        );
    }
}
