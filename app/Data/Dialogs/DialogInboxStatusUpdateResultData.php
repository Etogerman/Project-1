<?php

namespace App\Data\Dialogs;

use App\Models\Message;

final readonly class DialogInboxStatusUpdateResultData
{
    public function __construct(
        public DialogInboxStatusData $status,
        public ?Message $historyMessage,
    ) {}
}
