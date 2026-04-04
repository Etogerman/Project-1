<?php

namespace App\Services\DataCollection;

use App\Models\Contact;

class DataCollectionFallbackTransitionResolver
{
    public const ACTION_MOVE_TO_RESIDENCE_CITY = 'move_to_residence_city';

    public const ACTION_MOVE_TO_COUNTRY = 'move_to_country';

    public const ACTION_MOVE_TO_CITY = 'move_to_city';

    public const ACTION_MOVE_TO_AGE_RANGE = 'move_to_age_range';

    public const ACTION_TERMINAL_SKIP = 'terminal_skip';

    public function resolveAfterRetryLimit(?string $field, bool $hasCity = false): ?string
    {
        return $this->resolveCommonAction($field, $hasCity);
    }

    public function resolveAfterLocalSkip(?string $field, bool $hasCity = false): ?string
    {
        return $this->resolveCommonAction($field, $hasCity);
    }

    protected function resolveCommonAction(?string $field, bool $hasCity): ?string
    {
        return match ($field) {
            Contact::DATA_COLLECTION_FIELD_FIRST_NAME => self::ACTION_MOVE_TO_RESIDENCE_CITY,
            Contact::DATA_COLLECTION_FIELD_RESIDENCE_CITY => self::ACTION_MOVE_TO_AGE_RANGE,
            Contact::DATA_COLLECTION_FIELD_COUNTRY => $hasCity
                ? self::ACTION_MOVE_TO_AGE_RANGE
                : self::ACTION_MOVE_TO_CITY,
            Contact::DATA_COLLECTION_FIELD_CITY => self::ACTION_MOVE_TO_AGE_RANGE,
            Contact::DATA_COLLECTION_FIELD_AGE_RANGE => self::ACTION_TERMINAL_SKIP,
            Contact::DATA_COLLECTION_FIELD_RUSSIAN_REGION_CONFIRM => null,
            default => self::ACTION_TERMINAL_SKIP,
        };
    }
}
