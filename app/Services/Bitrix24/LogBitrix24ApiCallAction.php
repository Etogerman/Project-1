<?php

namespace App\Services\Bitrix24;

use App\Models\Bitrix24Connection;
use App\Models\Bitrix24SyncLog;

class LogBitrix24ApiCallAction
{
    public function __construct(
        private readonly SanitizeBitrix24LogPayloadAction $sanitizeLogPayload,
    ) {}

    /**
     * @param  array<string, mixed>  $requestPayload
     * @param  array<string, mixed>|null  $responsePayload
     */
    public function handle(
        string $direction,
        string $operation,
        string $status,
        array $requestPayload = [],
        ?array $responsePayload = null,
        ?Bitrix24Connection $connection = null,
        ?int $httpStatus = null,
        ?string $errorCode = null,
        ?string $errorMessage = null,
        ?string $entityType = null,
        ?string $entityId = null,
        ?string $fingerprint = null,
    ): Bitrix24SyncLog {
        return Bitrix24SyncLog::query()->create([
            'connection_id' => $connection?->id,
            'direction' => $direction,
            'operation' => $operation,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'request_payload' => $this->sanitizeLogPayload->handle($requestPayload),
            'response_payload' => $responsePayload === null
                ? null
                : $this->sanitizeLogPayload->handle($responsePayload),
            'status' => $status,
            'http_status' => $httpStatus,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
            'fingerprint' => $fingerprint,
        ]);
    }
}
