<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24OpenLinesOperatorMessageData;

class AcknowledgeBitrix24OpenLinesDeliveryAction
{
    public function __construct(
        private readonly Bitrix24ApiClient $bitrix24ApiClient,
        private readonly ResolveBitrix24OpenLinesRouteAction $resolveBitrix24OpenLinesRouteAction,
    ) {}

    public function handle(
        \App\Models\Dialog $dialog,
        Bitrix24OpenLinesOperatorMessageData $bitrixMessage,
        string $externalMessageId,
    ): void {
        $route = $this->resolveBitrix24OpenLinesRouteAction->handle($dialog);

        $connectorCode = $bitrixMessage->connectorCode !== ''
            ? $bitrixMessage->connectorCode
            : $route->connectorCode;
        $lineId = $bitrixMessage->lineId !== ''
            ? $bitrixMessage->lineId
            : $route->lineId;

        $response = $this->bitrix24ApiClient->call('imconnector.send.status.delivery', [
            'CONNECTOR' => $connectorCode,
            'LINE' => $lineId,
            'DATA' => [[
                'im' => $bitrixMessage->im,
                'chat' => [
                    'id' => $bitrixMessage->chatId,
                ],
                'message' => [
                    'id' => [$externalMessageId],
                ],
            ]],
        ]);

        if (! $response->successful) {
            throw new Bitrix24ApiException($response->errorMessage ?? 'Bitrix24 Open Lines delivery acknowledgement failed.');
        }
    }
}
