<?php

namespace App\Services\Contacts;

use App\Models\ContactDuplicateReview;
use RuntimeException;

class ContactPinnedByTerminalCrossChannelIdentityReviewException extends RuntimeException
{
    public static function forDelete(ContactDuplicateReview $review): self
    {
        $identityKey = $review->identity_key ?: 'без identity_key';

        return new self(sprintf(
            'Удаление контакта заблокировано: terminal cross-channel identity routing закреплён на этом контакте (%s).',
            $identityKey,
        ));
    }
}
