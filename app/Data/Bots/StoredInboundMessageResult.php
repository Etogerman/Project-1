<?php

namespace App\Data\Bots;

use App\Models\Message;

final readonly class StoredInboundMessageResult
{
    public const PHONE_CAPTURE_STATUS_NOT_APPLICABLE = 'not_applicable';

    public const PHONE_CAPTURE_STATUS_CAPTURED_NEW = 'captured_new';

    public const PHONE_CAPTURE_STATUS_DUPLICATE_SAME_ROOT = 'duplicate_same_root';

    public const PHONE_CAPTURE_STATUS_MERGED_TO_ROOT = 'merged_to_root';

    public const PHONE_CAPTURE_STATUS_REVIEW_PENDING = 'review_pending';

    public const PHONE_CAPTURE_STATUS_SENDER_MISMATCH = 'sender_mismatch';

    public const PHONE_CAPTURE_STATUS_UNKNOWN_FORMAT = 'unknown_format';

    public function __construct(
        public Message $message,
        public string $phoneCaptureStatus = self::PHONE_CAPTURE_STATUS_NOT_APPLICABLE,
    ) {}

    public function shouldQueuePhoneCaptureFollowUp(): bool
    {
        return in_array($this->phoneCaptureStatus, [
            self::PHONE_CAPTURE_STATUS_CAPTURED_NEW,
            self::PHONE_CAPTURE_STATUS_DUPLICATE_SAME_ROOT,
            self::PHONE_CAPTURE_STATUS_MERGED_TO_ROOT,
            self::PHONE_CAPTURE_STATUS_REVIEW_PENDING,
        ], true);
    }
}
