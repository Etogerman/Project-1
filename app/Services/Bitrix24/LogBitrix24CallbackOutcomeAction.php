<?php

namespace App\Services\Bitrix24;

use App\Models\Bitrix24Connection;
use App\Models\Bitrix24SyncLog;
use Illuminate\Support\Facades\Log;

class LogBitrix24CallbackOutcomeAction
{
    public function __construct(
        private readonly SanitizeBitrix24LogPayloadAction $sanitizeLogPayload,
        private readonly SanitizeBitrix24ApplicationTokenPayloadAction $sanitizeApplicationTokenPayload,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(
        string $callbackType,
        string $status,
        string $operation,
        array $payload,
        ?string $eventName = null,
        ?string $fingerprint = null,
        ?string $errorMessage = null,
        ?Bitrix24Connection $connection = null,
    ): void {
        $sanitizedPayload = $this->sanitizeApplicationTokenPayload->handle(
            $this->sanitizeLogPayload->handle($payload),
        );

        Bitrix24SyncLog::query()->create([
            'connection_id' => $connection?->id,
            'direction' => Bitrix24SyncLog::DIRECTION_SYSTEM,
            'operation' => $operation,
            'entity_type' => $callbackType,
            'entity_id' => $eventName,
            'request_payload' => $sanitizedPayload,
            'status' => $status,
            'error_message' => $errorMessage,
            'fingerprint' => $fingerprint,
        ]);

        $message = 'bitrix24 callback '.$operation;

        if ($status === Bitrix24SyncLog::STATUS_FAILED) {
            Log::channel('bitrix24_callbacks')->warning($message, [
                'callback_type' => $callbackType,
                'event_name' => $eventName,
                'error' => $errorMessage,
                'fingerprint' => $fingerprint,
            ]);

            return;
        }

        Log::channel('bitrix24_callbacks')->info($message, [
            'callback_type' => $callbackType,
            'event_name' => $eventName,
            'status' => $status,
            'fingerprint' => $fingerprint,
        ]);
    }
}
