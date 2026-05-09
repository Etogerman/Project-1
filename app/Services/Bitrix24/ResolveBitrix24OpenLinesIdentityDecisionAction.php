<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24OpenLinesIdentityDecisionData;
use App\Data\Bitrix24\Bitrix24OpenLinesRouteData;
use App\Models\Bitrix24Connection;
use App\Models\Bitrix24SyncLog;
use App\Models\Message;
use App\Services\Contacts\ResolveRootContactAction;

class ResolveBitrix24OpenLinesIdentityDecisionAction
{
    private const LEGACY_LOOKUP_LOG_LIMIT = 200;

    public function __construct(
        private readonly ResolveRootContactAction $resolveRootContactAction,
        private readonly ResolveBitrix24LiveChatKeyAction $resolveLiveChatKeyAction,
        private readonly BuildBitrix24OpenLinesExternalUserIdAction $buildExternalUserIdAction,
        private readonly ResolveBitrix24OpenLinesDialogBindingAction $resolveDialogBindingAction,
    ) {}

    public function handle(
        Message $message,
        Bitrix24OpenLinesRouteData $route,
        Bitrix24Connection $connection,
    ): Bitrix24OpenLinesIdentityDecisionData {
        $message->loadMissing([
            'dialog.channel',
            'dialog.currentContactIdentity',
            'contact',
            'contactIdentity',
        ]);

        $dialog = $message->dialog()->firstOrFail();
        $rootContact = $this->resolveRootContactAction->handle($message->contact()->firstOrFail());
        $channel = $dialog->channel ?? $message->channel()->firstOrFail();
        $identity = $dialog->currentContactIdentity ?? $message->contactIdentity;
        $dialogBinding = $this->resolveDialogBindingAction->handle($dialog, $route);
        $payloadChatId = $dialogBinding?->connectorChatId ?? $this->resolveLiveChatKeyAction->handle($dialog);
        $channelAwareUserId = $this->buildExternalUserIdAction->handle(
            $channel,
            $identity?->external_user_id,
            $rootContact->id,
        );
        $legacyCandidates = $this->resolveLegacyExternalUserIds(
            $payloadChatId,
            $dialogBinding?->resolvedBitrixChatId,
            $route,
            $connection,
            $channelAwareUserId,
        );
        $legacyCandidateCount = count($legacyCandidates);

        if ($legacyCandidateCount === 1) {
            $legacyExternalUserId = array_values($legacyCandidates)[0];

            return new Bitrix24OpenLinesIdentityDecisionData(
                identityMode: Bitrix24OpenLinesIdentityDecisionData::MODE_LEGACY_EXTERNAL,
                userId: $legacyExternalUserId,
                decisionReason: Bitrix24OpenLinesIdentityDecisionData::REASON_LEGACY_EXTERNAL_FOUND,
                channelAwareUserId: $channelAwareUserId,
                legacyExternalUserId: $legacyExternalUserId,
                selectedUserCode: $dialogBinding?->userCode,
                selectedChatId: $dialogBinding?->resolvedBitrixChatId,
                payloadChatId: $payloadChatId,
                legacyCandidateCount: $legacyCandidateCount,
            );
        }

        return new Bitrix24OpenLinesIdentityDecisionData(
            identityMode: Bitrix24OpenLinesIdentityDecisionData::MODE_CHANNEL_AWARE,
            userId: $channelAwareUserId,
            decisionReason: $this->resolveFallbackDecisionReason($legacyCandidateCount, $dialogBinding !== null),
            channelAwareUserId: $channelAwareUserId,
            selectedUserCode: $dialogBinding?->userCode,
            selectedChatId: $dialogBinding?->resolvedBitrixChatId,
            payloadChatId: $payloadChatId,
            legacyCandidateCount: $legacyCandidateCount,
        );
    }

    /**
     * @return list<string>
     */
    private function resolveLegacyExternalUserIds(
        string $payloadChatId,
        ?string $expectedResolvedBitrixChatId,
        Bitrix24OpenLinesRouteData $route,
        Bitrix24Connection $connection,
        string $channelAwareUserId,
    ): array {
        $expectedResolvedBitrixChatId = $this->nonEmptyScalarString($expectedResolvedBitrixChatId);

        if ($expectedResolvedBitrixChatId === null) {
            return [];
        }

        $logs = Bitrix24SyncLog::query()
            ->where('connection_id', $connection->id)
            ->where('status', Bitrix24SyncLog::STATUS_SUCCESS)
            ->where('entity_type', 'rest_method')
            ->where('entity_id', 'imconnector.send.messages')
            ->where('request_payload->params->CONNECTOR', $route->connectorCode)
            ->where('request_payload->params->LINE', $route->lineId)
            ->where('request_payload->params->MESSAGES->0->chat->id', $payloadChatId)
            ->where('response_payload->result->DATA->RESULT->0->session->CHAT_ID', $expectedResolvedBitrixChatId)
            ->orderByDesc('id')
            ->limit(self::LEGACY_LOOKUP_LOG_LIMIT)
            ->get(['request_payload', 'response_payload']);

        $userIds = [];

        foreach ($logs as $log) {
            $messagePayload = data_get($log->request_payload, 'params.MESSAGES.0');

            if (! is_array($messagePayload)) {
                continue;
            }

            $chatId = $this->nonEmptyScalarString(data_get($messagePayload, 'chat.id'));

            if ($chatId !== $payloadChatId) {
                continue;
            }

            if (! $this->responseHasReturnedSessionChatId($log->response_payload, $expectedResolvedBitrixChatId)) {
                continue;
            }

            $userId = $this->nonEmptyScalarString(data_get($messagePayload, 'user.id'));

            if ($userId === null || $userId === $channelAwareUserId) {
                continue;
            }

            $userIds[$userId] = true;
        }

        return array_keys($userIds);
    }

    private function responseHasReturnedSessionChatId(mixed $responsePayload, string $expectedResolvedBitrixChatId): bool
    {
        if (! is_array($responsePayload)) {
            return false;
        }

        $items = data_get($responsePayload, 'result.DATA.RESULT');

        if (! is_array($items)) {
            return false;
        }

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $chatId = $this->nonEmptyScalarString(data_get($item, 'session.CHAT_ID'));

            if ($chatId === $expectedResolvedBitrixChatId) {
                return true;
            }
        }

        return false;
    }

    private function resolveFallbackDecisionReason(int $legacyCandidateCount, bool $hasSelectedBinding): string
    {
        if ($legacyCandidateCount > 1) {
            return Bitrix24OpenLinesIdentityDecisionData::REASON_LEGACY_EXTERNAL_AMBIGUOUS;
        }

        if ($hasSelectedBinding) {
            return Bitrix24OpenLinesIdentityDecisionData::REASON_LEGACY_EXTERNAL_MISSING;
        }

        return Bitrix24OpenLinesIdentityDecisionData::REASON_CHANNEL_AWARE_NEW_DIALOG;
    }

    private function nonEmptyScalarString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
