<?php

namespace App\Data\Bots;

use Illuminate\Support\Carbon;

final readonly class IncomingBotMessage
{
    public const KIND_INBOUND_USER = 'inbound_user';

    public const KIND_INBOUND_CONTACT_SHARE = 'inbound_contact_share';

    public const KIND_INBOUND_SYSTEM_EVENT = 'inbound_system_event';

    public const SYSTEM_EVENT_BOT_BLOCKED_BY_USER = 'bot_blocked_by_user';

    public const SYSTEM_EVENT_BOT_UNBLOCKED_BY_USER = 'bot_unblocked_by_user';

    /**
     * @param  array<string, mixed>  $rawPayload
     */
    public function __construct(
        public string $platform,
        public int $channelId,
        public string $externalChatId,
        public string $externalUserId,
        public ?string $providerEventKey,
        public ?string $externalMessageId,
        public ?string $externalUsername,
        public ?string $contactName,
        public ?string $text,
        public string $inboundKind,
        public ?string $sharedPhoneNumber,
        public ?string $sharedContactUserId,
        public array $rawPayload,
        public Carbon $receivedAt,
        public ?string $messageParameter = null,
        public ?string $systemEventCode = null,
        public ?string $avatarUrl = null,
    ) {}
}
