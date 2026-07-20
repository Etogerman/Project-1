<?php

namespace App\Data\Messages;

use LogicException;

final readonly class PreparedMessageAttachmentFile
{
    public function __construct(
        public int $attachmentId,
        public int $messageId,
        public int $generation,
        public ?string $claimToken,
        public string $disk,
        public string $path,
        public int $sizeBytes,
    ) {
        if ($attachmentId <= 0 || $messageId <= 0 || $generation <= 0 || $sizeBytes < 0) {
            throw new LogicException('Prepared message attachment file identity is invalid.');
        }

        if (trim($disk) === '' || trim($path) === '') {
            throw new LogicException('Prepared message attachment file location is invalid.');
        }
    }
}
