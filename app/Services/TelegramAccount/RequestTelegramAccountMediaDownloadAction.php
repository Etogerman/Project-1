<?php

namespace App\Services\TelegramAccount;

use App\Models\Dialog;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Services\Messages\RequestInboundMediaDownloadAction;
use InvalidArgumentException;

class RequestTelegramAccountMediaDownloadAction
{
    public function __construct(
        private readonly RequestInboundMediaDownloadAction $requestInboundMediaDownloadAction,
    ) {}

    public function handle(Dialog $dialog, MessageAttachment $attachment, User $operator): MessageAttachment
    {
        if ($attachment->provider !== MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT) {
            throw new InvalidArgumentException('Файл не относится к каналу Telegram account.');
        }

        return $this->requestInboundMediaDownloadAction->handle($dialog, $attachment, $operator);
    }
}
