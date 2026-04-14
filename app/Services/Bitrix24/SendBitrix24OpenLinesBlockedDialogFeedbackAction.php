<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24OpenLinesOperatorMessageData;
use App\Models\Channel;
use App\Models\Dialog;
use App\Services\Contacts\ResolveContactDisplayNameAction;
use App\Services\Contacts\ResolveRootContactAction;

class SendBitrix24OpenLinesBlockedDialogFeedbackAction
{
    public function __construct(
        private readonly Bitrix24ApiClient $bitrix24ApiClient,
        private readonly ResolveBitrix24OpenLinesRouteAction $resolveBitrix24OpenLinesRouteAction,
        private readonly ResolveRootContactAction $resolveRootContactAction,
        private readonly ResolveContactDisplayNameAction $resolveContactDisplayNameAction,
    ) {}

    public function handle(
        Dialog $dialog,
        Bitrix24OpenLinesOperatorMessageData $bitrixMessage,
        string $text,
        string $feedbackMessageId,
    ): void {
        $dialog->loadMissing(['channel', 'contact', 'currentContactIdentity']);

        $channel = $dialog->channel;

        if (! $channel instanceof Channel) {
            throw new Bitrix24ApiException('Bitrix24 Open Lines blocked feedback requires a dialog channel.');
        }

        $route = $this->resolveBitrix24OpenLinesRouteAction->handle($dialog);
        $rootContact = $this->resolveRootContactAction->handle($dialog->contact()->firstOrFail());
        $identity = $dialog->currentContactIdentity;
        $userName = $this->resolveContactDisplayNameAction->handle($rootContact, $dialog);
        $userId = filled($identity?->external_user_id)
            ? $channel->platform.':'.$identity->external_user_id
            : 'contact:'.$rootContact->id;

        $connectorCode = $bitrixMessage->connectorCode !== ''
            ? $bitrixMessage->connectorCode
            : $route->connectorCode;
        $lineId = $bitrixMessage->lineId !== ''
            ? $bitrixMessage->lineId
            : $route->lineId;

        $response = $this->bitrix24ApiClient->call('imconnector.send.messages', [
            'CONNECTOR' => $connectorCode,
            'LINE' => $lineId,
            'MESSAGES' => [[
                'chat' => [
                    'id' => $bitrixMessage->chatId,
                    'name' => $userName,
                ],
                'user' => [
                    'id' => $userId,
                    'name' => $userName,
                ],
                'message' => [
                    'id' => $feedbackMessageId,
                    'date' => now()->timestamp,
                    'text' => $text,
                ],
            ]],
        ]);

        if (! $response->successful) {
            throw new Bitrix24ApiException($response->errorMessage ?? 'Bitrix24 Open Lines blocked feedback message failed.');
        }
    }
}
