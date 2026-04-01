<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24CallbackHandlingResultData;
use App\Models\Bitrix24WebhookEvent;
use Illuminate\Http\Request;

class HandleBitrix24EventsCallbackAction
{
    public function __construct(
        private readonly HandleBitrix24RuntimeCallbackAction $handleRuntimeCallback,
    ) {}

    public function handle(Request $request): Bitrix24CallbackHandlingResultData
    {
        return $this->handleRuntimeCallback->handle($request, Bitrix24WebhookEvent::TYPE_EVENTS);
    }
}
