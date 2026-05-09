<?php

namespace App\Data\Bitrix24;

final readonly class Bitrix24OpenLinesIdentityDecisionData
{
    public const MODE_LEGACY_EXTERNAL = 'legacy_external';

    public const MODE_CHANNEL_AWARE = 'channel_aware';

    public const REASON_LEGACY_EXTERNAL_FOUND = 'legacy_external_found';

    public const REASON_LEGACY_EXTERNAL_MISSING = 'legacy_external_missing';

    public const REASON_LEGACY_EXTERNAL_AMBIGUOUS = 'legacy_external_ambiguous';

    public const REASON_CHANNEL_AWARE_NEW_DIALOG = 'channel_aware_new_dialog';

    public function __construct(
        public string $identityMode,
        public string $userId,
        public string $decisionReason,
        public string $channelAwareUserId,
        public ?string $legacyExternalUserId = null,
        public ?string $selectedUserCode = null,
        public ?string $selectedChatId = null,
        public ?string $payloadChatId = null,
        public ?int $legacyCandidateCount = null,
    ) {}
}
