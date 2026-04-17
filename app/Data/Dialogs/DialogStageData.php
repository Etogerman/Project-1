<?php

namespace App\Data\Dialogs;

final readonly class DialogStageData
{
    public function __construct(
        public string $code,
        public string $label,
        public string $tone,
        public bool $isManual,
    ) {}
}
