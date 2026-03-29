<?php

namespace App\Data\Bots;

use App\Models\Message;

final readonly class StoredInboundMessageResult
{
    public const PHONE_CAPTURE_STATUS_NOT_APPLICABLE = 'not_applicable';

    public const PHONE_CAPTURE_STATUS_CAPTURED = 'captured';

    public const PHONE_CAPTURE_STATUS_DUPLICATE = 'duplicate';

    public const PHONE_CAPTURE_STATUS_SENDER_MISMATCH = 'sender_mismatch';

    public const PHONE_CAPTURE_STATUS_UNKNOWN_FORMAT = 'unknown_format';

    public function __construct(
        public Message $message,
        public string $phoneCaptureStatus = self::PHONE_CAPTURE_STATUS_NOT_APPLICABLE,
    ) {}

    public function shouldQueuePhoneCaptureFollowUp(): bool
    {
        return in_array($this->phoneCaptureStatus, [
            self::PHONE_CAPTURE_STATUS_CAPTURED,
            self::PHONE_CAPTURE_STATUS_DUPLICATE,
        ], true);
    }
}
