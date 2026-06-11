<?php

namespace App\Data\Contacts;

final readonly class FirstNameApplyResult
{
    public function __construct(
        public bool $changed,
        public bool $bitrix24RelevantChanged,
        public ?string $previousValue,
        public ?string $newValue,
        public ?string $previousSource,
        public ?string $newSource,
        public ?string $previousResolutionMethod = null,
        public ?string $newResolutionMethod = null,
    ) {}
}
