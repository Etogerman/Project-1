<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24OpenLinesManualReplyChatData;
use App\Data\Bitrix24\Bitrix24OpenLinesManualReplyExportData;
use App\Data\Bitrix24\Bitrix24OpenLinesRouteData;
use App\Data\Bitrix24\Bitrix24RestResponseData;
use App\Models\Bitrix24MessageExport;
use App\Models\Contact;
use App\Models\Dialog;
use App\Models\Message;

class ExportManualReplyToBitrix24OpenLinesAction
{
    public function __construct(
        private readonly ResolveBitrix24OpenLinesRouteAction $resolveBitrix24OpenLinesRouteAction,
        private readonly Bitrix24ApiClient $bitrix24ApiClient,
    ) {}

    public function handle(Message $message, Dialog $dialog, Contact $rootContact): Bitrix24OpenLinesManualReplyExportData
    {
        if ($message->message_kind !== Message::KIND_OUTBOUND_MANUAL_REPLY) {
            throw new Bitrix24OpenLinesManualReplyExportException(
                'Bitrix24 manual reply export expects a manual reply message kind.',
                Bitrix24MessageExport::FAILURE_MESSAGE_SEND_FAILED,
            );
        }

        $serviceUserId = (int) config('bitrix24.openlines.service_user_id', 0);

        if ($serviceUserId <= 0) {
            throw new Bitrix24OpenLinesManualReplyExportException(
                'Bitrix24 Open Lines service user id is not configured.',
                Bitrix24MessageExport::FAILURE_MESSAGE_SEND_FAILED,
            );
        }

        $route = $this->resolveBitrix24OpenLinesRouteAction->handle($dialog);
        $excludedChatIds = [];
        $activeChatRows = null;

        if ($reusableChat = $this->resolveReusableChat($dialog)) {
            try {
                $activeChatRows = $this->lookupActiveChatRows($rootContact);
            } catch (Bitrix24OpenLinesManualReplyExportException) {
                $activeChatRows = [];
                $excludedChatIds[] = $reusableChat->chatId;
            }

            if ($activeChatRows !== [] && ! $this->shouldExcludeReusableChat($reusableChat->chatId, $route, $activeChatRows)) {
                try {
                    return $this->sendMessage(
                        message: $message,
                        rootContact: $rootContact,
                        serviceUserId: $serviceUserId,
                        resolvedChat: $reusableChat,
                    );
                } catch (Bitrix24OpenLinesManualReplyExportException $exception) {
                    if (! $this->shouldContinueAfterReusableFailure($exception)) {
                        throw $exception;
                    }

                    $excludedChatIds[] = $reusableChat->chatId;
                }
            }
        }

        $resolvedChat = $this->resolveChat($dialog, $rootContact, $route, $excludedChatIds, $activeChatRows);

        return $this->sendMessage(
            message: $message,
            rootContact: $rootContact,
            serviceUserId: $serviceUserId,
            resolvedChat: $resolvedChat,
        );
    }

    private function resolveReusableChat(Dialog $dialog): ?Bitrix24OpenLinesManualReplyChatData
    {
        $chatId = Bitrix24MessageExport::query()
            ->join('messages', 'messages.id', '=', 'bitrix24_message_exports.message_id')
            ->where('messages.dialog_id', $dialog->id)
            ->where('bitrix24_message_exports.export_mode', Bitrix24MessageExport::MODE_LIVE)
            ->where('bitrix24_message_exports.export_status', Bitrix24MessageExport::STATUS_EXPORTED)
            ->where('bitrix24_message_exports.transport_method', Bitrix24MessageExport::TRANSPORT_IMOPENLINES_CRM_MESSAGE_ADD)
            ->whereNotNull('bitrix24_message_exports.resolved_bitrix_chat_id')
            ->latest('bitrix24_message_exports.id')
            ->value('bitrix24_message_exports.resolved_bitrix_chat_id');

        if (! is_scalar($chatId) || trim((string) $chatId) === '') {
            return null;
        }

        return new Bitrix24OpenLinesManualReplyChatData(
            chatId: trim((string) $chatId),
            usedFallback: false,
        );
    }

    private function shouldContinueAfterReusableFailure(Bitrix24OpenLinesManualReplyExportException $exception): bool
    {
        if ($exception->failureUncertain || $exception->failureCode === Bitrix24MessageExport::FAILURE_FAILED_UNCERTAIN) {
            return false;
        }

        return in_array($exception->failureCode, [
            Bitrix24MessageExport::FAILURE_CHAT_ACCESS_DENIED,
            Bitrix24MessageExport::FAILURE_CHAT_USER_ADD_FAILED,
            Bitrix24MessageExport::FAILURE_MESSAGE_SEND_FAILED,
        ], true);
    }

    private function resolveChat(
        Dialog $dialog,
        Contact $rootContact,
        Bitrix24OpenLinesRouteData $route,
        array $excludedChatIds = [],
        ?array $activeChatRows = null,
    ): Bitrix24OpenLinesManualReplyChatData {
        $candidateChats = $this->excludeChatIds(
            $activeChatRows ?? $this->lookupActiveChatRows($rootContact),
            $excludedChatIds,
        );

        if (count($candidateChats) === 1) {
            $connectorId = $this->extractConnectorId($candidateChats[0]);

            if ($connectorId !== null && $connectorId !== $route->connectorCode) {
                return $this->resolveFallbackChat($dialog, $route);
            }

            return new Bitrix24OpenLinesManualReplyChatData(
                chatId: $this->extractChatId($candidateChats[0]) ?? throw new Bitrix24OpenLinesManualReplyExportException(
                    'Bitrix24 Open Lines active chat lookup returned a chat without CHAT_ID.',
                    Bitrix24MessageExport::FAILURE_FAILED_UNCERTAIN,
                    true,
                ),
                usedFallback: false,
            );
        }

        if (count($candidateChats) > 1) {
            $connectorMatchedChats = array_values(array_filter(
                $candidateChats,
                fn (array $chat): bool => $this->extractConnectorId($chat) === $route->connectorCode,
            ));

            if (count($connectorMatchedChats) === 1) {
                return new Bitrix24OpenLinesManualReplyChatData(
                    chatId: $this->extractChatId($connectorMatchedChats[0]) ?? throw new Bitrix24OpenLinesManualReplyExportException(
                        'Bitrix24 Open Lines filtered chat lookup returned a chat without CHAT_ID.',
                        Bitrix24MessageExport::FAILURE_FAILED_UNCERTAIN,
                        true,
                    ),
                    usedFallback: false,
                );
            }

            throw new Bitrix24OpenLinesManualReplyExportException(
                'Bitrix24 Open Lines active chat lookup is ambiguous for this manual reply.',
                Bitrix24MessageExport::FAILURE_AMBIGUOUS_CHAT,
            );
        }

        return $this->resolveFallbackChat($dialog, $route);
    }

    /**
     * @param  list<array<string, mixed>>  $candidateChats
     * @param  list<string>  $excludedChatIds
     * @return list<array<string, mixed>>
     */
    private function excludeChatIds(array $candidateChats, array $excludedChatIds): array
    {
        if ($excludedChatIds === []) {
            return $candidateChats;
        }

        $excluded = array_fill_keys($excludedChatIds, true);

        return array_values(array_filter(
            $candidateChats,
            fn (array $chat): bool => ! isset($excluded[$this->extractChatId($chat) ?? '']),
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lookupActiveChatRows(Contact $rootContact): array
    {
        try {
            $response = $this->bitrix24ApiClient->call('imopenlines.crm.chat.get', array_merge(
                $this->crmEntityParams($rootContact),
                ['ACTIVE_ONLY' => 'Y'],
            ));
        } catch (Bitrix24ApiException $exception) {
            throw new Bitrix24OpenLinesManualReplyExportException(
                'Bitrix24 Open Lines active chat lookup failed.',
                Bitrix24MessageExport::FAILURE_MESSAGE_SEND_FAILED,
                false,
                $exception,
            );
        }

        if (! $response->successful) {
            throw new Bitrix24OpenLinesManualReplyExportException(
                sprintf(
                    'Bitrix24 Open Lines active chat lookup failed: %s',
                    $response->errorMessage ?? 'Unknown error.'
                ),
                $this->isUncertainResponse($response)
                    ? Bitrix24MessageExport::FAILURE_FAILED_UNCERTAIN
                    : Bitrix24MessageExport::FAILURE_MESSAGE_SEND_FAILED,
                $this->isUncertainResponse($response),
            );
        }

        return $this->extractChatRows($response->result);
    }

    /**
     * @param  list<array<string, mixed>>  $activeChatRows
     */
    private function shouldExcludeReusableChat(
        string $chatId,
        Bitrix24OpenLinesRouteData $route,
        array $activeChatRows,
    ): bool {
        $matchingChats = array_values(array_filter(
            $activeChatRows,
            fn (array $chat): bool => $this->extractChatId($chat) === $chatId,
        ));

        if ($matchingChats === []) {
            return false;
        }

        foreach ($matchingChats as $chat) {
            $connectorId = $this->extractConnectorId($chat);

            if ($connectorId === null || $connectorId === $route->connectorCode) {
                return false;
            }
        }

        return true;
    }

    private function resolveFallbackChat(
        Dialog $dialog,
        Bitrix24OpenLinesRouteData $route,
    ): Bitrix24OpenLinesManualReplyChatData {
        $dialog->loadMissing('currentContactIdentity');

        $externalUserId = (string) ($dialog->currentContactIdentity?->external_user_id ?? '');
        $externalChatId = trim((string) $dialog->external_chat_id);

        if ($externalChatId === '' || $externalUserId === '') {
            throw new Bitrix24OpenLinesManualReplyExportException(
                'Bitrix24 Open Lines session fallback is unavailable for this manual reply.',
                Bitrix24MessageExport::FAILURE_SESSION_OPEN_UNAVAILABLE,
            );
        }

        $userCode = implode('|', [
            $route->connectorCode,
            $route->lineId,
            $externalChatId,
            $externalUserId,
        ]);

        try {
            $response = $this->bitrix24ApiClient->call(
                'imopenlines.session.open',
                [
                    'USER_CODE' => $userCode,
                ],
                connection: null,
                transportRetry: false,
            );
        } catch (Bitrix24ApiException $exception) {
            throw new Bitrix24OpenLinesManualReplyExportException(
                'Bitrix24 Open Lines session fallback transport outcome is uncertain.',
                Bitrix24MessageExport::FAILURE_FAILED_UNCERTAIN,
                true,
                $exception,
            );
        }

        if (! $response->successful) {
            throw new Bitrix24OpenLinesManualReplyExportException(
                sprintf(
                    'Bitrix24 Open Lines session fallback failed: %s',
                    $response->errorMessage ?? 'Unknown error.'
                ),
                $this->isUncertainResponse($response)
                    ? Bitrix24MessageExport::FAILURE_FAILED_UNCERTAIN
                    : Bitrix24MessageExport::FAILURE_SESSION_OPEN_FAILED,
                $this->isUncertainResponse($response),
            );
        }

        $chatId = $this->extractSessionChatId($response->result);

        if ($chatId === null) {
            throw new Bitrix24OpenLinesManualReplyExportException(
                'Bitrix24 Open Lines session fallback did not return chat id.',
                Bitrix24MessageExport::FAILURE_SESSION_OPEN_FAILED,
            );
        }

        return new Bitrix24OpenLinesManualReplyChatData(
            chatId: $chatId,
            usedFallback: true,
        );
    }

    private function sendMessage(
        Message $message,
        Contact $rootContact,
        int $serviceUserId,
        Bitrix24OpenLinesManualReplyChatData $resolvedChat,
        bool $allowRecovery = true,
        bool $usedChatUserAddRecovery = false,
    ): Bitrix24OpenLinesManualReplyExportData {
        $messageText = trim((string) $message->text);

        if ($messageText === '') {
            throw new Bitrix24OpenLinesManualReplyExportException(
                'Bitrix24 Open Lines manual reply text is empty.',
                Bitrix24MessageExport::FAILURE_MESSAGE_SEND_FAILED,
            );
        }

        try {
            $response = $this->bitrix24ApiClient->call(
                'imopenlines.crm.message.add',
                array_merge($this->crmEntityParams($rootContact), [
                    'USER_ID' => $serviceUserId,
                    'CHAT_ID' => $resolvedChat->chatId,
                    'MESSAGE' => $messageText,
                ]),
                connection: null,
                transportRetry: false,
            );
        } catch (Bitrix24ApiException $exception) {
            throw new Bitrix24OpenLinesManualReplyExportException(
                'Bitrix24 Open Lines manual reply transport outcome is uncertain.',
                Bitrix24MessageExport::FAILURE_FAILED_UNCERTAIN,
                true,
                $exception,
            );
        }

        if ($response->successful) {
            $remoteMessageId = $this->extractRemoteMessageId($response->result);

            if ($remoteMessageId === null) {
                throw new Bitrix24OpenLinesManualReplyExportException(
                    'Bitrix24 Open Lines manual reply did not return a remote message id.',
                    Bitrix24MessageExport::FAILURE_FAILED_UNCERTAIN,
                    true,
                );
            }

            return new Bitrix24OpenLinesManualReplyExportData(
                resolvedBitrixChatId: $resolvedChat->chatId,
                bitrixRemoteMessageId: $remoteMessageId,
                usedFallback: $resolvedChat->usedFallback,
                usedChatUserAddRecovery: $usedChatUserAddRecovery,
            );
        }

        if ($allowRecovery && $this->isAccessDeniedResponse($response)) {
            $this->recoverChatAccess($rootContact, $serviceUserId, $resolvedChat->chatId);

            return $this->sendMessage(
                message: $message,
                rootContact: $rootContact,
                serviceUserId: $serviceUserId,
                resolvedChat: $resolvedChat,
                allowRecovery: false,
                usedChatUserAddRecovery: true,
            );
        }

        if ($this->isUncertainResponse($response)) {
            throw new Bitrix24OpenLinesManualReplyExportException(
                sprintf(
                    'Bitrix24 Open Lines manual reply transport outcome is uncertain: %s',
                    $response->errorMessage ?? 'Unknown error.'
                ),
                Bitrix24MessageExport::FAILURE_FAILED_UNCERTAIN,
                true,
            );
        }

        throw new Bitrix24OpenLinesManualReplyExportException(
            sprintf(
                'Bitrix24 Open Lines manual reply export failed: %s',
                $response->errorMessage ?? 'Unknown error.'
            ),
            $this->isAccessDeniedResponse($response)
                ? Bitrix24MessageExport::FAILURE_CHAT_ACCESS_DENIED
                : Bitrix24MessageExport::FAILURE_MESSAGE_SEND_FAILED,
        );
    }

    private function recoverChatAccess(Contact $rootContact, int $serviceUserId, string $chatId): void
    {
        try {
            $response = $this->bitrix24ApiClient->call(
                'imopenlines.crm.chat.user.add',
                array_merge($this->crmEntityParams($rootContact), [
                    'CHAT_ID' => $chatId,
                    'USER_ID' => $serviceUserId,
                ]),
                connection: null,
                transportRetry: false,
            );
        } catch (Bitrix24ApiException $exception) {
            throw new Bitrix24OpenLinesManualReplyExportException(
                'Bitrix24 Open Lines chat user add transport outcome is uncertain.',
                Bitrix24MessageExport::FAILURE_FAILED_UNCERTAIN,
                true,
                $exception,
            );
        }

        if ($response->successful) {
            return;
        }

        if ($this->isUncertainResponse($response)) {
            throw new Bitrix24OpenLinesManualReplyExportException(
                sprintf(
                    'Bitrix24 Open Lines chat user add transport outcome is uncertain: %s',
                    $response->errorMessage ?? 'Unknown error.'
                ),
                Bitrix24MessageExport::FAILURE_FAILED_UNCERTAIN,
                true,
            );
        }

        throw new Bitrix24OpenLinesManualReplyExportException(
            sprintf(
                'Bitrix24 Open Lines chat user add failed: %s',
                $response->errorMessage ?? 'Unknown error.'
            ),
            Bitrix24MessageExport::FAILURE_CHAT_USER_ADD_FAILED,
        );
    }

    /**
     * @return array{CRM_ENTITY_TYPE: string, CRM_ENTITY: string}
     */
    private function crmEntityParams(Contact $rootContact): array
    {
        return [
            'CRM_ENTITY_TYPE' => 'CONTACT',
            'CRM_ENTITY' => (string) $rootContact->bitrix24_contact_id,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function extractChatRows(mixed $result): array
    {
        if (! is_array($result)) {
            return [];
        }

        $chatRows = null;

        if (array_is_list($result)) {
            $chatRows = $result;
        } else {
            foreach (['chats', 'CHATS', 'result', 'RESULT', 'items', 'ITEMS'] as $key) {
                $value = $result[$key] ?? null;

                if (is_array($value)) {
                    $chatRows = $value;
                    break;
                }
            }

            if ($chatRows === null && $this->extractChatId($result) !== null) {
                $chatRows = [$result];
            }
        }

        if (! is_array($chatRows)) {
            return [];
        }

        return array_values(array_filter(
            $chatRows,
            fn (mixed $row): bool => is_array($row) && $this->extractChatId($row) !== null,
        ));
    }

    private function extractChatId(array $chat): ?string
    {
        foreach (['CHAT_ID', 'chat_id', 'chatId', 'ID', 'id'] as $key) {
            $value = $chat[$key] ?? null;

            if (! is_scalar($value)) {
                continue;
            }

            $normalized = trim((string) $value);

            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }

    private function extractConnectorId(array $chat): ?string
    {
        foreach (['CONNECTOR_ID', 'connector_id', 'connectorId', 'CONNECTOR'] as $key) {
            $value = $chat[$key] ?? null;

            if (! is_scalar($value)) {
                continue;
            }

            $normalized = trim((string) $value);

            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }

    private function extractSessionChatId(mixed $result): ?string
    {
        if (is_scalar($result)) {
            $normalized = trim((string) $result);

            return $normalized === '' ? null : $normalized;
        }

        if (! is_array($result)) {
            return null;
        }

        foreach (['CHAT_ID', 'chatId', 'chat_id', 'ID', 'id'] as $key) {
            $value = $result[$key] ?? null;

            if (! is_scalar($value)) {
                continue;
            }

            $normalized = trim((string) $value);

            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }

    private function extractRemoteMessageId(mixed $result): ?string
    {
        if (is_scalar($result)) {
            $normalized = trim((string) $result);

            return $normalized === '' ? null : $normalized;
        }

        if (! is_array($result)) {
            return null;
        }

        foreach (['MESSAGE_ID', 'message_id', 'messageId', 'ID', 'id'] as $key) {
            $value = $result[$key] ?? null;

            if (! is_scalar($value)) {
                continue;
            }

            $normalized = trim((string) $value);

            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }

    private function isAccessDeniedResponse(Bitrix24RestResponseData $response): bool
    {
        if ($response->errorCode === 'CANCELED') {
            return true;
        }

        $errorMessage = mb_strtolower(trim((string) ($response->errorMessage ?? '')));

        return $errorMessage !== ''
            && (
                str_contains($errorMessage, 'access')
                || str_contains($errorMessage, 'permission')
                || str_contains($errorMessage, 'denied')
            );
    }

    private function isUncertainResponse(Bitrix24RestResponseData $response): bool
    {
        return $response->httpStatus === null || $response->httpStatus >= 500;
    }
}
