<?php

namespace App\Data\Bitrix24;

final readonly class Bitrix24RescueSyncQueueResultData
{
    /**
     * @param  list<string>  $skippedReasons
     */
    public function __construct(
        public string $status,
        public bool $queuedContact,
        public bool $queuedDeal,
        public bool $queuedHistory,
        public bool $alreadyPending,
        public bool $needsManualReview,
        public int $rootContactId,
        public int $requestedContactId,
        public array $skippedReasons,
        public Bitrix24RescueSyncDiagnosticData $diagnostics,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'queued_contact' => $this->queuedContact,
            'queued_deal' => $this->queuedDeal,
            'queued_history' => $this->queuedHistory,
            'already_pending' => $this->alreadyPending,
            'needs_manual_review' => $this->needsManualReview,
            'root_contact_id' => $this->rootContactId,
            'requested_contact_id' => $this->requestedContactId,
            'skipped_reasons' => $this->skippedReasons,
            'diagnostics' => $this->diagnostics->toArray(),
        ];
    }
}
