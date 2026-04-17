<?php

namespace App\Data\Bots;

use App\Data\Dialogs\DialogRouteStatusData;
use App\Models\Dialog;

final readonly class BotDialogTextSendResult
{
    public function __construct(
        public DialogRouteStatusData $routeStatus,
        public ?Dialog $dialog = null,
        public ?AutoReplyDeliveryResult $deliveryResult = null,
    ) {}

    public function wasSent(): bool
    {
        return $this->deliveryResult instanceof AutoReplyDeliveryResult;
    }
}
