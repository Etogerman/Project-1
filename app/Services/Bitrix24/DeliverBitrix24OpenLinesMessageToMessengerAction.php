<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24OpenLinesOperatorMessageData;
use App\Data\Bots\AutoReplyDeliveryResult;
use App\Models\Channel;
use App\Models\Dialog;
use App\Services\Bots\MaxBotApiService;
use App\Services\Bots\TelegramBotApiService;

class DeliverBitrix24OpenLinesMessageToMessengerAction
{
    public function __construct(
        private readonly TelegramBotApiService $telegramBotApiService,
        private readonly MaxBotApiService $maxBotApiService,
    ) {}

    public function handle(Dialog $dialog, Bitrix24OpenLinesOperatorMessageData $message): AutoReplyDeliveryResult
    {
        $dialog->loadMissing(['channel', 'currentContactIdentity']);

        $channel = $dialog->channel;

        if (! $channel instanceof Channel) {
            throw new Bitrix24ApiException('Bitrix24 Open Lines message delivery requires a dialog channel.');
        }

        return match ($channel->platform) {
            Channel::PLATFORM_TELEGRAM => $this->telegramBotApiService->sendTextMessage(
                $channel,
                $dialog->external_chat_id,
                $dialog->currentContactIdentity?->external_user_id,
                $message->text,
            ),
            Channel::PLATFORM_MAX => $this->maxBotApiService->sendTextMessage(
                $channel,
                $dialog->external_chat_id,
                $dialog->currentContactIdentity?->external_user_id,
                $message->text,
            ),
            default => throw new Bitrix24ApiException(sprintf(
                'Bitrix24 Open Lines platform [%s] is not supported.',
                $channel->platform,
            )),
        };
    }
}
