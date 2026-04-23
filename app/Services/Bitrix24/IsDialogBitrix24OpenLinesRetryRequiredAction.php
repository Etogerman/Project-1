<?php

namespace App\Services\Bitrix24;

use App\Models\Bitrix24MessageExport;
use App\Models\Channel;
use App\Models\Dialog;
use App\Models\Message;
use Illuminate\Support\Collection;

class IsDialogBitrix24OpenLinesRetryRequiredAction
{
    private const CANDIDATE_BATCH_SIZE = 50;

    public function __construct(
        private readonly IsMessageReadyForBitrix24LiveExportAction $isMessageReadyForBitrix24LiveExportAction,
    ) {}

    public function handle(Dialog|int $dialog): bool
    {
        $dialog = $dialog instanceof Dialog
            ? $dialog
            : Dialog::query()->with(['channel', 'contact'])->findOrFail($dialog);

        $dialog->loadMissing(['channel', 'contact']);

        $channel = $dialog->channel;

        if (! $channel instanceof Channel) {
            return false;
        }

        if (! in_array($channel->platform, [
            Channel::PLATFORM_TELEGRAM,
            Channel::PLATFORM_MAX,
        ], true)) {
            return false;
        }

        if (! in_array($dialog->bitrix24_live_status, [
            Dialog::BITRIX24_LIVE_STATUS_NOT_LINKED,
            Dialog::BITRIX24_LIVE_STATUS_FAILED,
        ], true)) {
            return false;
        }

        $page = 1;

        do {
            $candidates = $this->findUnresolvedInboundCandidates($dialog, $page);

            foreach ($candidates as $message) {
                if ($message->getAttribute('live_export_status') === Bitrix24MessageExport::STATUS_PENDING) {
                    return true;
                }

                if ($this->isMessageReadyForBitrix24LiveExportAction->handle($message)) {
                    return true;
                }
            }

            $page++;
        } while ($candidates->isNotEmpty());

        return false;
    }

    /**
     * @return Collection<int, Message>
     */
    private function findUnresolvedInboundCandidates(Dialog $dialog, int $page): Collection
    {
        return Message::query()
            ->select('messages.*')
            ->selectRaw('live_export.export_status as live_export_status')
            ->leftJoin('bitrix24_message_exports as live_export', function ($join): void {
                $join->on('live_export.message_id', '=', 'messages.id')
                    ->where('live_export.export_mode', '=', Bitrix24MessageExport::MODE_LIVE);
            })
            ->where('messages.dialog_id', $dialog->id)
            ->whereIn('messages.message_kind', [
                Message::KIND_INBOUND_USER,
                Message::KIND_INBOUND_CONTACT_SHARE,
            ])
            ->where(function ($query): void {
                $query->whereNull('live_export.id')
                    ->orWhere('live_export.export_status', Bitrix24MessageExport::STATUS_PENDING)
                    ->orWhere(function ($failedQuery): void {
                        $failedQuery
                            ->where('live_export.export_status', Bitrix24MessageExport::STATUS_FAILED)
                            ->where(function ($certaintyQuery): void {
                                $certaintyQuery
                                    ->whereNull('live_export.failure_uncertain')
                                    ->orWhere('live_export.failure_uncertain', false);
                            });
                    });
            })
            ->with(['dialog.channel', 'contact'])
            ->orderByDesc('messages.received_at')
            ->orderByDesc('messages.id')
            ->forPage($page, self::CANDIDATE_BATCH_SIZE)
            ->get();
    }
}
