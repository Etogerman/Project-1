<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24ActiveDealStateResultData;
use App\Models\Contact;
use App\Services\Contacts\ResolveRootContactAction;

class ApplyBitrix24DealLookupResultAction
{
    public function __construct(
        private readonly ResolveRootContactAction $resolveRootContactAction,
    ) {}

    public function handle(Contact|int $contact, Bitrix24ActiveDealStateResultData $state): Contact
    {
        $rootContact = $this->resolveRootContactAction->handle($contact);
        $attributes = [
            'bitrix24_deal_sync_pending' => false,
            'bitrix24_deal_last_synced_at' => now(),
        ];

        if ($state->type === Bitrix24ActiveDealStateResultData::TYPE_NO_ACTIVE_DEAL) {
            $attributes['bitrix24_deal_id'] = null;
            $attributes['bitrix24_deal_sync_status'] = Contact::BITRIX24_DEAL_SYNC_STATUS_NOT_SYNCED;

            $rootContact->forceFill($attributes)->save();

            return $rootContact->fresh();
        }

        $attributes['bitrix24_deal_id'] = $state->selectedDealId;
        $attributes['bitrix24_deal_sync_status'] = $state->type === Bitrix24ActiveDealStateResultData::TYPE_SINGLE_ACTIVE_DEAL
            ? Contact::BITRIX24_DEAL_SYNC_STATUS_SYNCED
            : Contact::BITRIX24_DEAL_SYNC_STATUS_PENDING_REVIEW;

        if ($rootContact->bitrix24_deal_linked_at === null) {
            $attributes['bitrix24_deal_linked_at'] = now();
        }

        $rootContact->forceFill($attributes)->save();

        return $rootContact->fresh();
    }
}
