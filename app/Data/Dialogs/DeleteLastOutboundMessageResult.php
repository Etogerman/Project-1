<?php

namespace App\Data\Dialogs;

final readonly class DeleteLastOutboundMessageResult
{
    public function __construct(
        public string $status,
        public ?int $messageId = null,
        public ?string $externalMessageId = null,
        public ?string $error = null,
    ) {}
}
