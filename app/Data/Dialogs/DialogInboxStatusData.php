<?php

namespace App\Data\Dialogs;

final readonly class DialogInboxStatusData
{
    public const CODE_REQUIRES_REPLY = 'requires_reply';

    public const CODE_NOT_REQUIRED = 'not_required';

    public const CODE_NO_NEW = 'no_new';

    public function __construct(
        public string $code,
        public string $label,
        public string $tone,
    ) {}
}
