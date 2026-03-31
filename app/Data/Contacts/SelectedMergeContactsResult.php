<?php

namespace App\Data\Contacts;

use App\Models\Contact;

final readonly class SelectedMergeContactsResult
{
    public function __construct(
        public Contact $primary,
        public Contact $secondary,
    ) {}
}
