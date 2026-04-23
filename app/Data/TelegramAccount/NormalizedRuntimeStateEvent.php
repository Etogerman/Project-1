<?php

namespace App\Data\TelegramAccount;

use Illuminate\Support\Carbon;

final readonly class NormalizedRuntimeStateEvent
{
    public function __construct(
        public string $schemaVersion,
        public int $channelId,
        public string $platform,
        public string $connectionType,
        public string $authStatus,
        public string $authorizationState,
        public string $syncStatus,
        public ?Carbon $lastGatewayHeartbeatAt,
        public ?Carbon $lastSyncStartedAt,
        public ?Carbon $lastSyncCompletedAt,
        public ?Carbon $lastErrorAt,
        public ?string $lastErrorMessage,
        public array $runtimePayload,
        public bool $hasLastGatewayHeartbeatAt,
        public bool $hasLastSyncStartedAt,
        public bool $hasLastSyncCompletedAt,
        public bool $hasLastErrorAt,
        public bool $hasLastErrorMessage,
        public bool $hasRuntimePayload,
    ) {}
}
