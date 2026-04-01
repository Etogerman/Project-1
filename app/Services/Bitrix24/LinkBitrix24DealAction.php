<?php

namespace App\Services\Bitrix24;

use App\Models\Contact;
use App\Services\Contacts\ResolveRootContactAction;

class LinkBitrix24DealAction
{
    public function __construct(
        private readonly ResolveRootContactAction $resolveRootContactAction,
    ) {}

    public function handle(Contact|int $contact, string $bitrix24DealId): Contact
    {
        $rootContact = $this->resolveRootContactAction->handle($contact);
        $attributes = [
            'bitrix24_deal_id' => $bitrix24DealId,
            'bitrix24_deal_sync_status' => Contact::BITRIX24_DEAL_SYNC_STATUS_SYNCED,
            'bitrix24_deal_last_synced_at' => now(),
            'bitrix24_deal_sync_pending' => false,
        ];

        if ($rootContact->bitrix24_deal_linked_at === null) {
            $attributes['bitrix24_deal_linked_at'] = now();
        }

        $rootContact->forceFill($attributes)->save();

        return $rootContact->fresh();
    }
}
