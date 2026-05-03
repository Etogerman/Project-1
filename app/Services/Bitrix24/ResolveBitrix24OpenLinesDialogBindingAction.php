<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24OpenLinesDialogBindingData;
use App\Data\Bitrix24\Bitrix24OpenLinesRouteData;
use App\Models\Dialog;

class ResolveBitrix24OpenLinesDialogBindingAction
{
    public function handle(Dialog $dialog, Bitrix24OpenLinesRouteData $route): ?Bitrix24OpenLinesDialogBindingData
    {
        if ($dialog->bitrix24_open_line_binding_verified_at === null) {
            return null;
        }

        $parsed = $this->parseUserCode($dialog->bitrix24_open_line_user_code_override);

        if ($parsed === null) {
            return null;
        }

        if ($parsed->connectorCode !== $route->connectorCode || $parsed->lineId !== $route->lineId) {
            return null;
        }

        $resolvedChatId = $this->nullableString($dialog->bitrix24_open_line_resolved_chat_id_override);

        return new Bitrix24OpenLinesDialogBindingData(
            userCode: $parsed->userCode,
            connectorCode: $parsed->connectorCode,
            lineId: $parsed->lineId,
            connectorChatId: $parsed->connectorChatId,
            connectorUserId: $parsed->connectorUserId,
            resolvedBitrixChatId: $resolvedChatId,
        );
    }

    public function parseUserCode(mixed $value): ?Bitrix24OpenLinesDialogBindingData
    {
        if (! is_scalar($value)) {
            return null;
        }

        $parts = array_values(array_filter(
            array_map('trim', explode('|', (string) $value)),
            static fn (string $part): bool => $part !== '',
        ));

        if (($parts[0] ?? null) === 'imol') {
            array_shift($parts);
        }

        if (count($parts) !== 4) {
            return null;
        }

        [$connectorCode, $lineId, $connectorChatId, $connectorUserId] = $parts;

        if ($connectorCode === '' || $lineId === '' || $connectorChatId === '' || $connectorUserId === '') {
            return null;
        }

        return new Bitrix24OpenLinesDialogBindingData(
            userCode: implode('|', $parts),
            connectorCode: $connectorCode,
            lineId: $lineId,
            connectorChatId: $connectorChatId,
            connectorUserId: $connectorUserId,
        );
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
