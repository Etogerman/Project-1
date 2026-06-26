<?php

namespace App\Data\TelegramAccount;

use App\Models\Message;

final readonly class StoreExternalOutgoingMessageResult
{
    public function __construct(
        public ?Message $message,
        public bool $stored,
        public bool $skipped,
        public ?string $skipReason = null,
    ) {}

    public static function stored(Message $message): self
    {
        return new self(
            message: $message,
            stored: true,
            skipped: false,
        );
    }

    public static function skipped(string $reason, ?Message $message = null): self
    {
        return new self(
            message: $message,
            stored: false,
            skipped: true,
            skipReason: $reason,
        );
    }
}
