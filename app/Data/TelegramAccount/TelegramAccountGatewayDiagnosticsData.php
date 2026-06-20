<?php

namespace App\Data\TelegramAccount;

final readonly class TelegramAccountGatewayDiagnosticsData
{
    public const CODE_NOT_TELEGRAM_ACCOUNT = 'not_telegram_account';

    public const CODE_RUNTIME_STATE_MISSING = 'runtime_state_missing';

    public const CODE_CHANNEL_INACTIVE = 'channel_inactive';

    public const CODE_AUTH_NOT_AUTHORIZED = 'auth_not_authorized';

    public const CODE_AUTHORIZATION_NOT_READY = 'authorization_not_ready';

    public const CODE_SYNC_NOT_LIVE = 'sync_not_live';

    public const CODE_HEARTBEAT_STALE = 'heartbeat_stale';

    public const CODE_OUTGOING_REPLIES_UNCONFIRMED = 'outgoing_replies_unconfirmed';

    public const CODE_READY = 'ready';

    public function __construct(
        public string $code,
        public string $label,
        public string $description,
        public string $severity,
        public bool $isOutgoingReplyReady,
    ) {}
}
