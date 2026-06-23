<?php

namespace App\Data\Bitrix24;

final readonly class Bitrix24RescueSyncDiagnosticData
{
    /**
     * @param  list<string>  $missingRequirements
     * @param  list<string>  $reasons
     */
    public function __construct(
        public bool $ready,
        public int $rootContactId,
        public int $requestedContactId,
        public array $missingRequirements,
        public string $contactStatus,
        public bool $contactPending,
        public string $dealStatus,
        public bool $dealPending,
        public string $historyStatus,
        public bool $historyPending,
        public bool $dealsSyncEnabled,
        public bool $historySyncEnabled,
        public bool $canQueueContact,
        public bool $canQueueDeal,
        public bool $canQueueHistory,
        public bool $needsManualReview,
        public ?string $lastContactError,
        public ?string $lastDealError,
        public ?string $lastHistoryError,
        public array $reasons,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ready' => $this->ready,
            'root_contact_id' => $this->rootContactId,
            'requested_contact_id' => $this->requestedContactId,
            'missing_requirements' => $this->missingRequirements,
            'contact_status' => $this->contactStatus,
            'contact_pending' => $this->contactPending,
            'deal_status' => $this->dealStatus,
            'deal_pending' => $this->dealPending,
            'history_status' => $this->historyStatus,
            'history_pending' => $this->historyPending,
            'deals_sync_enabled' => $this->dealsSyncEnabled,
            'history_sync_enabled' => $this->historySyncEnabled,
            'can_queue_contact' => $this->canQueueContact,
            'can_queue_deal' => $this->canQueueDeal,
            'can_queue_history' => $this->canQueueHistory,
            'needs_manual_review' => $this->needsManualReview,
            'last_contact_error' => $this->lastContactError,
            'last_deal_error' => $this->lastDealError,
            'last_history_error' => $this->lastHistoryError,
            'reasons' => $this->reasons,
        ];
    }
}
