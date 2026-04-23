<?php

namespace App\Services\Contacts;

use App\Models\ContactDuplicateReview;
use RuntimeException;

class ContactFrozenByOpenCrossChannelIdentityReviewException extends RuntimeException
{
    public static function forMerge(ContactDuplicateReview $review): self
    {
        return new self(self::buildMessage(
            review: $review,
            actionLabel: 'Склейка контактов',
        ));
    }

    public static function forDelete(ContactDuplicateReview $review): self
    {
        return new self(self::buildMessage(
            review: $review,
            actionLabel: 'Удаление контакта',
        ));
    }

    private static function buildMessage(ContactDuplicateReview $review, string $actionLabel): string
    {
        $identityKey = $review->identity_key ?: 'без identity_key';

        return sprintf(
            '%s заблокировано: контакт участвует в открытой cross-channel identity проверке (%s).',
            $actionLabel,
            $identityKey,
        );
    }
}
