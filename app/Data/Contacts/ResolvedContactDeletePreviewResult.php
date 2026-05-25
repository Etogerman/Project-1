<?php

namespace App\Data\Contacts;

use App\Models\Contact;

final readonly class ResolvedContactDeletePreviewResult
{
    public function __construct(
        public Contact $rootContact,
        public int $contactsCount,
        public int $dialogsCount,
        public int $messagesCount,
        public int $questionnaireRunsCount,
        public int $phonesCount,
        public int $identitiesCount,
        public bool $hasMergeHistory,
    ) {}
}
