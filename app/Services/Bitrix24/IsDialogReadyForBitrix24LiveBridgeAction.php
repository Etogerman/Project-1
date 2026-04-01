<?php

namespace App\Services\Bitrix24;

use App\Models\Channel;
use App\Models\Dialog;
use App\Services\Contacts\ResolveRootContactAction;

class IsDialogReadyForBitrix24LiveBridgeAction
{
    public function __construct(
        private readonly ResolveRootContactAction $resolveRootContactAction,
    ) {}

    public function handle(Dialog|int $dialog): bool
    {
        if (! config('bitrix24.features.openlines_enabled', false)) {
            return false;
        }

        $dialog = $dialog instanceof Dialog
            ? $dialog
            : Dialog::query()->with(['channel', 'contact'])->findOrFail($dialog);

        $dialog->loadMissing(['channel', 'contact']);

        if (! filled($dialog->external_chat_id)) {
            return false;
        }

        $channel = $dialog->channel;
        $contact = $dialog->contact;

        if (! $channel instanceof Channel || $contact === null) {
            return false;
        }

        if (! in_array($channel->platform, [
            Channel::PLATFORM_TELEGRAM,
            Channel::PLATFORM_MAX,
        ], true)) {
            return false;
        }

        $rootContact = $this->resolveRootContactAction->handle($contact);

        if (! filled($rootContact->bitrix24_contact_id)) {
            return false;
        }

        if ($rootContact->bitrix24_sync_status !== $rootContact::BITRIX24_SYNC_STATUS_SYNCED) {
            return false;
        }

        if ($rootContact->bitrix24_sync_pending) {
            return false;
        }

        return true;
    }
}
