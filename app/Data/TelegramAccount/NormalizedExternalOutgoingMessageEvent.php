<?php

namespace App\Data\TelegramAccount;

use Illuminate\Support\Carbon;

final readonly class NormalizedExternalOutgoingMessageEvent
{
    public const DIRECTION_OUTBOUND = 'outbound';

    public const SOURCE_EXTERNAL_ACCOUNT = 'external_account';

    public const PEER_TYPE_PRIVATE = 'private';

    public const CONTENT_TYPE_TEXT = 'text';

    public const HISTORY_SOURCE_BACKFILL = 'backfill';

    public const HISTORY_SOURCE_LIVE = 'live';

    public const SKIP_SYNC_DISABLED = 'sync_external_outgoing_disabled';

    public const SKIP_UNKNOWN_BACKFILL_DIALOG = 'unknown_backfill_dialog';

    public const SKIP_UNSUPPORTED_PEER_TYPE = 'unsupported_peer_type';

    public const SKIP_ARCHIVED_CHAT = 'archived_chat';

    public const SKIP_BOT_USER = 'bot_user';

    public const SKIP_UNSUPPORTED_CONTENT_TYPE = 'unsupported_content_type';

    public const SKIP_INVALID_PAYLOAD = 'invalid_payload';

    public const SKIP_AB_ORIGIN_OUTGOING_MESSAGE = 'ab_origin_outgoing_message';

    public function __construct(
        public string $schemaVersion,
        public string $gatewayEventId,
        public int $channelId,
        public string $platform,
        public string $connectionType,
        public string $direction,
        public string $source,
        public string $peerType,
        public string $peerKey,
        public string $messageKey,
        public string $externalChatId,
        public string $externalUserId,
        public string $externalMessageId,
        public ?string $externalUsername,
        public ?string $contactName,
        public string $contentType,
        public ?string $text,
        public bool $isArchived,
        public bool $isBotUser,
        public array $rawPayload,
        public Carbon $occurredAt,
        public string $historySource,
    ) {}

    public static function buildTelegramAccountPeerKey(int|string $channelId, int|string $externalChatId): string
    {
        return NormalizedInboundMessageEvent::buildTelegramAccountPeerKey($channelId, $externalChatId);
    }

    public static function buildTelegramAccountMessageKey(
        int|string $channelId,
        int|string $externalChatId,
        int|string $externalMessageId,
    ): string {
        return NormalizedInboundMessageEvent::buildTelegramAccountMessageKey(
            $channelId,
            $externalChatId,
            $externalMessageId,
        );
    }

    public function isPrivatePeer(): bool
    {
        return $this->peerType === self::PEER_TYPE_PRIVATE;
    }

    public function isTextContent(): bool
    {
        return $this->contentType === self::CONTENT_TYPE_TEXT && filled($this->text);
    }

    public function isBackfill(): bool
    {
        return $this->historySource === self::HISTORY_SOURCE_BACKFILL;
    }
}
