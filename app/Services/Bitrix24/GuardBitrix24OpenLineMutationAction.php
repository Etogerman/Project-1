<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24CurrentOpenLineChatData;
use App\Data\Bitrix24\Bitrix24OpenLinesRouteData;
use App\Models\Bitrix24Connection;
use App\Models\Bitrix24MessageExport;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Dialog;

class GuardBitrix24OpenLineMutationAction
{
    public function __construct(
        private readonly Bitrix24ApiClient $bitrix24ApiClient,
        private readonly ResolveBitrix24LiveChatKeyAction $resolveLiveChatKeyAction,
        private readonly ResolveBitrix24OpenLinesDialogBindingAction $resolveDialogBindingAction,
        private readonly ResolveCurrentBitrix24OpenLineChatAction $resolveCurrentOpenLineChatAction,
    ) {}

    /**
     * @param  list<array<string, mixed>>|null  $activeChatRows
     *
     * @throws Bitrix24OpenLineMutationGuardException
     */
    public function handle(
        Dialog $dialog,
        Contact $rootContact,
        Bitrix24OpenLinesRouteData $route,
        Bitrix24Connection $connection,
        ?array $activeChatRows = null,
    ): void {
        if ($route->platform !== Channel::PLATFORM_TELEGRAM) {
            return;
        }

        if ($this->resolveDialogBindingAction->handle($dialog, $route) !== null) {
            return;
        }

        if (! $this->hasLegacyOpenLineExportHistory($dialog)) {
            return;
        }

        $activeChatRows ??= $this->lookupActiveChatRows($rootContact, $connection);

        if ($this->hasCompatibleActiveChat($activeChatRows, $route)) {
            return;
        }

        $historyUserCodes = $this->lookupContactOpenLineHistoryUserCodes($rootContact, $connection);
        $expectedConnectorChatId = $this->resolveLiveChatKeyAction->handle($dialog);
        $matchedHistory = array_values(array_filter(
            $historyUserCodes,
            function (string $userCode) use ($route, $expectedConnectorChatId): bool {
                $binding = $this->resolveDialogBindingAction->parseUserCode($userCode);

                return $binding !== null
                    && $binding->connectorCode === $route->connectorCode
                    && $binding->lineId === $route->lineId
                    && $binding->connectorChatId === $expectedConnectorChatId;
            },
        ));

        if ($matchedHistory === []) {
            return;
        }

        if ($this->canCreateControlledOpenLineForContact($rootContact)) {
            return;
        }

        throw new Bitrix24OpenLineMutationGuardException(
            sprintf(
                'Bitrix24 Open Lines Telegram export is blocked: CONTACT [%s] already has Open Line history for connector [%s], line [%s], dialog [%s], but no active same-connector CRM chat. Verified binding is required before mutating export.',
                (string) $rootContact->bitrix24_contact_id,
                $route->connectorCode,
                $route->lineId,
                $expectedConnectorChatId,
            ),
            Bitrix24MessageExport::FAILURE_OPEN_LINE_HISTORY_REQUIRES_BINDING,
        );
    }

    private function canCreateControlledOpenLineForContact(Contact $rootContact): bool
    {
        $bitrix24ContactId = $rootContact->bitrix24_contact_id;

        if (! is_scalar($bitrix24ContactId)) {
            return false;
        }

        $normalized = trim((string) $bitrix24ContactId);

        return $normalized !== ''
            && ctype_digit($normalized)
            && (int) $normalized > 0;
    }

    /**
     * @throws Bitrix24OpenLineMutationGuardException
     */
    public function assertVerifiedBindingChatIsActiveForContact(
        Dialog $dialog,
        Contact $rootContact,
        Bitrix24OpenLinesRouteData $route,
        Bitrix24Connection $connection,
        string $expectedResolvedBitrixChatId,
        ?string $expectedUserCode = null,
    ): void {
        $activeChatRows = $this->lookupActiveChatRows($rootContact, $connection);
        $matchedConnectorId = null;
        $sameConnectorActiveChatId = null;
        $newerSameConnectorActiveChatId = null;
        $matchedExpectedConnectorChat = false;
        $verifiedBindingMatchesCurrentOrContact = null;
        $verifiedBindingMatches = function () use (
            $dialog,
            $rootContact,
            $route,
            $connection,
            $expectedResolvedBitrixChatId,
            $expectedUserCode,
            &$verifiedBindingMatchesCurrentOrContact,
        ): bool {
            if ($expectedUserCode === null) {
                return false;
            }

            return $verifiedBindingMatchesCurrentOrContact ??= $this->verifiedBindingMatchesCurrentOrContact(
                $dialog,
                $rootContact,
                $route,
                $connection,
                $expectedResolvedBitrixChatId,
                $expectedUserCode,
            );
        };
        foreach ($activeChatRows as $chat) {
            $chatId = $this->extractChatId($chat);
            $connectorId = $this->extractConnectorId($chat);

            if ($connectorId === $route->connectorCode && $chatId !== $expectedResolvedBitrixChatId) {
                $sameConnectorActiveChatId = $this->preferNewerChatId($sameConnectorActiveChatId, $chatId);

                if ($this->isNewerChatId($chatId, $expectedResolvedBitrixChatId)) {
                    $newerSameConnectorActiveChatId = $this->preferNewerChatId($newerSameConnectorActiveChatId, $chatId);
                }

                continue;
            }

            if ($chatId !== $expectedResolvedBitrixChatId) {
                continue;
            }

            $matchedConnectorId = $connectorId;

            if ($matchedConnectorId === $route->connectorCode) {
                $matchedExpectedConnectorChat = true;

                continue;
            }

            if ($matchedConnectorId !== null) {
                break;
            }
        }

        if ($newerSameConnectorActiveChatId !== null) {
            if ($verifiedBindingMatches()) {
                return;
            }

            throw new Bitrix24OpenLineMutationGuardException(
                sprintf(
                    'Bitrix24 Open Lines verified binding preflight failed: expected chat id [%s] is not current for connector [%s]; newer active chat id [%s] was found.',
                    $expectedResolvedBitrixChatId,
                    $route->connectorCode,
                    $newerSameConnectorActiveChatId,
                ),
                Bitrix24MessageExport::FAILURE_MESSAGE_SEND_FAILED,
                relatedChatId: $newerSameConnectorActiveChatId,
            );
        }

        if ($matchedExpectedConnectorChat && ($expectedUserCode === null || $verifiedBindingMatches())) {
            return;
        }

        if ($sameConnectorActiveChatId !== null) {
            if ($verifiedBindingMatches()) {
                return;
            }

            throw new Bitrix24OpenLineMutationGuardException(
                sprintf(
                    'Bitrix24 Open Lines verified binding preflight failed: expected chat id [%s] is not current for connector [%s]; active chat id [%s] was found.',
                    $expectedResolvedBitrixChatId,
                    $route->connectorCode,
                    $sameConnectorActiveChatId,
                ),
                Bitrix24MessageExport::FAILURE_MESSAGE_SEND_FAILED,
                relatedChatId: $sameConnectorActiveChatId,
            );
        }

        if ($verifiedBindingMatches()) {
            return;
        }

        if ($matchedConnectorId !== null) {
            throw new Bitrix24OpenLineMutationGuardException(
                sprintf(
                    'Bitrix24 Open Lines verified binding preflight failed: expected chat id [%s] is active, but connector [%s] does not match expected connector [%s].',
                    $expectedResolvedBitrixChatId,
                    $matchedConnectorId,
                    $route->connectorCode,
                ),
                Bitrix24MessageExport::FAILURE_MESSAGE_SEND_FAILED,
            );
        }

        throw new Bitrix24OpenLineMutationGuardException(
            sprintf(
                'Bitrix24 Open Lines verified binding preflight failed: expected chat id [%s] is not active for CONTACT [%s] and connector [%s].',
                $expectedResolvedBitrixChatId,
                (string) $rootContact->bitrix24_contact_id,
                $route->connectorCode,
            ),
            Bitrix24MessageExport::FAILURE_MESSAGE_SEND_FAILED,
        );
    }

    private function verifiedBindingMatchesCurrentOrContact(
        Dialog $dialog,
        Contact $rootContact,
        Bitrix24OpenLinesRouteData $route,
        Bitrix24Connection $connection,
        string $expectedResolvedBitrixChatId,
        string $expectedUserCode,
    ): bool {
        if ($this->verifiedBindingDialogMatchesContact(
            $rootContact,
            $route,
            $connection,
            $expectedResolvedBitrixChatId,
            $expectedUserCode,
        )) {
            return true;
        }

        $currentChat = $this->resolveCurrentOpenLineChat(
            $dialog,
            $route,
            $connection,
        );

        if ($currentChat instanceof Bitrix24CurrentOpenLineChatData) {
            if ($currentChat->chatId === $expectedResolvedBitrixChatId) {
                return true;
            }

            throw new Bitrix24OpenLineMutationGuardException(
                sprintf(
                    'Bitrix24 Open Lines verified binding preflight failed: expected chat id [%s] is not current for connector [%s]; current chat id [%s] was found.',
                    $expectedResolvedBitrixChatId,
                    $route->connectorCode,
                    $currentChat->chatId,
                ),
                Bitrix24MessageExport::FAILURE_MESSAGE_SEND_FAILED,
                relatedChatId: $currentChat->chatId,
            );
        }

        return false;
    }

    private function resolveCurrentOpenLineChat(
        Dialog $dialog,
        Bitrix24OpenLinesRouteData $route,
        Bitrix24Connection $connection,
    ): ?Bitrix24CurrentOpenLineChatData {
        try {
            return $this->resolveCurrentOpenLineChatAction->handle($dialog, $route, $connection);
        } catch (Bitrix24ApiException $exception) {
            throw new Bitrix24OpenLineMutationGuardException(
                'Bitrix24 Open Lines verified binding current chat lookup failed before mutating export.',
                Bitrix24MessageExport::FAILURE_OPEN_LINE_GUARD_LOOKUP_FAILED,
                false,
                $exception,
            );
        }
    }

    private function verifiedBindingDialogMatchesContact(
        Contact $rootContact,
        Bitrix24OpenLinesRouteData $route,
        Bitrix24Connection $connection,
        string $expectedResolvedBitrixChatId,
        string $expectedUserCode,
    ): bool {
        $binding = $this->resolveDialogBindingAction->parseUserCode($expectedUserCode);

        if (
            $binding === null
            || $binding->connectorCode !== $route->connectorCode
            || $binding->lineId !== $route->lineId
        ) {
            return false;
        }

        try {
            $response = $this->bitrix24ApiClient->call(
                'imopenlines.dialog.get',
                ['USER_CODE' => $binding->userCode],
                connection: $connection,
                transportRetry: false,
            );
        } catch (Bitrix24ApiException $exception) {
            throw new Bitrix24OpenLineMutationGuardException(
                'Bitrix24 Open Lines verified binding dialog lookup failed before mutating export.',
                Bitrix24MessageExport::FAILURE_OPEN_LINE_GUARD_LOOKUP_FAILED,
                false,
                $exception,
            );
        }

        if (! $response->successful || ! is_array($response->result)) {
            if ($response->httpStatus !== null && $response->httpStatus >= 500) {
                throw new Bitrix24OpenLineMutationGuardException(
                    sprintf(
                        'Bitrix24 Open Lines verified binding dialog lookup failed: %s',
                        $response->errorMessage ?? 'Unknown error.',
                    ),
                    Bitrix24MessageExport::FAILURE_OPEN_LINE_GUARD_LOOKUP_FAILED,
                    false,
                );
            }

            return false;
        }

        return $this->extractChatId($response->result) === $expectedResolvedBitrixChatId
            && $this->chatBelongsToContact($response->result, (string) $rootContact->bitrix24_contact_id);
    }

    private function hasLegacyOpenLineExportHistory(Dialog $dialog): bool
    {
        return Bitrix24MessageExport::query()
            ->join('messages', 'messages.id', '=', 'bitrix24_message_exports.message_id')
            ->where('messages.dialog_id', $dialog->id)
            ->where('bitrix24_message_exports.export_mode', Bitrix24MessageExport::MODE_LIVE)
            ->where('bitrix24_message_exports.export_status', Bitrix24MessageExport::STATUS_EXPORTED)
            ->where(function ($query): void {
                $query->where('bitrix24_message_exports.transport_method', Bitrix24MessageExport::TRANSPORT_IMCONNECTOR_SEND_MESSAGES)
                    ->orWhereNull('bitrix24_message_exports.transport_method');
            })
            ->exists();
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws Bitrix24OpenLineMutationGuardException
     */
    private function lookupActiveChatRows(Contact $rootContact, Bitrix24Connection $connection): array
    {
        try {
            $response = $this->bitrix24ApiClient->call(
                'imopenlines.crm.chat.get',
                [
                    'CRM_ENTITY_TYPE' => 'CONTACT',
                    'CRM_ENTITY' => (string) $rootContact->bitrix24_contact_id,
                    'ACTIVE_ONLY' => 'Y',
                ],
                connection: $connection,
                transportRetry: false,
            );
        } catch (Bitrix24ApiException $exception) {
            throw new Bitrix24OpenLineMutationGuardException(
                'Bitrix24 Open Lines mutation guard active chat lookup failed before mutating export.',
                Bitrix24MessageExport::FAILURE_OPEN_LINE_GUARD_LOOKUP_FAILED,
                false,
                $exception,
            );
        }

        if (! $response->successful) {
            throw new Bitrix24OpenLineMutationGuardException(
                sprintf(
                    'Bitrix24 Open Lines mutation guard active chat lookup failed: %s',
                    $response->errorMessage ?? 'Unknown error.',
                ),
                Bitrix24MessageExport::FAILURE_OPEN_LINE_GUARD_LOOKUP_FAILED,
                false,
            );
        }

        return $this->extractChatRows($response->result);
    }

    /**
     * @param  list<array<string, mixed>>  $activeChatRows
     */
    private function hasCompatibleActiveChat(array $activeChatRows, Bitrix24OpenLinesRouteData $route): bool
    {
        foreach ($activeChatRows as $chat) {
            $connectorId = $this->extractConnectorId($chat);

            if ($connectorId === $route->connectorCode) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     *
     * @throws Bitrix24OpenLineMutationGuardException
     */
    private function lookupContactOpenLineHistoryUserCodes(Contact $rootContact, Bitrix24Connection $connection): array
    {
        try {
            $response = $this->bitrix24ApiClient->call(
                'crm.contact.get',
                ['ID' => (string) $rootContact->bitrix24_contact_id],
                connection: $connection,
                transportRetry: false,
            );
        } catch (Bitrix24ApiException $exception) {
            throw new Bitrix24OpenLineMutationGuardException(
                'Bitrix24 Open Lines mutation guard contact lookup failed before mutating export.',
                Bitrix24MessageExport::FAILURE_OPEN_LINE_GUARD_LOOKUP_FAILED,
                false,
                $exception,
            );
        }

        if (! $response->successful || ! is_array($response->result)) {
            if ($notFound = Bitrix24ContactNotFoundException::fromResponse((string) $rootContact->bitrix24_contact_id, $response)) {
                throw new Bitrix24OpenLineMutationGuardException(
                    'Bitrix24 Open Lines mutation guard contact lookup found a stale CRM contact binding.',
                    Bitrix24MessageExport::FAILURE_OPEN_LINE_GUARD_LOOKUP_FAILED,
                    false,
                    $notFound,
                );
            }

            throw new Bitrix24OpenLineMutationGuardException(
                sprintf(
                    'Bitrix24 Open Lines mutation guard contact lookup failed: %s',
                    $response->errorMessage ?? 'Unknown error.',
                ),
                Bitrix24MessageExport::FAILURE_OPEN_LINE_GUARD_LOOKUP_FAILED,
                false,
            );
        }

        $imRows = $response->result['IM'] ?? [];

        if (! is_array($imRows)) {
            return [];
        }

        $userCodes = [];

        foreach ($imRows as $row) {
            $value = is_array($row) ? ($row['VALUE'] ?? null) : $row;
            $valueType = is_array($row) ? ($row['VALUE_TYPE'] ?? null) : null;

            if (! is_scalar($value)) {
                continue;
            }

            $normalized = trim((string) $value);

            if ($normalized === '') {
                continue;
            }

            $isOpenLine = is_scalar($valueType) && strtoupper(trim((string) $valueType)) === 'IMOL';

            if (! $isOpenLine && ! str_starts_with($normalized, 'imol|')) {
                continue;
            }

            $userCodes[$normalized] = true;
        }

        return array_keys($userCodes);
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

    /**
     * @param  array<string, mixed>  $chat
     */
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

    /**
     * @param  array<string, mixed>  $chat
     */
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

    private function preferNewerChatId(?string $current, string $candidate): string
    {
        if ($current === null) {
            return $candidate;
        }

        $currentNumeric = $this->positiveIntegerString($current);
        $candidateNumeric = $this->positiveIntegerString($candidate);

        if ($candidateNumeric === null) {
            return $current;
        }

        if ($currentNumeric === null) {
            return $candidate;
        }

        return (int) $candidateNumeric > (int) $currentNumeric
            ? $candidate
            : $current;
    }

    private function isNewerChatId(string $candidate, string $expected): bool
    {
        $candidateNumeric = $this->positiveIntegerString($candidate);
        $expectedNumeric = $this->positiveIntegerString($expected);

        return $candidateNumeric !== null
            && $expectedNumeric !== null
            && (int) $candidateNumeric > (int) $expectedNumeric;
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

    /**
     * @param  array<string, mixed>  $chat
     */
    private function chatBelongsToContact(array $chat, string $bitrix24ContactId): bool
    {
        $entityData = $chat['ENTITY_DATA_2']
            ?? $chat['entity_data_2']
            ?? data_get($chat, 'chat.ENTITY_DATA_2')
            ?? data_get($chat, 'chat.entity_data_2');

        if (! is_scalar($entityData)) {
            return false;
        }

        $parts = array_values(array_map('trim', explode('|', (string) $entityData)));

        foreach ($parts as $index => $part) {
            if (strtoupper($part) !== 'CONTACT') {
                continue;
            }

            return ($parts[$index + 1] ?? null) === $bitrix24ContactId;
        }

        return false;
    }
}
