<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24CurrentOpenLineChatData;
use App\Data\Bitrix24\Bitrix24OpenLinesRouteData;
use App\Data\Bitrix24\Bitrix24RestResponseData;
use App\Models\Bitrix24Connection;
use App\Models\Contact;
use App\Models\Dialog;
use App\Services\Contacts\ResolveRootContactAction;

class ResolveCurrentBitrix24OpenLineChatAction
{
    public function __construct(
        private readonly Bitrix24ApiClient $bitrix24ApiClient,
        private readonly ResolveBitrix24LiveChatKeyAction $resolveLiveChatKeyAction,
        private readonly ResolveBitrix24OpenLinesDialogBindingAction $resolveDialogBindingAction,
        private readonly ResolveRootContactAction $resolveRootContactAction,
    ) {}

    public function handle(
        Dialog $dialog,
        Bitrix24OpenLinesRouteData $route,
        Bitrix24Connection $connection,
    ): ?Bitrix24CurrentOpenLineChatData {
        $dialog->loadMissing('contact');

        $contact = $dialog->contact;

        if (! $contact instanceof Contact) {
            return null;
        }

        $rootContact = $this->resolveRootContactAction->handle($contact);
        $bitrix24ContactId = $this->positiveIntegerString($rootContact->bitrix24_contact_id);

        if ($bitrix24ContactId === null) {
            return null;
        }

        $expectedConnectorChatId = $this->resolveLiveChatKeyAction->handle($dialog);
        $historyUserCodes = $this->lookupContactOpenLineHistoryUserCodes($bitrix24ContactId, $connection);
        $candidates = [];

        foreach ($historyUserCodes as $userCode) {
            $binding = $this->resolveDialogBindingAction->parseUserCode($userCode);

            if (
                $binding === null
                || $binding->connectorCode !== $route->connectorCode
                || $binding->lineId !== $route->lineId
                || $binding->connectorChatId !== $expectedConnectorChatId
            ) {
                continue;
            }

            $resolvedChat = $this->resolveChatByUserCode($binding->userCode, $bitrix24ContactId, $connection);

            if (! $resolvedChat instanceof Bitrix24CurrentOpenLineChatData) {
                continue;
            }

            $numericChatId = $this->positiveIntegerString($resolvedChat->chatId);

            if ($numericChatId === null) {
                continue;
            }

            $candidates[] = [
                'chat_id' => $numericChatId,
                'user_code' => $resolvedChat->userCode,
            ];
        }

        if ($candidates === []) {
            return null;
        }

        usort(
            $candidates,
            static fn (array $left, array $right): int => (int) $right['chat_id'] <=> (int) $left['chat_id'],
        );

        $current = $candidates[0];

        return new Bitrix24CurrentOpenLineChatData(
            userCode: $current['user_code'],
            chatId: $current['chat_id'],
        );
    }

    /**
     * @return list<string>
     */
    private function lookupContactOpenLineHistoryUserCodes(
        string $bitrix24ContactId,
        Bitrix24Connection $connection,
    ): array {
        $response = $this->bitrix24ApiClient->call(
            'crm.contact.get',
            ['ID' => $bitrix24ContactId],
            connection: $connection,
            transportRetry: false,
        );

        if (! $response->successful || ! is_array($response->result)) {
            throw new Bitrix24ApiException(sprintf(
                'Bitrix24 Open Lines current chat contact lookup failed: %s',
                $response->errorMessage ?? 'Unknown error.',
            ));
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

    private function resolveChatByUserCode(
        string $userCode,
        string $bitrix24ContactId,
        Bitrix24Connection $connection,
    ): ?Bitrix24CurrentOpenLineChatData {
        $response = $this->bitrix24ApiClient->call(
            'imopenlines.dialog.get',
            ['USER_CODE' => $userCode],
            connection: $connection,
            transportRetry: false,
        );

        if (! $response->successful || ! is_array($response->result)) {
            if ($this->isServerFailure($response)) {
                throw new Bitrix24ApiException(sprintf(
                    'Bitrix24 Open Lines current chat dialog lookup failed: %s',
                    $response->errorMessage ?? 'Unknown error.',
                ));
            }

            return null;
        }

        $chatId = $this->extractChatId($response->result);

        if ($chatId === null || ! $this->chatBelongsToContact($response->result, $bitrix24ContactId)) {
            return null;
        }

        return new Bitrix24CurrentOpenLineChatData(
            userCode: $userCode,
            chatId: $chatId,
        );
    }

    private function isServerFailure(Bitrix24RestResponseData $response): bool
    {
        return $response->httpStatus !== null && $response->httpStatus >= 500;
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
}
