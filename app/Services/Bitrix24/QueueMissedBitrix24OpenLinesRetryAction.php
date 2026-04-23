<?php

namespace App\Services\Bitrix24;

use App\Models\Bitrix24MessageExport;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Dialog;
use App\Models\Message;
use App\Services\Contacts\ResolveRootContactAction;
use Illuminate\Support\Collection;

class QueueMissedBitrix24OpenLinesRetryAction
{
    private const CANDIDATE_BATCH_SIZE = 50;

    public function __construct(
        private readonly ResolveRootContactAction $resolveRootContactAction,
        private readonly IsMessageReadyForBitrix24LiveExportAction $isMessageReadyForBitrix24LiveExportAction,
        private readonly QueueBitrix24LiveMessageExportAction $queueBitrix24LiveMessageExportAction,
    ) {}

    public function handle(Contact|int $contact): bool
    {
        $rootContact = $this->resolveRootContactAction->handle($contact);

        $page = 1;

        do {
            $candidates = $this->findMissedInboundCandidates($rootContact, $page);

            foreach ($candidates as $message) {
                if (! $this->isMessageReadyForBitrix24LiveExportAction->handle($message)) {
                    continue;
                }

                $result = $this->queueBitrix24LiveMessageExportAction->handle($message, retryAfterSync: true);

                return $result->queued || $result->alreadyPending;
            }

            $page++;
        } while ($candidates->isNotEmpty());

        return false;
    }

    /**
     * @return Collection<int, Message>
     */
    private function findMissedInboundCandidates(Contact $rootContact, int $page): Collection
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
                Message::KIND_INBOUND_SYSTEM_EVENT,
            ])
            ->where(function ($query): void {
                $query->whereNull('live_export.id')
                    ->orWhere(function ($pendingQuery): void {
                        $pendingQuery
                            ->where('live_export.export_status', Bitrix24MessageExport::STATUS_PENDING)
                            ->where(function ($recoverablePendingQuery): void {
                                $recoverablePendingQuery
                                    ->whereNull('live_export.live_batch_uuid')
                                    ->orWhere(function ($expiredClaimQuery): void {
                                        $expiredClaimQuery
                                            ->whereNotNull('live_export.live_claim_uuid')
                                            ->whereNotNull('live_export.live_claim_expires_at')
                                            ->where('live_export.live_claim_expires_at', '<=', now());
                                    });
                            });
                    })
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
