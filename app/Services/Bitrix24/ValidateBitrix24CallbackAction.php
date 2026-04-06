<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24AuthContextData;
use App\Data\Bitrix24\Bitrix24CallbackValidationResultData;
use App\Models\Bitrix24Connection;
use App\Models\Bitrix24WebhookEvent;

class ValidateBitrix24CallbackAction
{
    public function __construct(
        private readonly HashBitrix24ApplicationTokenAction $hashApplicationToken,
    ) {}

    public function handle(
        string $callbackType,
        Bitrix24AuthContextData $authContext,
        bool $looksLikeBitrix,
    ): Bitrix24CallbackValidationResultData {
        if (! $looksLikeBitrix) {
            return new Bitrix24CallbackValidationResultData(
                accepted: false,
                processingStatus: Bitrix24WebhookEvent::STATUS_IGNORED,
                reason: 'Payload does not look like a Bitrix24 callback.',
                connection: null,
            );
        }

        if (! filled($authContext->memberId) || ! filled($authContext->applicationToken)) {
            return new Bitrix24CallbackValidationResultData(
                accepted: false,
                processingStatus: Bitrix24WebhookEvent::STATUS_FAILED,
                reason: 'Missing member_id or application_token in callback auth context.',
                connection: $this->findRelatedConnection($authContext),
            );
        }

        $applicationTokenHash = $this->hashApplicationToken->handle($authContext->applicationToken);

        if ($applicationTokenHash === null) {
            return new Bitrix24CallbackValidationResultData(
                accepted: false,
                processingStatus: Bitrix24WebhookEvent::STATUS_FAILED,
                reason: 'Missing member_id or application_token in callback auth context.',
                connection: $this->findRelatedConnection($authContext),
            );
        }

        $connection = Bitrix24Connection::query()
            ->where('status', Bitrix24Connection::STATUS_ACTIVE)
            ->where('member_id', $authContext->memberId)
            ->where('application_token_hash', $applicationTokenHash)
            ->first();

        if (! $connection) {
            return new Bitrix24CallbackValidationResultData(
                accepted: false,
                processingStatus: Bitrix24WebhookEvent::STATUS_FAILED,
                reason: sprintf(
                    'No active Bitrix24 connection matched %s callback auth context.',
                    $callbackType,
                ),
                connection: $this->findRelatedConnection($authContext),
            );
        }

        return new Bitrix24CallbackValidationResultData(
            accepted: true,
            processingStatus: Bitrix24WebhookEvent::STATUS_PENDING,
            reason: null,
            connection: $connection,
        );
    }

    private function findRelatedConnection(Bitrix24AuthContextData $authContext): ?Bitrix24Connection
    {
        $query = Bitrix24Connection::query();

        if (filled($authContext->memberId)) {
            $query->where('member_id', $authContext->memberId);
        } elseif (filled($authContext->portalDomain)) {
            $query->where('portal_domain', $authContext->portalDomain);
        } else {
            return null;
        }

        return $query->first();
    }
}
