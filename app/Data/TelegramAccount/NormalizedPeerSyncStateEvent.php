<?php

namespace App\Data\TelegramAccount;

use Illuminate\Support\Carbon;

final readonly class NormalizedPeerSyncStateEvent
{
    public function __construct(
        public string $schemaVersion,
        public int $channelId,
        public string $platform,
        public string $connectionType,
        public string $peerKey,
        public string $externalChatId,
        public string $backfillStatus,
        public ?string $oldestImportedMessageId,
        public ?string $latestObservedMessageId,
        public ?Carbon $historyCompleteAt,
        public ?string $lastSyncError,
        public bool $hasOldestImportedMessageId,
        public bool $hasLatestObservedMessageId,
        public bool $hasHistoryCompleteAt,
        public bool $hasLastSyncError,
    ) {}
}
