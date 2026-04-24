<?php

namespace App\Data\Dialogs;

use App\Models\Message;

final readonly class DialogStageUpdateResultData
{
    public function __construct(
        public string $stage,
        public ?Message $historyMessage,
    ) {}
}
