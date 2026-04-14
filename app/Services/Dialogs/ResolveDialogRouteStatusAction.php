<?php

namespace App\Services\Dialogs;

use App\Data\Dialogs\DialogRouteStatusData;
use App\Models\Channel;
use App\Models\Dialog;

class ResolveDialogRouteStatusAction
{
    private const GENERIC_BLOCKED_REASON = 'У этого диалога сейчас нет рабочего маршрута для отправки ответа.';

    private const BLOCKED_BY_USER_REASON = 'Клиент заблокировал бота в Telegram. Новые сообщения в этот диалог сейчас отправлять нельзя.';

    public function __construct(
        private readonly DialogRoutePredicate $dialogRoutePredicate,
    ) {}

    public function handle(Dialog $dialog): DialogRouteStatusData
    {
        $dialog->loadMissing(['channel', 'currentContactIdentity']);

        $channel = $dialog->channel;

        if (! $channel instanceof Channel) {
            return $this->make(
                DialogRouteStatusData::CODE_CHANNEL_MISSING,
                'Канал не найден',
                'gray',
                false,
            );
        }

        if ($this->dialogRoutePredicate->isBlockedByUser($dialog)) {
            return new DialogRouteStatusData(
                code: DialogRouteStatusData::CODE_BLOCKED_BY_USER,
                label: 'Бот заблокирован',
                tone: 'danger',
                isSendable: false,
                blockedReason: self::BLOCKED_BY_USER_REASON,
            );
        }

        if (! $this->dialogRoutePredicate->isActiveChannel($channel)) {
            return $this->make(
                DialogRouteStatusData::CODE_CHANNEL_INACTIVE,
                'Канал неактивен',
                'gray',
                false,
            );
        }

        if (! $this->dialogRoutePredicate->isBotChannel($channel)) {
            return $this->make(
                DialogRouteStatusData::CODE_NOT_BOT_CHANNEL,
                'Не bot-канал',
                'gray',
                false,
            );
        }

        if (! $this->dialogRoutePredicate->hasConfiguredToken($channel)) {
            return $this->make(
                DialogRouteStatusData::CODE_MISSING_TOKEN,
                'Нет токена',
                'warning',
                false,
            );
        }

        if (! $this->dialogRoutePredicate->supportsPlatform($channel)) {
            return $this->make(
                DialogRouteStatusData::CODE_UNSUPPORTED_PLATFORM,
                'Платформа не поддерживается',
                'gray',
                false,
            );
        }

        return match ($channel->platform) {
            Channel::PLATFORM_TELEGRAM => $this->dialogRoutePredicate->hasTelegramRouteSource($dialog)
                ? $this->make(DialogRouteStatusData::CODE_READY, 'Маршрут готов', 'success', true)
                : $this->make(DialogRouteStatusData::CODE_MISSING_CHAT_ID, 'Нет chat id', 'warning', false),
            Channel::PLATFORM_MAX => $this->dialogRoutePredicate->hasMaxRouteSource($dialog)
                ? $this->make(DialogRouteStatusData::CODE_READY, 'Маршрут готов', 'success', true)
                : $this->make(DialogRouteStatusData::CODE_MISSING_ROUTE_SOURCE, 'Нет route source', 'warning', false),
            default => $this->make(DialogRouteStatusData::CODE_UNSUPPORTED_PLATFORM, 'Платформа не поддерживается', 'gray', false),
        };
    }

    private function make(string $code, string $label, string $tone, bool $isSendable): DialogRouteStatusData
    {
        return new DialogRouteStatusData(
            code: $code,
            label: $label,
            tone: $tone,
            isSendable: $isSendable,
            blockedReason: $isSendable ? null : self::GENERIC_BLOCKED_REASON,
        );
    }
}
