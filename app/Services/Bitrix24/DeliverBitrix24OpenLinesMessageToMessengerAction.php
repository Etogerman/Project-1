<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24OpenLinesOperatorMessageData;
use App\Data\Bots\BotDialogTextSendResult;
use App\Models\Channel;
use App\Models\Dialog;
use App\Services\Bots\SendBotDialogTextAction;

class DeliverBitrix24OpenLinesMessageToMessengerAction
{
    public function __construct(
        private readonly SendBotDialogTextAction $sendBotDialogTextAction,
    ) {}

    public function handle(Dialog $dialog, Bitrix24OpenLinesOperatorMessageData $message): BotDialogTextSendResult
    {
        $dialog->loadMissing(['channel', 'currentContactIdentity']);

        $channel = $dialog->channel;

        if (! $channel instanceof Channel) {
            throw new Bitrix24ApiException('Bitrix24 Open Lines message delivery requires a dialog channel.');
        }

        if (! in_array($channel->platform, [
            Channel::PLATFORM_TELEGRAM,
            Channel::PLATFORM_MAX,
        ], true)) {
            throw new Bitrix24ApiException(sprintf(
                'Bitrix24 Open Lines platform [%s] is not supported.',
                $channel->platform,
            ));
        }

        return $this->sendBotDialogTextAction->handleDialog($dialog, $message->text);
    }
}
