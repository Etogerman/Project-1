<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24AuthContextData;
use App\Data\Bitrix24\Bitrix24CallbackValidationResultData;
use App\Models\Bitrix24Connection;
use App\Models\Bitrix24Profile;
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
        ?string $callbackBaseUrl,
        ?Bitrix24Profile $profile,
    ): Bitrix24CallbackValidationResultData {
        if (! $looksLikeBitrix) {
            return new Bitrix24CallbackValidationResultData(
                accepted: false,
                processingStatus: Bitrix24WebhookEvent::STATUS_IGNORED,
                reason: 'Payload does not look like a Bitrix24 callback.',
                connection: null,
            );
        }

        if (! filled($callbackBaseUrl)) {
            return new Bitrix24CallbackValidationResultData(
                accepted: false,
                processingStatus: Bitrix24WebhookEvent::STATUS_FAILED,
                reason: 'Callback did not resolve a valid callback_base_url.',
                connection: null,
            );
        }

        if (! $profile) {
            return new Bitrix24CallbackValidationResultData(
                accepted: false,
                processingStatus: Bitrix24WebhookEvent::STATUS_FAILED,
                reason: sprintf(
                    'No Bitrix24 profile matched %s callback callback_base_url.',
                    $callbackType,
                ),
                connection: null,
            );
        }

        if (! $profile->allowsCallbackType($callbackType)) {
            return new Bitrix24CallbackValidationResultData(
                accepted: false,
                processingStatus: Bitrix24WebhookEvent::STATUS_FAILED,
                reason: sprintf(
                    'Bitrix24 profile `%s` does not allow %s callbacks.',
                    $profile->profile_key,
                    $callbackType,
                ),
                connection: $this->findRelatedConnection($profile, $authContext),
            );
        }

        if (! filled($authContext->memberId) || ! filled($authContext->applicationToken)) {
            return new Bitrix24CallbackValidationResultData(
                accepted: false,
                processingStatus: Bitrix24WebhookEvent::STATUS_FAILED,
                reason: 'Missing member_id or application_token in callback auth context.',
                connection: $this->findRelatedConnection($profile, $authContext),
            );
        }

        $applicationTokenHash = $this->hashApplicationToken->handle($authContext->applicationToken);

        if ($applicationTokenHash === null) {
            return new Bitrix24CallbackValidationResultData(
                accepted: false,
                processingStatus: Bitrix24WebhookEvent::STATUS_FAILED,
                reason: 'Missing member_id or application_token in callback auth context.',
                connection: $this->findRelatedConnection($profile, $authContext),
            );
        }

        $connection = Bitrix24Connection::query()
            ->where('profile_id', $profile->id)
            ->where('status', Bitrix24Connection::STATUS_ACTIVE)
            ->where('member_id', $authContext->memberId)
            ->where('application_token_hash', $applicationTokenHash)
            ->first();

        if (! $connection && $callbackType === Bitrix24WebhookEvent::TYPE_OPENLINES && $this->isConfiguredOpenLinesRuntimeToken($applicationTokenHash)) {
            $connection = Bitrix24Connection::query()
                ->where('profile_id', $profile->id)
                ->where('status', Bitrix24Connection::STATUS_ACTIVE)
                ->where('member_id', $authContext->memberId)
                ->first();
        }

        if (! $connection) {
            return new Bitrix24CallbackValidationResultData(
                accepted: false,
                processingStatus: Bitrix24WebhookEvent::STATUS_FAILED,
                reason: sprintf(
                    'No active Bitrix24 connection matched %s callback auth context.',
                    $callbackType,
                ),
                connection: $this->findRelatedConnection($profile, $authContext),
            );
        }

        return new Bitrix24CallbackValidationResultData(
            accepted: true,
            processingStatus: Bitrix24WebhookEvent::STATUS_PENDING,
            reason: null,
            connection: $connection,
        );
    }

    private function findRelatedConnection(?Bitrix24Profile $profile, Bitrix24AuthContextData $authContext): ?Bitrix24Connection
    {
        $query = Bitrix24Connection::query();

        if ($profile) {
            $query->where('profile_id', $profile->id);

            if (filled($authContext->memberId)) {
                $query->where('member_id', $authContext->memberId);
            }
        } elseif (filled($authContext->memberId)) {
            $query->where('member_id', $authContext->memberId);
        } elseif (filled($authContext->portalDomain)) {
            $query->where('portal_domain', $authContext->portalDomain);
        } else {
            return null;
        }

        return $query->first();
    }

    private function isConfiguredOpenLinesRuntimeToken(string $applicationTokenHash): bool
    {
        foreach ((array) config('bitrix24.openlines.runtime_application_token_hashes', []) as $configuredHash) {
            if (! is_scalar($configuredHash)) {
                continue;
            }

            $configuredHash = trim((string) $configuredHash);

            if ($configuredHash !== '' && hash_equals($configuredHash, $applicationTokenHash)) {
                return true;
            }
        }

        return false;
    }
}
