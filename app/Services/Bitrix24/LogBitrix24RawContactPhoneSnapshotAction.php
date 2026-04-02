<?php

namespace App\Services\Bitrix24;

use App\Models\Bitrix24SyncLog;
use App\Models\Contact;
use App\Models\Dialog;
use App\Models\Message;
use App\Services\Contacts\ResolveRootContactAction;

class LogBitrix24RawContactPhoneSnapshotAction
{
    public function __construct(
        private readonly ResolveRootContactAction $resolveRootContactAction,
        private readonly ShouldRunBitrix24DuplicatePhoneDiagnosticAction $shouldRunDiagnosticAction,
        private readonly Bitrix24ApiClient $bitrix24ApiClient,
        private readonly LogBitrix24ApiCallAction $logBitrix24ApiCallAction,
    ) {}

    public function handle(
        Contact|int $contact,
        string $stage,
        ?Dialog $dialog = null,
        ?Message $message = null,
    ): bool {
        $rootContact = $this->resolveRootContactAction->handle($contact);

        if (! $this->shouldRunDiagnosticAction->handle($rootContact)) {
            return false;
        }

        if (! filled($rootContact->bitrix24_contact_id)) {
            return false;
        }

        $response = $this->bitrix24ApiClient->call('crm.contact.get', [
            'id' => (string) $rootContact->bitrix24_contact_id,
        ]);

        $requestPayload = [
            'stage' => $stage,
            'contact_id' => $rootContact->id,
            'bitrix24_contact_id' => (string) $rootContact->bitrix24_contact_id,
            'dialog_id' => $dialog?->id,
            'message_id' => $message?->id,
            'bitrix24_live_status' => $dialog?->bitrix24_live_status,
            'snapshot_taken_at' => now()->toIso8601String(),
        ];

        $responsePayload = [
            'result' => is_array($response->result) ? $response->result : null,
            'raw' => $response->raw,
            'rest_method' => $response->restMethod,
        ];

        $this->logBitrix24ApiCallAction->handle(
            direction: Bitrix24SyncLog::DIRECTION_SYSTEM,
            operation: 'duplicate_phone_raw_snapshot',
            status: $response->successful ? Bitrix24SyncLog::STATUS_SUCCESS : Bitrix24SyncLog::STATUS_FAILED,
            requestPayload: $requestPayload,
            responsePayload: $responsePayload,
            connection: null,
            httpStatus: $response->httpStatus,
            errorCode: $response->errorCode,
            errorMessage: $response->errorMessage,
            entityType: 'contact',
            entityId: (string) $rootContact->id,
        );

        return $response->successful;
    }
}
