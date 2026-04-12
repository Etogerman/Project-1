<?php

namespace App\Data\Contacts;

final readonly class FirstNameApplyResult
{
    public function __construct(
        public bool $changed,
        public ?string $previousValue,
        public ?string $newValue,
        public ?string $previousSource,
        public ?string $newSource,
    ) {}
}
