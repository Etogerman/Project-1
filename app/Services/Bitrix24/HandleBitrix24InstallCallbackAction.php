<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24CallbackHandlingResultData;
use App\Data\Bitrix24\Bitrix24InstallPayloadData;
use App\Data\Bitrix24\Bitrix24WebhookEventData;
use App\Jobs\ProcessBitrix24InstallCallbackJob;
use App\Models\Bitrix24Connection;
use App\Models\Bitrix24Profile;
use App\Models\Bitrix24SyncLog;
use App\Models\Bitrix24WebhookEvent;
use Illuminate\Http\Request;

class HandleBitrix24InstallCallbackAction
{
    public function __construct(
        private readonly NormalizeBitrix24WebhookPayloadAction $normalizeWebhookPayload,
        private readonly ResolveBitrix24CallbackIngressAction $resolveCallbackIngress,
        private readonly BuildBitrix24AuthContextAction $buildAuthContext,
        private readonly BuildBitrix24WebhookFingerprintAction $buildFingerprint,
        private readonly ValidateBitrix24InstallCallbackAction $validateInstallCallback,
        private readonly StoreBitrix24WebhookEventAction $storeWebhookEvent,
        private readonly UpsertBitrix24ConnectionFromInstallAction $upsertConnection,
        private readonly LogBitrix24CallbackOutcomeAction $logCallbackOutcome,
    ) {}

    public function handle(Request $request): Bitrix24CallbackHandlingResultData
    {
        $normalized = $this->normalizeWebhookPayload->handle($request);
        $ingress = $this->resolveCallbackIngress->handle($request);
        $authContext = $this->buildAuthContext->handle($request->all());
        $fingerprint = $this->buildFingerprint->handle($normalized['payload']);
        $installPayload = $this->buildInstallPayloadData($request->all(), $normalized['payload'], $authContext, $ingress->callbackBaseUrl);

        [$status, $reason] = $this->validateInstallCallback->handle(
            looksLikeBitrix: $normalized['looks_like_bitrix'],
            payload: $installPayload,
            profile: $ingress->profile,
        );

        $connection = null;

        if ($status === Bitrix24WebhookEvent::STATUS_PENDING) {
            $connection = $this->upsertConnection->handle($ingress->profile, $installPayload);
        } else {
            $connection = $this->findRelatedConnection($ingress->profile, $authContext);

            if ($connection) {
                $connection->forceFill([
                    'last_error_at' => now(),
                    'last_error_message' => $reason,
                ])->save();
            }
        }

        $storeResult = $this->storeWebhookEvent->handle(
            eventData: new Bitrix24WebhookEventData(
                callbackType: Bitrix24WebhookEvent::TYPE_INSTALL,
                eventName: $normalized['event_name'],
                authContext: $authContext,
                callbackBaseUrl: $ingress->callbackBaseUrl,
                payload: $normalized['payload'],
                headers: $normalized['headers'],
                query: $normalized['query'],
                payloadHash: $fingerprint,
            ),
            processingStatus: $status,
            failureReason: $reason,
            connection: $connection,
        );

        $event = $storeResult['event'];
        $duplicate = $storeResult['duplicate'];

        $logStatus = match (true) {
            $duplicate => Bitrix24SyncLog::STATUS_SKIPPED,
            $status === Bitrix24WebhookEvent::STATUS_FAILED => Bitrix24SyncLog::STATUS_FAILED,
            $status === Bitrix24WebhookEvent::STATUS_IGNORED => Bitrix24SyncLog::STATUS_SKIPPED,
            default => Bitrix24SyncLog::STATUS_SUCCESS,
        };

        $operation = match (true) {
            $duplicate => 'install_callback_duplicate',
            $status === Bitrix24WebhookEvent::STATUS_FAILED => 'install_callback_validation_failed',
            $status === Bitrix24WebhookEvent::STATUS_IGNORED => 'install_callback_ignored',
            default => 'install_callback_stored',
        };

        $this->logCallbackOutcome->handle(
            callbackType: Bitrix24WebhookEvent::TYPE_INSTALL,
            status: $logStatus,
            operation: $operation,
            payload: $normalized['payload'],
            eventName: $normalized['event_name'],
            fingerprint: $fingerprint,
            errorMessage: $reason,
            connection: $connection,
        );

        $dispatched = false;

        if (! $duplicate && $status === Bitrix24WebhookEvent::STATUS_PENDING) {
            ProcessBitrix24InstallCallbackJob::dispatch($event->id);
            $dispatched = true;
        }

        return new Bitrix24CallbackHandlingResultData(
            callbackType: Bitrix24WebhookEvent::TYPE_INSTALL,
            processingStatus: $status,
            stored: true,
            duplicate: $duplicate,
            dispatched: $dispatched,
            event: $event,
        );
    }

    /**
     * @param  array<string, mixed>  $rawPayload
     * @param  array<string, mixed>  $sanitizedPayload
     */
    private function buildInstallPayloadData(
        array $rawPayload,
        array $sanitizedPayload,
        \App\Data\Bitrix24\Bitrix24AuthContextData $authContext,
        ?string $callbackBaseUrl,
    ): Bitrix24InstallPayloadData {
        $auth = $this->caseInsensitiveValue($rawPayload, 'auth');

        if (! is_array($auth)) {
            $auth = [];
        }

        $scope = $this->caseInsensitiveValue($auth, 'scope');

        if (! is_array($scope)) {
            $scope = $this->caseInsensitiveValue($rawPayload, 'scope');
        }

        if (! is_array($scope)) {
            $scope = [];
        }

        $scope = array_values(array_filter($scope, fn (mixed $value): bool => is_scalar($value) && trim((string) $value) !== ''));

        return new Bitrix24InstallPayloadData(
            portalDomain: $authContext->portalDomain,
            callbackBaseUrl: $callbackBaseUrl,
            applicationToken: $authContext->applicationToken,
            memberId: $authContext->memberId,
            clientEndpoint: $authContext->clientEndpoint,
            serverEndpoint: $authContext->serverEndpoint,
            accessToken: $this->nullableString($this->caseInsensitiveValue($auth, 'access_token')),
            refreshToken: $this->nullableString($this->caseInsensitiveValue($auth, 'refresh_token')),
            expiresAt: $this->nullableString($this->caseInsensitiveValue($auth, 'expires') ?? $this->caseInsensitiveValue($auth, 'expires_at')),
            scope: $scope,
            rawPayload: $sanitizedPayload,
        );
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function caseInsensitiveValue(array $values, string $needle): mixed
    {
        $normalizedNeedle = mb_strtolower((string) $needle);

        foreach ($values as $key => $value) {
            if (mb_strtolower((string) $key) === $normalizedNeedle) {
                return $value;
            }
        }

        return null;
    }

    private function findRelatedConnection(?Bitrix24Profile $profile, \App\Data\Bitrix24\Bitrix24AuthContextData $authContext): ?Bitrix24Connection
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

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
