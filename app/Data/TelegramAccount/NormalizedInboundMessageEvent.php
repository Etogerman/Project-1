<?php

namespace App\Data\TelegramAccount;

use App\Data\Bots\IncomingBotMessage;
use Illuminate\Support\Carbon;

final readonly class NormalizedInboundMessageEvent
{
    public const PEER_TYPE_PRIVATE = 'private';

    public const HISTORY_SOURCE_BACKFILL = 'backfill';

    public const HISTORY_SOURCE_LIVE = 'live';

    public function __construct(
        public string $schemaVersion,
        public string $gatewayEventId,
        public int $channelId,
        public string $platform,
        public string $connectionType,
        public string $peerType,
        public string $peerKey,
        public string $messageKey,
        public string $externalChatId,
        public string $externalUserId,
        public string $externalMessageId,
        public ?string $externalUsername,
        public ?string $contactName,
        public string $messageKind,
        public ?string $text,
        public array $media,
        public bool $isArchived,
        public array $rawPayload,
        public Carbon $occurredAt,
        public string $historySource,
        public ?string $providerGroupKey = null,
        public ?array $richText = null,
    ) {}

    public static function buildTelegramAccountPeerKey(int|string $channelId, int|string $externalChatId): string
    {
        return sprintf('telegram_account:%s:%s', (string) $channelId, (string) $externalChatId);
    }

    public static function buildTelegramAccountMessageKey(
        int|string $channelId,
        int|string $externalChatId,
        int|string $externalMessageId,
    ): string {
        return sprintf(
            'telegram_account:%s:%s:%s',
            (string) $channelId,
            (string) $externalChatId,
            (string) $externalMessageId,
        );
    }

    public function isPrivatePeer(): bool
    {
        return $this->peerType === self::PEER_TYPE_PRIVATE;
    }

    public function hasMedia(): bool
    {
        return $this->media !== [];
    }

    public function isArchivedPrivatePeer(): bool
    {
        return $this->isPrivatePeer() && $this->isArchived;
    }

    public function toIncomingBotMessage(): IncomingBotMessage
    {
        $rawPayload = $this->rawPayload;
        $rawPayload['_gateway_event'] = [
            'schema_version' => $this->schemaVersion,
            'gateway_event_id' => $this->gatewayEventId,
            'peer_key' => $this->peerKey,
            'message_key' => $this->messageKey,
            'peer_type' => $this->peerType,
            'is_archived' => $this->isArchived,
            'message_kind' => $this->messageKind,
            'history_source' => $this->historySource,
        ];
        $rawPayload['media'] = $this->media;

        return new IncomingBotMessage(
            platform: $this->platform,
            channelId: $this->channelId,
            externalChatId: $this->externalChatId,
            externalUserId: $this->externalUserId,
            providerEventKey: $this->messageKey,
            externalMessageId: $this->externalMessageId,
            externalUsername: $this->externalUsername,
            contactName: $this->contactName,
            text: $this->text,
            inboundKind: IncomingBotMessage::KIND_INBOUND_USER,
            sharedPhoneNumber: null,
            sharedContactUserId: null,
            rawPayload: $rawPayload,
            receivedAt: $this->occurredAt,
            providerGroupKey: $this->providerGroupKey,
            richText: $this->richText,
        );
    }
}
