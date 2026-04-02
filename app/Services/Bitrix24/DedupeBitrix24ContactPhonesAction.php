<?php

namespace App\Services\Bitrix24;

use App\Models\Contact;
use App\Services\Contacts\ResolveRootContactAction;

class DedupeBitrix24ContactPhonesAction
{
    public function __construct(
        private readonly ResolveRootContactAction $resolveRootContactAction,
        private readonly FetchBitrix24ContactAction $fetchBitrix24ContactAction,
        private readonly BuildBitrix24DedupedRawPhonePayloadAction $buildBitrix24DedupedRawPhonePayloadAction,
        private readonly UpdateBitrix24ContactAction $updateBitrix24ContactAction,
    ) {}

    public function handle(Contact|int $contact): bool
    {
        $rootContact = $this->resolveRootContactAction->handle($contact);
        $bitrix24ContactId = trim((string) $rootContact->bitrix24_contact_id);

        if ($bitrix24ContactId === '') {
            return false;
        }

        $snapshot = $this->fetchBitrix24ContactAction->handle($bitrix24ContactId);
        $dedupedPhonePayload = $this->buildBitrix24DedupedRawPhonePayloadAction->handle($snapshot['PHONE'] ?? []);

        if ($dedupedPhonePayload === null) {
            return false;
        }

        $this->updateBitrix24ContactAction->handle($rootContact, $bitrix24ContactId, [
            'PHONE' => $dedupedPhonePayload,
        ]);

        return true;
    }
}
