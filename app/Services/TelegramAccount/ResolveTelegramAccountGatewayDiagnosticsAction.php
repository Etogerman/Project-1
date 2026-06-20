<?php

namespace App\Services\TelegramAccount;

use App\Data\TelegramAccount\TelegramAccountGatewayDiagnosticsData;
use App\Models\Channel;
use App\Models\ChannelRuntimeState;

class ResolveTelegramAccountGatewayDiagnosticsAction
{
    public function handle(Channel $channel): TelegramAccountGatewayDiagnosticsData
    {
        if (! $channel->isAccountConnection() || $channel->platform !== Channel::PLATFORM_TELEGRAM) {
            return $this->make(
                TelegramAccountGatewayDiagnosticsData::CODE_NOT_TELEGRAM_ACCOUNT,
                'Не Telegram account',
                'Диагностика исходящих ответов gateway применяется только к Telegram account.',
                'gray',
                false,
            );
        }

        $channel->loadMissing('runtimeState');

        $runtimeState = $channel->runtimeState;

        if (! $runtimeState instanceof ChannelRuntimeState) {
            return $this->make(
                TelegramAccountGatewayDiagnosticsData::CODE_RUNTIME_STATE_MISSING,
                'Gateway ещё не прислал runtime-состояние',
                'Gateway ещё не прислал runtime-состояние для этого Telegram account.',
                'gray',
                false,
            );
        }

        if (! $channel->is_active) {
            return $this->make(
                TelegramAccountGatewayDiagnosticsData::CODE_CHANNEL_INACTIVE,
                'Канал отключён',
                'Канал отключён в админке, поэтому исходящие ответы недоступны.',
                'gray',
                false,
            );
        }

        if ($runtimeState->auth_status !== ChannelRuntimeState::AUTH_STATUS_AUTHORIZED) {
            return $this->make(
                TelegramAccountGatewayDiagnosticsData::CODE_AUTH_NOT_AUTHORIZED,
                'Telegram account не авторизован',
                'Telegram account не авторизован, поэтому gateway не может отправлять исходящие ответы.',
                $this->authSeverity($runtimeState),
                false,
            );
        }

        if ($runtimeState->authorization_state !== ChannelRuntimeState::AUTHORIZATION_STATE_READY) {
            return $this->make(
                TelegramAccountGatewayDiagnosticsData::CODE_AUTHORIZATION_NOT_READY,
                'Авторизация Telegram account ещё не готова',
                'Авторизация Telegram account ещё не готова, поэтому исходящие ответы временно недоступны.',
                $this->authorizationSeverity($runtimeState),
                false,
            );
        }

        if ($runtimeState->sync_status !== ChannelRuntimeState::SYNC_STATUS_LIVE) {
            return $this->make(
                TelegramAccountGatewayDiagnosticsData::CODE_SYNC_NOT_LIVE,
                'Синхронизация Telegram account не в реальном времени',
                'Синхронизация Telegram account не в реальном времени, поэтому исходящие ответы не разрешены.',
                $this->syncSeverity($runtimeState),
                false,
            );
        }

        if (
            $runtimeState->last_gateway_heartbeat_at === null
            || $runtimeState->last_gateway_heartbeat_at->lessThan(now()->subMinutes(Channel::GATEWAY_HEARTBEAT_FRESH_FOR_MINUTES))
        ) {
            return $this->make(
                TelegramAccountGatewayDiagnosticsData::CODE_HEARTBEAT_STALE,
                'Heartbeat gateway устарел',
                'Heartbeat gateway устарел, поэтому система не считает исходящие ответы безопасными.',
                'danger',
                false,
            );
        }

        if (data_get($runtimeState->runtime_payload, 'gateway_capabilities.outgoing_replies') !== true) {
            return $this->make(
                TelegramAccountGatewayDiagnosticsData::CODE_OUTGOING_REPLIES_UNCONFIRMED,
                'Gateway не подтвердил отправку исходящих ответов',
                'Gateway не подтвердил отправку исходящих ответов для этого Telegram account.',
                'warning',
                false,
            );
        }

        return $this->make(
            TelegramAccountGatewayDiagnosticsData::CODE_READY,
            'Исходящие ответы готовы',
            'Gateway готов к исходящим ответам для этого Telegram account.',
            'success',
            true,
        );
    }

    private function make(string $code, string $label, string $description, string $severity, bool $isOutgoingReplyReady): TelegramAccountGatewayDiagnosticsData
    {
        return new TelegramAccountGatewayDiagnosticsData(
            code: $code,
            label: $label,
            description: $description,
            severity: $severity,
            isOutgoingReplyReady: $isOutgoingReplyReady,
        );
    }

    private function authSeverity(ChannelRuntimeState $runtimeState): string
    {
        return match ($runtimeState->auth_status) {
            ChannelRuntimeState::AUTH_STATUS_FAILED,
            ChannelRuntimeState::AUTH_STATUS_REVOKED => 'danger',
            ChannelRuntimeState::AUTH_STATUS_PENDING => 'warning',
            default => 'gray',
        };
    }

    private function authorizationSeverity(ChannelRuntimeState $runtimeState): string
    {
        return match ($runtimeState->authorization_state) {
            ChannelRuntimeState::AUTHORIZATION_STATE_EXPIRED => 'danger',
            ChannelRuntimeState::AUTHORIZATION_STATE_AWAITING_QR,
            ChannelRuntimeState::AUTHORIZATION_STATE_AWAITING_CODE,
            ChannelRuntimeState::AUTHORIZATION_STATE_AWAITING_PASSWORD => 'warning',
            default => 'gray',
        };
    }

    private function syncSeverity(ChannelRuntimeState $runtimeState): string
    {
        return match ($runtimeState->sync_status) {
            ChannelRuntimeState::SYNC_STATUS_FAILED => 'danger',
            ChannelRuntimeState::SYNC_STATUS_BACKFILL_IN_PROGRESS,
            ChannelRuntimeState::SYNC_STATUS_DEGRADED => 'warning',
            default => 'gray',
        };
    }
}
