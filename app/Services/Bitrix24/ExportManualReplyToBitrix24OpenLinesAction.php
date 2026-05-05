<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24CurrentOpenLineChatData;
use App\Data\Bitrix24\Bitrix24OpenLinesManualReplyChatData;
use App\Data\Bitrix24\Bitrix24OpenLinesManualReplyExportData;
use App\Data\Bitrix24\Bitrix24OpenLinesRouteData;
use App\Data\Bitrix24\Bitrix24RestResponseData;
use App\Models\Bitrix24Connection;
use App\Models\Bitrix24MessageExport;
use App\Models\Contact;
use App\Models\Dialog;
use App\Models\Message;

class ExportManualReplyToBitrix24OpenLinesAction
{
    public function __construct(
        private readonly ResolveBitrix24OpenLinesRouteAction $resolveBitrix24OpenLinesRouteAction,
        private readonly ResolveCurrentBitrix24ConnectionAction $resolveCurrentConnectionAction,
        private readonly Bitrix24ApiClient $bitrix24ApiClient,
        private readonly ResolveBitrix24OpenLinesDialogBindingAction $resolveDialogBindingAction,
        private readonly GuardBitrix24OpenLineMutationAction $guardOpenLineMutationAction,
        private readonly ResolveCurrentBitrix24OpenLineChatAction $resolveCurrentOpenLineChatAction,
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
        $connection = $this->resolveCurrentConnectionAction->handle();
        $dialogBinding = $this->resolveDialogBindingAction->handle($dialog, $route);
        $excludedChatIds = [];

        if ($dialogBinding !== null) {
            if ($dialogBinding->resolvedBitrixChatId === null) {
                throw new Bitrix24OpenLinesManualReplyExportException(
                    'Bitrix24 Open Lines verified dialog binding is missing resolved chat id.',
                    Bitrix24MessageExport::FAILURE_MESSAGE_SEND_FAILED,
                );
            }

            $resolvedChat = $this->resolveDialogChat(
                $dialog,
                $route,
                $connection,
                $dialogBinding->resolvedBitrixChatId,
            );

            if (! $resolvedChat instanceof Bitrix24OpenLinesManualReplyChatData) {
                throw new Bitrix24OpenLinesManualReplyExportException(
                    'Bitrix24 Open Lines verified dialog binding could not be resolved.',
                    Bitrix24MessageExport::FAILURE_MESSAGE_SEND_FAILED,
                );
            }

            $resolvedChat = $this->preflightVerifiedBindingChatBeforeSend(
                $dialog,
                $rootContact,
                $route,
                $connection,
                $dialogBinding->resolvedBitrixChatId,
                $dialogBinding->userCode,
                $resolvedChat,
            );

            try {
                return $this->sendMessage(
                    message: $message,
                    dialog: $dialog,
                    rootContact: $rootContact,
                    route: $route,
                    connection: $connection,
                    serviceUserId: $serviceUserId,
                    resolvedChat: $resolvedChat,
                    allowDialogBindingRecovery: false,
                );
            } catch (Bitrix24OpenLinesManualReplyExportException $exception) {
                throw new Bitrix24OpenLinesManualReplyExportException(
                    'Bitrix24 Open Lines verified dialog binding send failed: '.$exception->getMessage(),
                    $this->resolveVerifiedBindingFailureCode($exception),
                    $exception->failureUncertain,
                    $exception,
                );
            }
        }

        if ($reusableChat = $this->resolveReusableChat($dialog)) {
            $currentRouteChat = $this->resolveCurrentRouteChat($dialog, $route, $connection);

            if ($currentRouteChat['resolved'] && $currentRouteChat['chatId'] === $reusableChat->chatId) {
                try {
                    return $this->sendMessage(
                        message: $message,
                        dialog: $dialog,
                        rootContact: $rootContact,
                        route: $route,
                        connection: $connection,
                        serviceUserId: $serviceUserId,
                        resolvedChat: $reusableChat,
                    );
                } catch (Bitrix24OpenLinesManualReplyExportException $exception) {
                    if (! $this->shouldContinueAfterReusableFailure($exception)) {
                        throw $exception;
                    }

                    $excludedChatIds[] = $reusableChat->chatId;
                }
            } elseif ($currentRouteChat['resolved']) {
                $excludedChatIds[] = $reusableChat->chatId;
            }
        }

        $resolvedChat = $this->resolveChat(
            $dialog,
            $rootContact,
            $route,
            $connection,
            $excludedChatIds,
        );

        return $this->sendMessage(
            message: $message,
            dialog: $dialog,
            rootContact: $rootContact,
            route: $route,
            connection: $connection,
            serviceUserId: $serviceUserId,
            resolvedChat: $resolvedChat,
        );
    }

    private function shouldFallbackVerifiedBindingToLegacyTransport(
        Bitrix24OpenLinesManualReplyExportException $exception,
    ): bool {
        if ($exception->failureUncertain || $exception->failureCode !== Bitrix24MessageExport::FAILURE_MESSAGE_SEND_FAILED) {
            return false;
        }

        $message = mb_strtolower($exception->getMessage());

        return str_contains($message, 'chat does not belong to the crm entity')
            || str_contains($message, 'chat does not belong');
    }

    private function resolveVerifiedBindingFailureCode(Bitrix24OpenLinesManualReplyExportException $exception): string
    {
        if (in_array($exception->failureCode, [
            Bitrix24MessageExport::FAILURE_FAILED_UNCERTAIN,
            Bitrix24MessageExport::FAILURE_OPEN_LINE_GUARD_LOOKUP_FAILED,
            Bitrix24MessageExport::FAILURE_VERIFIED_BINDING_CRM_MESSAGE_ADD_UNAVAILABLE,
        ], true)) {
            return $exception->failureCode;
        }

        return $this->shouldFallbackVerifiedBindingToLegacyTransport($exception)
            ? Bitrix24MessageExport::FAILURE_VERIFIED_BINDING_CRM_MESSAGE_ADD_UNAVAILABLE
            : Bitrix24MessageExport::FAILURE_MESSAGE_SEND_FAILED;
    }

    private function preflightVerifiedBindingChatBeforeSend(
        Dialog $dialog,
        Contact $rootContact,
        Bitrix24OpenLinesRouteData $route,
        Bitrix24Connection $connection,
        string $expectedResolvedBitrixChatId,
        string $expectedUserCode,
        Bitrix24OpenLinesManualReplyChatData $resolvedChat,
    ): Bitrix24OpenLinesManualReplyChatData {
        try {
            $this->guardOpenLineMutationAction->assertVerifiedBindingChatIsActiveForContact(
                $dialog,
                $rootContact,
                $route,
                $connection,
                $expectedResolvedBitrixChatId,
                $expectedUserCode,
            );

            return $resolvedChat;
        } catch (Bitrix24OpenLineMutationGuardException $exception) {
            if (! $this->syncVerifiedBindingToCurrentChatBeforeSend(
                $dialog,
                $route,
                $connection,
                $expectedResolvedBitrixChatId,
                $exception,
            )) {
                throw $this->manualReplyExceptionFromGuard($exception);
            }
        }

        $dialog->refresh();
        $dialogBinding = $this->resolveDialogBindingAction->handle($dialog, $route);

        if ($dialogBinding?->resolvedBitrixChatId === null) {
            throw new Bitrix24OpenLinesManualReplyExportException(
                'Bitrix24 Open Lines verified dialog binding is missing resolved chat id after current chat sync.',
                Bitrix24MessageExport::FAILURE_MESSAGE_SEND_FAILED,
            );
        }

        $refreshedChat = $this->resolveDialogChat(
            $dialog,
            $route,
            $connection,
            $dialogBinding->resolvedBitrixChatId,
        );

        if (! $refreshedChat instanceof Bitrix24OpenLinesManualReplyChatData) {
            throw new Bitrix24OpenLinesManualReplyExportException(
                'Bitrix24 Open Lines verified dialog binding could not be resolved after current chat sync.',
                Bitrix24MessageExport::FAILURE_MESSAGE_SEND_FAILED,
            );
        }

        try {
            $this->guardOpenLineMutationAction->assertVerifiedBindingChatIsActiveForContact(
                $dialog,
                $rootContact,
                $route,
                $connection,
                $dialogBinding->resolvedBitrixChatId,
                $dialogBinding->userCode,
            );
        } catch (Bitrix24OpenLineMutationGuardException $exception) {
            throw $this->manualReplyExceptionFromGuard($exception);
        }

        return $refreshedChat;
    }

    private function syncVerifiedBindingToCurrentChatBeforeSend(
        Dialog $dialog,
        Bitrix24OpenLinesRouteData $route,
        Bitrix24Connection $connection,
        string $expectedResolvedBitrixChatId,
        Bitrix24OpenLineMutationGuardException $exception,
    ): bool {
        if (! $this->isStaleVerifiedBindingGuardFailure($exception)) {
            return false;
        }

        $activeChatId = $this->positiveIntegerString($exception->relatedChatId);

        if ($activeChatId === null) {
            return false;
        }

        try {
            $currentChat = $this->resolveCurrentOpenLineChatAction->handle($dialog, $route, $connection);
        } catch (Bitrix24ApiException $lookupException) {
            throw new Bitrix24OpenLinesManualReplyExportException(
                'Bitrix24 Open Lines verified binding current chat lookup failed before mutating export.',
                Bitrix24MessageExport::FAILURE_OPEN_LINE_GUARD_LOOKUP_FAILED,
                false,
                $lookupException,
            );
        }

        if (
            ! $currentChat instanceof Bitrix24CurrentOpenLineChatData
            || $currentChat->chatId !== $activeChatId
            || $currentChat->chatId === $expectedResolvedBitrixChatId
        ) {
            return false;
        }

        $this->syncVerifiedBindingToCurrentChat($dialog, $currentChat);

        return true;
    }

    private function isStaleVerifiedBindingGuardFailure(Bitrix24OpenLineMutationGuardException $exception): bool
    {
        return $exception->failureCode === Bitrix24MessageExport::FAILURE_MESSAGE_SEND_FAILED
            && $exception->relatedChatId !== null;
    }

    private function syncVerifiedBindingToCurrentChat(Dialog $dialog, Bitrix24CurrentOpenLineChatData $currentChat): void
    {
        $dialog->forceFill([
            'bitrix24_open_line_user_code_override' => $currentChat->userCode,
            'bitrix24_open_line_resolved_chat_id_override' => $currentChat->chatId,
            'bitrix24_open_line_binding_verified_at' => now(),
        ])->save();
    }

    private function manualReplyExceptionFromGuard(
        Bitrix24OpenLineMutationGuardException $exception,
    ): Bitrix24OpenLinesManualReplyExportException {
        return new Bitrix24OpenLinesManualReplyExportException(
            $exception->getMessage(),
            $exception->failureCode,
            $exception->failureUncertain,
            $exception,
        );
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

    private function resolveReusableChat(Dialog $dialog): ?Bitrix24OpenLinesManualReplyChatData
    {
        $reusableExport = Bitrix24MessageExport::query()
            ->join('messages', 'messages.id', '=', 'bitrix24_message_exports.message_id')
            ->where('messages.dialog_id', $dialog->id)
            ->where('bitrix24_message_exports.export_mode', Bitrix24MessageExport::MODE_LIVE)
            ->where('bitrix24_message_exports.export_status', Bitrix24MessageExport::STATUS_EXPORTED)
            ->where('bitrix24_message_exports.transport_method', Bitrix24MessageExport::TRANSPORT_IMOPENLINES_CRM_MESSAGE_ADD)
            ->whereNotNull('bitrix24_message_exports.resolved_bitrix_chat_id')
            ->orderByDesc('bitrix24_message_exports.exported_at')
            ->orderByDesc('bitrix24_message_exports.id')
            ->first([
                'bitrix24_message_exports.resolved_bitrix_chat_id',
                'bitrix24_message_exports.transport_method',
            ]);

        $chatId = $reusableExport?->getAttribute('resolved_bitrix_chat_id');

        if (! is_scalar($chatId) || trim((string) $chatId) === '') {
            return null;
        }

        return $this->makeResolvedChat(
            dialog: $dialog,
            chatId: trim((string) $chatId),
            usedFallback: false,
        );
    }

    private function shouldContinueAfterReusableFailure(Bitrix24OpenLinesManualReplyExportException $exception): bool
    {
        if ($exception->failureUncertain || $exception->failureCode === Bitrix24MessageExport::FAILURE_FAILED_UNCERTAIN) {
            return false;
        }

        if (in_array($exception->failureCode, [
            Bitrix24MessageExport::FAILURE_CHAT_ACCESS_DENIED,
            Bitrix24MessageExport::FAILURE_CHAT_USER_ADD_FAILED,
        ], true)) {
            return true;
        }

        if ($exception->failureCode !== Bitrix24MessageExport::FAILURE_MESSAGE_SEND_FAILED) {
            return false;
        }

        $failureMessage = mb_strtolower($exception->getMessage());

        return str_contains($failureMessage, 'no longer available')
            || str_contains($failureMessage, 'invalid chat');
    }

    private function resolveChat(
        Dialog $dialog,
        Contact $rootContact,
        Bitrix24OpenLinesRouteData $route,
        Bitrix24Connection $connection,
        array $excludedChatIds = [],
    ): Bitrix24OpenLinesManualReplyChatData {
        $candidateChats = $this->excludeChatIds(
            $this->lookupActiveChatRows($rootContact, $connection),
            $excludedChatIds,
        );

        if (count($candidateChats) === 1) {
            $connectorId = $this->extractConnectorId($candidateChats[0]);

            if ($connectorId !== null && $connectorId !== $route->connectorCode) {
                return $this->resolveFallbackChat($dialog, $rootContact, $route, $connection, $candidateChats);
            }

            return $this->makeResolvedChat(
                dialog: $dialog,
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
                return $this->makeResolvedChat(
                    dialog: $dialog,
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

        return $this->resolveFallbackChat($dialog, $rootContact, $route, $connection, $candidateChats);
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
    private function lookupActiveChatRows(Contact $rootContact, Bitrix24Connection $connection): array
    {
        try {
            $response = $this->bitrix24ApiClient->call('imopenlines.crm.chat.get', array_merge(
                $this->crmEntityParams($rootContact),
                ['ACTIVE_ONLY' => 'Y'],
            ), $connection);
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

    private function resolveFallbackChat(
        Dialog $dialog,
        Contact $rootContact,
        Bitrix24OpenLinesRouteData $route,
        Bitrix24Connection $connection,
        array $activeChatRows,
    ): Bitrix24OpenLinesManualReplyChatData {
        $userCode = $this->buildUserCode($dialog, $route);

        if ($userCode === null) {
            throw new Bitrix24OpenLinesManualReplyExportException(
                'Bitrix24 Open Lines session fallback is unavailable for this manual reply.',
                Bitrix24MessageExport::FAILURE_SESSION_OPEN_UNAVAILABLE,
            );
        }

        try {
            $this->guardOpenLineMutationAction->handle(
                $dialog,
                $rootContact,
                $route,
                $connection,
                $activeChatRows,
            );
        } catch (Bitrix24OpenLineMutationGuardException $exception) {
            throw new Bitrix24OpenLinesManualReplyExportException(
                $exception->getMessage(),
                $exception->failureCode,
                $exception->failureUncertain,
                $exception,
            );
        }

        try {
            $response = $this->bitrix24ApiClient->call(
                'imopenlines.session.open',
                [
                    'USER_CODE' => $userCode,
                ],
                connection: $connection,
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

        return $this->makeResolvedChat(
            dialog: $dialog,
            chatId: $chatId,
            usedFallback: true,
        );
    }

    /**
     * @return array{resolved: bool, chatId: ?string}
     */
    private function resolveCurrentRouteChat(
        Dialog $dialog,
        Bitrix24OpenLinesRouteData $route,
        Bitrix24Connection $connection,
    ): array {
        $userCode = $this->buildUserCode($dialog, $route);

        if ($userCode === null) {
            return [
                'resolved' => false,
                'chatId' => null,
            ];
        }

        try {
            $response = $this->bitrix24ApiClient->call(
                'imopenlines.dialog.get',
                ['USER_CODE' => $userCode],
                connection: $connection,
                transportRetry: false,
            );
        } catch (Bitrix24ApiException) {
            return [
                'resolved' => false,
                'chatId' => null,
            ];
        }

        if (! $response->successful || ! is_array($response->result)) {
            return [
                'resolved' => false,
                'chatId' => null,
            ];
        }

        $chatId = $response->result['id'] ?? null;

        if (! is_scalar($chatId) || trim((string) $chatId) === '') {
            return [
                'resolved' => false,
                'chatId' => null,
            ];
        }

        return [
            'resolved' => true,
            'chatId' => trim((string) $chatId),
        ];
    }

    private function buildUserCode(Dialog $dialog, Bitrix24OpenLinesRouteData $route): ?string
    {
        if ($binding = $this->resolveDialogBindingAction->handle($dialog, $route)) {
            return $binding->userCode;
        }

        $dialog->loadMissing('currentContactIdentity');

        $externalUserId = (string) ($dialog->currentContactIdentity?->external_user_id ?? '');
        $externalChatId = trim((string) $dialog->external_chat_id);

        if ($externalChatId === '' || $externalUserId === '') {
            return null;
        }

        return implode('|', [
            $route->connectorCode,
            $route->lineId,
            $externalChatId,
            $externalUserId,
        ]);
    }

    private function sendMessage(
        Message $message,
        Dialog $dialog,
        Contact $rootContact,
        Bitrix24OpenLinesRouteData $route,
        Bitrix24Connection $connection,
        int $serviceUserId,
        Bitrix24OpenLinesManualReplyChatData $resolvedChat,
        bool $allowRecovery = true,
        bool $usedChatUserAddRecovery = false,
        bool $allowDialogBindingRecovery = true,
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
                array_merge($this->crmEntityParamsForResolvedChat($rootContact, $resolvedChat), [
                    'USER_ID' => $serviceUserId,
                    'CHAT_ID' => $resolvedChat->chatId,
                    'MESSAGE' => $messageText,
                ]),
                connection: $connection,
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
                resolvedCrmEntityType: $resolvedChat->crmEntityType,
                resolvedCrmEntityId: $resolvedChat->crmEntityId,
            );
        }

        if (
            $allowDialogBindingRecovery
            && $this->isChatNotInCrmResponse($response)
            && ($dialogResolvedChat = $this->resolveDialogChat($dialog, $route, $connection, $resolvedChat->chatId)) !== null
            && (
                $dialogResolvedChat->chatId !== $resolvedChat->chatId
                || $dialogResolvedChat->crmEntityType !== $resolvedChat->crmEntityType
                || $dialogResolvedChat->crmEntityId !== $resolvedChat->crmEntityId
            )
        ) {
            return $this->sendMessage(
                message: $message,
                dialog: $dialog,
                rootContact: $rootContact,
                route: $route,
                connection: $connection,
                serviceUserId: $serviceUserId,
                resolvedChat: $dialogResolvedChat,
                allowRecovery: $allowRecovery,
                usedChatUserAddRecovery: $usedChatUserAddRecovery,
                allowDialogBindingRecovery: false,
            );
        }

        if ($allowRecovery && $this->isAccessDeniedResponse($response)) {
            $this->recoverChatAccess($rootContact, $serviceUserId, $resolvedChat, $connection);

            return $this->sendMessage(
                message: $message,
                dialog: $dialog,
                rootContact: $rootContact,
                route: $route,
                connection: $connection,
                serviceUserId: $serviceUserId,
                resolvedChat: $resolvedChat,
                allowRecovery: false,
                usedChatUserAddRecovery: true,
                allowDialogBindingRecovery: $allowDialogBindingRecovery,
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

    /**
     * @return array{CRM_ENTITY_TYPE: string, CRM_ENTITY: string}
     */
    private function crmEntityParamsForResolvedChat(
        Contact $rootContact,
        Bitrix24OpenLinesManualReplyChatData $resolvedChat,
    ): array {
        if (filled($resolvedChat->crmEntityType) && filled($resolvedChat->crmEntityId)) {
            return [
                'CRM_ENTITY_TYPE' => $resolvedChat->crmEntityType,
                'CRM_ENTITY' => $resolvedChat->crmEntityId,
            ];
        }

        return $this->crmEntityParams($rootContact);
    }

    private function recoverChatAccess(
        Contact $rootContact,
        int $serviceUserId,
        Bitrix24OpenLinesManualReplyChatData $resolvedChat,
        Bitrix24Connection $connection,
    ): void {
        try {
            $response = $this->bitrix24ApiClient->call(
                'imopenlines.crm.chat.user.add',
                array_merge($this->crmEntityParamsForResolvedChat($rootContact, $resolvedChat), [
                    'CHAT_ID' => $resolvedChat->chatId,
                    'USER_ID' => $serviceUserId,
                ]),
                connection: $connection,
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

    private function resolveDialogChat(
        Dialog $dialog,
        Bitrix24OpenLinesRouteData $route,
        Bitrix24Connection $connection,
        ?string $chatId = null,
    ): ?Bitrix24OpenLinesManualReplyChatData {
        $userCode = $this->buildUserCode($dialog, $route);

        if ($userCode === null) {
            return null;
        }

        $shouldFallbackToImDialogGet = false;

        try {
            $response = $this->bitrix24ApiClient->call(
                'imopenlines.dialog.get',
                ['USER_CODE' => $userCode],
                connection: $connection,
                transportRetry: false,
            );
        } catch (Bitrix24ApiException) {
            $response = null;
            $shouldFallbackToImDialogGet = true;
        }

        if ($response instanceof Bitrix24RestResponseData) {
            if ($response->successful && is_array($response->result)) {
                $chatId = $response->result['id'] ?? null;

                if (! is_scalar($chatId) || trim((string) $chatId) === '') {
                    return null;
                }

                $crmBinding = $this->parseDialogCrmBinding($response->result['entity_data_2'] ?? null);

                if ($crmBinding === null) {
                    return null;
                }

                return $this->makeResolvedChat(
                    dialog: $dialog,
                    chatId: trim((string) $chatId),
                    usedFallback: false,
                    crmEntityType: $crmBinding['CRM_ENTITY_TYPE'],
                    crmEntityId: $crmBinding['CRM_ENTITY'],
                );
            }

            $shouldFallbackToImDialogGet = $this->isDialogLookupUnavailableResponse($response);
        }

        if (
            ! $shouldFallbackToImDialogGet
            || ! is_string($chatId)
            || trim($chatId) === ''
        ) {
            return null;
        }

        return $this->resolveChatViaImDialogGet($dialog, trim($chatId), $connection);
    }

    private function resolveChatViaImDialogGet(
        Dialog $dialog,
        string $chatId,
        Bitrix24Connection $connection,
    ): ?Bitrix24OpenLinesManualReplyChatData {
        try {
            $response = $this->bitrix24ApiClient->call(
                'im.dialog.get',
                ['DIALOG_ID' => sprintf('chat%s', $chatId)],
                connection: $connection,
                transportRetry: false,
            );
        } catch (Bitrix24ApiException) {
            return null;
        }

        if (! $response->successful || ! is_array($response->result)) {
            return null;
        }

        $resolvedChatId = $response->result['id'] ?? null;

        if (! is_scalar($resolvedChatId) || trim((string) $resolvedChatId) === '') {
            return null;
        }

        $crmBinding = $this->parseDialogCrmBinding($response->result['entity_data_2'] ?? null);

        if ($crmBinding === null) {
            return null;
        }

        return $this->makeResolvedChat(
            dialog: $dialog,
            chatId: trim((string) $resolvedChatId),
            usedFallback: false,
            crmEntityType: $crmBinding['CRM_ENTITY_TYPE'],
            crmEntityId: $crmBinding['CRM_ENTITY'],
        );
    }

    /**
     * @return array{CRM_ENTITY_TYPE: string, CRM_ENTITY: string}|null
     */
    private function parseDialogCrmBinding(mixed $crmBinding): ?array
    {
        if (! is_scalar($crmBinding)) {
            return null;
        }

        $parts = array_map('trim', explode('|', (string) $crmBinding));
        $contactIds = [];

        for ($index = 0; $index + 1 < count($parts); $index += 2) {
            $entityType = strtoupper($parts[$index]);
            $entityId = trim($parts[$index + 1]);

            if ($entityType !== 'CONTACT' || $entityId === '' || $entityId === '0') {
                continue;
            }

            $contactIds[$entityId] = true;
        }

        if ($contactIds === []) {
            return null;
        }

        if (count($contactIds) > 1) {
            return null;
        }

        return [
            'CRM_ENTITY_TYPE' => 'CONTACT',
            'CRM_ENTITY' => array_key_first($contactIds),
        ];
    }

    private function makeResolvedChat(
        Dialog $dialog,
        string $chatId,
        bool $usedFallback,
        bool $trustedReusableSource = false,
        ?string $crmEntityType = null,
        ?string $crmEntityId = null,
    ): Bitrix24OpenLinesManualReplyChatData {
        $persistedCrmBinding = $this->resolvePersistedCrmBinding($dialog, $chatId);

        return new Bitrix24OpenLinesManualReplyChatData(
            chatId: $chatId,
            usedFallback: $usedFallback,
            trustedReusableSource: $trustedReusableSource,
            crmEntityType: $crmEntityType ?? $persistedCrmBinding['CRM_ENTITY_TYPE'] ?? null,
            crmEntityId: $crmEntityId ?? $persistedCrmBinding['CRM_ENTITY'] ?? null,
        );
    }

    /**
     * @return array{CRM_ENTITY_TYPE: string, CRM_ENTITY: string}|null
     */
    private function resolvePersistedCrmBinding(Dialog $dialog, string $chatId): ?array
    {
        $persistedCrmBinding = Bitrix24MessageExport::query()
            ->join('messages', 'messages.id', '=', 'bitrix24_message_exports.message_id')
            ->where('messages.dialog_id', $dialog->id)
            ->where('bitrix24_message_exports.export_mode', Bitrix24MessageExport::MODE_LIVE)
            ->where('bitrix24_message_exports.export_status', Bitrix24MessageExport::STATUS_EXPORTED)
            ->where('bitrix24_message_exports.resolved_bitrix_chat_id', $chatId)
            ->whereNotNull('bitrix24_message_exports.resolved_crm_entity_type')
            ->whereNotNull('bitrix24_message_exports.resolved_crm_entity_id')
            ->orderByDesc('bitrix24_message_exports.exported_at')
            ->orderByDesc('bitrix24_message_exports.id')
            ->first([
                'bitrix24_message_exports.resolved_crm_entity_type',
                'bitrix24_message_exports.resolved_crm_entity_id',
            ]);

        $crmEntityType = $persistedCrmBinding?->getAttribute('resolved_crm_entity_type');
        $crmEntityId = $persistedCrmBinding?->getAttribute('resolved_crm_entity_id');

        if (
            ! is_scalar($crmEntityType)
            || trim((string) $crmEntityType) === ''
            || ! is_scalar($crmEntityId)
            || trim((string) $crmEntityId) === ''
            || trim((string) $crmEntityId) === '0'
        ) {
            return null;
        }

        return [
            'CRM_ENTITY_TYPE' => trim((string) $crmEntityType),
            'CRM_ENTITY' => trim((string) $crmEntityId),
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
        if (in_array($response->errorCode, ['CANCELED', 'ACCESS_DENIED', 'ACCESS_ERROR'], true)) {
            return true;
        }

        $errorMessage = mb_strtolower(trim((string) ($response->errorMessage ?? '')));

        return $errorMessage !== ''
            && (
                str_contains($errorMessage, 'access')
                || str_contains($errorMessage, 'permission')
                || str_contains($errorMessage, 'denied')
                || str_contains($errorMessage, 'not in chat')
            );
    }

    private function isChatNotInCrmResponse(Bitrix24RestResponseData $response): bool
    {
        return $response->errorCode === 'CHAT_NOT_IN_CRM';
    }

    private function isDialogLookupUnavailableResponse(Bitrix24RestResponseData $response): bool
    {
        return in_array($response->errorCode, ['ACCESS_ERROR', 'ACCESS_DENIED'], true)
            || $this->isAccessDeniedResponse($response)
            || $this->isUncertainResponse($response);
    }

    private function isUncertainResponse(Bitrix24RestResponseData $response): bool
    {
        return $response->httpStatus === null || $response->httpStatus >= 500;
    }
}
