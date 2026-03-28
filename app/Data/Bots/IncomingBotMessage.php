<?php

namespace App\Data\Bots;

use Illuminate\Support\Carbon;

final readonly class IncomingBotMessage
{
    public const KIND_INBOUND_USER = 'inbound_user';

    public const KIND_INBOUND_CONTACT_SHARE = 'inbound_contact_share';

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
    ) {}
}
