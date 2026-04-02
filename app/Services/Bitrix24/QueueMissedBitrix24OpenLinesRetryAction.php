<?php

namespace App\Services\Bitrix24;

use App\Models\Bitrix24MessageExport;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Dialog;
use App\Models\Message;
use App\Services\Contacts\ResolveRootContactAction;

class QueueMissedBitrix24OpenLinesRetryAction
{
    public function __construct(
        private readonly ResolveRootContactAction $resolveRootContactAction,
        private readonly QueueBitrix24LiveMessageExportAction $queueBitrix24LiveMessageExportAction,
    ) {}

    public function handle(Contact|int $contact): bool
    {
        $rootContact = $this->resolveRootContactAction->handle($contact);
        $message = $this->findLatestMissedInboundMessage($rootContact);

        if (! $message instanceof Message) {
            return false;
        }

        $result = $this->queueBitrix24LiveMessageExportAction->handle($message);

        return $result->queued || $result->alreadyPending;
    }

    private function findLatestMissedInboundMessage(Contact $rootContact): ?Message
    {
        return Message::query()
            ->select('messages.*')
            ->join('dialogs', 'dialogs.id', '=', 'messages.dialog_id')
            ->join('channels', 'channels.id', '=', 'dialogs.channel_id')
            ->leftJoin('bitrix24_message_exports as live_export', function ($join): void {
                $join->on('live_export.message_id', '=', 'messages.id')
                    ->where('live_export.export_mode', '=', Bitrix24MessageExport::MODE_LIVE);
            })
            ->where('dialogs.contact_id', $rootContact->id)
            ->whereIn('channels.platform', [
                Channel::PLATFORM_TELEGRAM,
                Channel::PLATFORM_MAX,
            ])
            ->whereIn('dialogs.bitrix24_live_status', [
                Dialog::BITRIX24_LIVE_STATUS_NOT_LINKED,
                Dialog::BITRIX24_LIVE_STATUS_FAILED,
            ])
            ->whereIn('messages.message_kind', [
                Message::KIND_INBOUND_USER,
                Message::KIND_INBOUND_CONTACT_SHARE,
            ])
            ->where(function ($query): void {
                $query->whereNull('live_export.id')
                    ->orWhere('live_export.export_status', Bitrix24MessageExport::STATUS_FAILED);
            })
            ->with(['dialog.channel', 'contact'])
            ->orderByDesc('messages.received_at')
            ->orderByDesc('messages.id')
            ->first();
    }
}
