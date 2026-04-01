<?php

namespace App\Data\Bitrix24;

use App\Models\Bitrix24WebhookEvent;

final readonly class Bitrix24CallbackHandlingResultData
{
    public function __construct(
        public string $callbackType,
        public string $processingStatus,
        public bool $stored,
        public bool $duplicate,
        public bool $dispatched,
        public ?Bitrix24WebhookEvent $event,
    ) {}
}
