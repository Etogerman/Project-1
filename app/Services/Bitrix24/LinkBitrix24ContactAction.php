<?php

namespace App\Services\Bitrix24;

use App\Models\Contact;
use App\Services\Contacts\ResolveRootContactAction;

class LinkBitrix24ContactAction
{
    public function __construct(
        private readonly ResolveRootContactAction $resolveRootContactAction,
        private readonly Bitrix24OpenLineScopedMutation $scopedMutation,
    ) {}

    public function handle(Contact|int $contact, string $bitrix24ContactId): Contact
    {
        $rootContact = $this->resolveRootContactAction->handle($contact);

        $attributes = [
            'bitrix24_contact_id' => $bitrix24ContactId,
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_SYNCED,
            'bitrix24_last_synced_at' => now(),
        ];

        if ($rootContact->bitrix24_linked_at === null) {
            $attributes['bitrix24_linked_at'] = now();
        }

        $this->scopedMutation->run(
            fn () => $rootContact->forceFill($attributes)->save(),
        );

        return $rootContact->fresh();
    }
}
