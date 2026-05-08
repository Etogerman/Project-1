<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24StaleContactRepairResultData;
use App\Models\Bitrix24MessageExport;
use App\Models\Bitrix24SyncLog;
use App\Models\Contact;
use App\Models\Dialog;
use App\Models\Message;
use Illuminate\Support\Collection;
use Throwable;

class RepairStaleBitrix24ContactForLiveExportAction
{
    public function __construct(
        private readonly CollectBitrix24ContactPhonesAction $collectContactPhonesAction,
        private readonly FindBitrix24DuplicateContactsByPhonesAction $findDuplicateContactsByPhonesAction,
        private readonly LinkBitrix24ContactAction $linkBitrix24ContactAction,
        private readonly SyncContactToBitrix24Action $syncContactToBitrix24Action,
        private readonly QueueBitrix24DealSyncAction $queueBitrix24DealSyncAction,
        private readonly QueueBitrix24LiveMessageExportAction $queueBitrix24LiveMessageExportAction,
        private readonly LogBitrix24ApiCallAction $logBitrix24ApiCallAction,
        private readonly ResolveCurrentBitrix24ConnectionAction $resolveCurrentConnectionAction,
    ) {}

    public function handle(Message $triggerMessage, Contact $rootContact, string $oldBitrix24ContactId): Bitrix24StaleContactRepairResultData
    {
        $this->logOldContactNotFound($triggerMessage, $rootContact, $oldBitrix24ContactId);

        $phones = $this->collectContactPhonesAction->handle($rootContact);

        if ($phones === []) {
            $this->logPhoneLookup($triggerMessage, $rootContact, $oldBitrix24ContactId, $phones, [], []);

            return new Bitrix24StaleContactRepairResultData(
                repaired: false,
                failureCode: Bitrix24MessageExport::FAILURE_STALE_CONTACT_REPAIR_NO_VALID_PHONE,
                failureReason: 'Bitrix24 stale contact repair could not run because the local contact has no valid phone.',
            );
        }

        try {
            $connection = $this->resolveCurrentConnectionAction->handle();
            $lookupResult = $this->findDuplicateContactsByPhonesAction->handle($phones, $connection);
        } catch (Throwable $exception) {
            $this->logRepairFailed($triggerMessage, $rootContact, $oldBitrix24ContactId, $exception);

            return new Bitrix24StaleContactRepairResultData(
                repaired: false,
                failureCode: Bitrix24MessageExport::FAILURE_STALE_CONTACT_REPAIR_FAILED,
                failureReason: 'Bitrix24 stale contact repair phone lookup failed: '.$exception->getMessage(),
            );
        }

        $foundCandidateIds = $this->uniquePositiveIntegerStrings($lookupResult->uniqueContactIds);
        $candidateIds = $this->excludeBitrix24ContactId($foundCandidateIds, $oldBitrix24ContactId);

        $this->logPhoneLookup(
            $triggerMessage,
            $rootContact,
            $oldBitrix24ContactId,
            $phones,
            $lookupResult->matchesByPhone,
            $foundCandidateIds,
        );

        if ($candidateIds !== []) {
            $selectedBitrix24ContactId = $this->selectNewestBitrix24ContactId($candidateIds);
            $this->linkBitrix24ContactAction->handle($rootContact, $selectedBitrix24ContactId);
            $this->logRelink($triggerMessage, $rootContact, $oldBitrix24ContactId, $candidateIds, $selectedBitrix24ContactId);
            $this->invalidateOpenLineBindingsAfterContactRepair(
                $triggerMessage,
                $rootContact->fresh() ?? $rootContact,
                $oldBitrix24ContactId,
                $selectedBitrix24ContactId,
                'relink',
            );
            $this->queueRetryScope($triggerMessage);

            return new Bitrix24StaleContactRepairResultData(repaired: true);
        }

        try {
            $syncedContact = $this->createReplacementContact($rootContact, $oldBitrix24ContactId);
        } catch (Throwable $exception) {
            $this->logRepairFailed($triggerMessage, $rootContact, $oldBitrix24ContactId, $exception);

            return new Bitrix24StaleContactRepairResultData(
                repaired: false,
                failureCode: Bitrix24MessageExport::FAILURE_STALE_CONTACT_REPAIR_FAILED,
                failureReason: 'Bitrix24 stale contact repair replacement contact sync failed: '.$exception->getMessage(),
            );
        }

        if (! filled($syncedContact->bitrix24_contact_id)) {
            return new Bitrix24StaleContactRepairResultData(
                repaired: false,
                failureCode: Bitrix24MessageExport::FAILURE_STALE_CONTACT_REPAIR_FAILED,
                failureReason: 'Bitrix24 stale contact repair could not create a replacement contact.',
            );
        }

        $this->logCreate($triggerMessage, $syncedContact, $oldBitrix24ContactId);
        $this->invalidateOpenLineBindingsAfterContactRepair(
            $triggerMessage,
            $syncedContact,
            $oldBitrix24ContactId,
            (string) $syncedContact->bitrix24_contact_id,
            'create',
        );
        $this->queueBitrix24DealSyncAction->handle($syncedContact);
        $this->queueRetryScope($triggerMessage);

        return new Bitrix24StaleContactRepairResultData(repaired: true);
    }

    private function createReplacementContact(Contact $rootContact, string $oldBitrix24ContactId): Contact
    {
        $rootContact->forceFill([
            'bitrix24_contact_id' => null,
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_PENDING,
            'bitrix24_sync_pending' => true,
        ])->save();

        $syncedContact = $this->syncContactToBitrix24Action->handle(
            $rootContact->fresh(),
            excludedBitrix24ContactIds: [$oldBitrix24ContactId],
        );

        if (filled($syncedContact->bitrix24_contact_id) && $syncedContact->bitrix24_sync_status === Contact::BITRIX24_SYNC_STATUS_SYNCED) {
            $syncedContact->forceFill([
                'bitrix24_sync_pending' => false,
            ])->save();
        }

        return $syncedContact->fresh() ?? $syncedContact;
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function uniquePositiveIntegerStrings(array $values): array
    {
        $result = [];

        foreach ($values as $value) {
            $normalized = $this->positiveIntegerString($value);

            if ($normalized === null) {
                continue;
            }

            $result[$normalized] = $normalized;
        }

        return array_values($result);
    }

    /**
     * @param  list<string>  $candidateIds
     * @return list<string>
     */
    private function excludeBitrix24ContactId(array $candidateIds, string $excludedBitrix24ContactId): array
    {
        $excludedBitrix24ContactId = $this->positiveIntegerString($excludedBitrix24ContactId);

        if ($excludedBitrix24ContactId === null) {
            return $candidateIds;
        }

        return array_values(array_filter(
            $candidateIds,
            static fn (string $candidateId): bool => $candidateId !== $excludedBitrix24ContactId,
        ));
    }

    /**
     * @param  list<string>  $candidateIds
     */
    private function selectNewestBitrix24ContactId(array $candidateIds): string
    {
        usort(
            $candidateIds,
            static fn (string $left, string $right): int => (int) $right <=> (int) $left,
        );

        return $candidateIds[0];
    }

    private function positiveIntegerString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        if ($normalized === '' || ! ctype_digit($normalized) || (int) $normalized <= 0) {
            return null;
        }

        return $normalized;
    }

    private function queueRetryScope(Message $triggerMessage): void
    {
        $queuedMessageIds = [];

        foreach ($this->findRetryMessages($triggerMessage) as $message) {
            $result = $this->queueBitrix24LiveMessageExportAction->handle(
                $message,
                retryAfterSync: true,
                retryAfterSyncReason: Bitrix24MessageExport::RETRY_AFTER_SYNC_REASON_STALE_CONTACT_REPAIR,
            );

            if ($result->queued || $result->alreadyPending) {
                $queuedMessageIds[] = $message->id;
            }
        }

        $this->logBitrix24ApiCallAction->handle(
            direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
            operation: 'retry_live_export',
            status: Bitrix24SyncLog::STATUS_SUCCESS,
            requestPayload: [
                'trigger_message_id' => $triggerMessage->id,
                'dialog_id' => $triggerMessage->dialog_id,
            ],
            responsePayload: [
                'queued_message_ids' => $queuedMessageIds,
            ],
            entityType: 'message',
            entityId: (string) $triggerMessage->id,
        );
    }

    private function invalidateOpenLineBindingsAfterContactRepair(
        Message $triggerMessage,
        Contact $rootContact,
        string $oldBitrix24ContactId,
        ?string $newBitrix24ContactId,
        string $repairOutcome,
    ): void {
        $dialogIds = Dialog::query()
            ->where('contact_id', $rootContact->id)
            ->where(function ($query): void {
                $query
                    ->whereNotNull('bitrix24_open_line_user_code_override')
                    ->orWhereNotNull('bitrix24_open_line_resolved_chat_id_override')
                    ->orWhereNotNull('bitrix24_open_line_binding_verified_at');
            })
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if ($dialogIds !== []) {
            Dialog::query()
                ->whereKey($dialogIds)
                ->update([
                    'bitrix24_open_line_user_code_override' => null,
                    'bitrix24_open_line_resolved_chat_id_override' => null,
                    'bitrix24_open_line_binding_verified_at' => null,
                ]);
        }

        $this->logBitrix24ApiCallAction->handle(
            direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
            operation: 'open_line_bindings_invalidated_after_contact_repair',
            status: Bitrix24SyncLog::STATUS_SUCCESS,
            requestPayload: [
                'trigger_message_id' => $triggerMessage->id,
                'dialog_id' => $triggerMessage->dialog_id,
                'contact_id' => $rootContact->id,
                'old_bitrix24_contact_id' => $oldBitrix24ContactId,
                'new_bitrix24_contact_id' => $newBitrix24ContactId,
                'repair_outcome' => $repairOutcome,
            ],
            responsePayload: [
                'reset_dialog_ids' => $dialogIds,
                'reset_dialog_count' => count($dialogIds),
            ],
            entityType: 'contact',
            entityId: (string) $rootContact->id,
        );
    }

    /**
     * @return Collection<int, Message>
     */
    private function findRetryMessages(Message $triggerMessage): Collection
    {
        return Message::query()
            ->select('messages.*')
            ->join('bitrix24_message_exports as live_export', function ($join): void {
                $join->on('live_export.message_id', '=', 'messages.id')
                    ->where('live_export.export_mode', '=', Bitrix24MessageExport::MODE_LIVE);
            })
            ->where('messages.dialog_id', $triggerMessage->dialog_id)
            ->where('messages.id', '>=', $triggerMessage->id)
            ->where('live_export.export_status', Bitrix24MessageExport::STATUS_FAILED)
            ->where('live_export.failure_code', Bitrix24MessageExport::FAILURE_OPEN_LINE_GUARD_LOOKUP_FAILED)
            ->where(function ($query): void {
                $query->whereNull('live_export.failure_uncertain')
                    ->orWhere('live_export.failure_uncertain', false);
            })
            ->with(['dialog.channel', 'contact'])
            ->orderBy('messages.id')
            ->get();
    }

    private function logOldContactNotFound(Message $triggerMessage, Contact $rootContact, string $oldBitrix24ContactId): void
    {
        $this->logBitrix24ApiCallAction->handle(
            direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
            operation: 'old_contact_not_found',
            status: Bitrix24SyncLog::STATUS_SUCCESS,
            requestPayload: [
                'trigger_message_id' => $triggerMessage->id,
                'dialog_id' => $triggerMessage->dialog_id,
                'contact_id' => $rootContact->id,
                'old_bitrix24_contact_id' => $oldBitrix24ContactId,
            ],
            entityType: 'contact',
            entityId: (string) $rootContact->id,
        );
    }

    /**
     * @param  list<string>  $phones
     * @param  array<string, list<string>>  $matchesByPhone
     * @param  list<string>  $candidateIds
     */
    private function logPhoneLookup(
        Message $triggerMessage,
        Contact $rootContact,
        string $oldBitrix24ContactId,
        array $phones,
        array $matchesByPhone,
        array $candidateIds,
    ): void {
        $this->logBitrix24ApiCallAction->handle(
            direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
            operation: 'phone_lookup',
            status: $phones === [] ? Bitrix24SyncLog::STATUS_SKIPPED : Bitrix24SyncLog::STATUS_SUCCESS,
            requestPayload: [
                'trigger_message_id' => $triggerMessage->id,
                'dialog_id' => $triggerMessage->dialog_id,
                'contact_id' => $rootContact->id,
                'old_bitrix24_contact_id' => $oldBitrix24ContactId,
                'checked_phone_count' => count($phones),
                'masked_phones' => array_map($this->maskPhone(...), $phones),
            ],
            responsePayload: [
                'matches_by_phone' => $this->maskMatchesByPhoneKeys($matchesByPhone),
                'candidate_contact_ids' => $candidateIds,
            ],
            entityType: 'contact',
            entityId: (string) $rootContact->id,
        );
    }

    /**
     * @param  list<string>  $candidateIds
     */
    private function logRelink(
        Message $triggerMessage,
        Contact $rootContact,
        string $oldBitrix24ContactId,
        array $candidateIds,
        string $selectedBitrix24ContactId,
    ): void {
        $this->logBitrix24ApiCallAction->handle(
            direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
            operation: 'relink',
            status: Bitrix24SyncLog::STATUS_SUCCESS,
            requestPayload: [
                'trigger_message_id' => $triggerMessage->id,
                'dialog_id' => $triggerMessage->dialog_id,
                'contact_id' => $rootContact->id,
                'old_bitrix24_contact_id' => $oldBitrix24ContactId,
            ],
            responsePayload: [
                'candidate_contact_ids' => $candidateIds,
                'selected_bitrix24_contact_id' => $selectedBitrix24ContactId,
                'selected_by' => count($candidateIds) > 1 ? 'max_bitrix_id_after_multi_phone_lookup' : 'single_phone_lookup_candidate',
            ],
            entityType: 'contact',
            entityId: (string) $rootContact->id,
        );
    }

    private function logCreate(Message $triggerMessage, Contact $rootContact, string $oldBitrix24ContactId): void
    {
        $this->logBitrix24ApiCallAction->handle(
            direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
            operation: 'create',
            status: Bitrix24SyncLog::STATUS_SUCCESS,
            requestPayload: [
                'trigger_message_id' => $triggerMessage->id,
                'dialog_id' => $triggerMessage->dialog_id,
                'contact_id' => $rootContact->id,
                'old_bitrix24_contact_id' => $oldBitrix24ContactId,
            ],
            responsePayload: [
                'selected_bitrix24_contact_id' => $rootContact->bitrix24_contact_id,
                'selected_by' => 'standard_contact_sync_after_phone_lookup_no_match',
            ],
            entityType: 'contact',
            entityId: (string) $rootContact->id,
        );
    }

    private function logRepairFailed(
        Message $triggerMessage,
        Contact $rootContact,
        string $oldBitrix24ContactId,
        Throwable $throwable,
    ): void {
        $this->logBitrix24ApiCallAction->handle(
            direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
            operation: 'repair_failed',
            status: Bitrix24SyncLog::STATUS_FAILED,
            requestPayload: [
                'trigger_message_id' => $triggerMessage->id,
                'dialog_id' => $triggerMessage->dialog_id,
                'contact_id' => $rootContact->id,
                'old_bitrix24_contact_id' => $oldBitrix24ContactId,
            ],
            connection: null,
            errorMessage: $throwable->getMessage(),
            entityType: 'contact',
            entityId: (string) $rootContact->id,
        );
    }

    private function maskPhone(string $phone): string
    {
        $lastFour = mb_substr($phone, -4);
        $prefix = mb_substr($phone, 0, max(0, mb_strlen($phone) - 4));

        return (preg_replace('/\d/u', '*', $prefix) ?? $prefix).$lastFour;
    }

    /**
     * @param  array<string, list<string>>  $matchesByPhone
     * @return array<string, list<string>>
     */
    private function maskMatchesByPhoneKeys(array $matchesByPhone): array
    {
        $masked = [];

        foreach ($matchesByPhone as $phone => $contactIds) {
            $masked[$this->maskPhone($phone)] = $contactIds;
        }

        return $masked;
    }
}
