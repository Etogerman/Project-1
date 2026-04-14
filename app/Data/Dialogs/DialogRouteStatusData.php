<?php

namespace App\Data\Dialogs;

final readonly class DialogRouteStatusData
{
    public const CODE_CHANNEL_MISSING = 'channel_missing';

    public const CODE_CHANNEL_INACTIVE = 'channel_inactive';

    public const CODE_NOT_BOT_CHANNEL = 'not_bot_channel';

    public const CODE_MISSING_TOKEN = 'missing_token';

    public const CODE_MISSING_CHAT_ID = 'missing_chat_id';

    public const CODE_MISSING_ROUTE_SOURCE = 'missing_route_source';

    public const CODE_UNSUPPORTED_PLATFORM = 'unsupported_platform';

    public const CODE_BLOCKED_BY_USER = 'blocked_by_user';

    public const CODE_READY = 'ready';

    public function __construct(
        public string $code,
        public string $label,
        public string $tone,
        public bool $isSendable,
        public ?string $blockedReason,
    ) {}
}
