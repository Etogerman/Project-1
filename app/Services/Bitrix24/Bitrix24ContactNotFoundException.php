<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24RestResponseData;

final class Bitrix24ContactNotFoundException extends Bitrix24ApiException
{
    public function __construct(
        public readonly string $bitrix24ContactId,
        public readonly ?string $restMethod = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
    ) {
        parent::__construct(sprintf(
            'Bitrix24 contact `%s` was not found.',
            $bitrix24ContactId,
        ));
    }

    public static function fromResponse(string $bitrix24ContactId, Bitrix24RestResponseData $response): ?self
    {
        if (! self::isNotFoundResponse($response)) {
            return null;
        }

        return new self(
            bitrix24ContactId: $bitrix24ContactId,
            restMethod: $response->restMethod,
            errorCode: $response->errorCode,
            errorMessage: $response->errorMessage,
        );
    }

    public static function isNotFoundResponse(Bitrix24RestResponseData $response): bool
    {
        if ($response->successful) {
            return false;
        }

        if ($response->httpStatus !== null && $response->httpStatus >= 500) {
            return false;
        }

        $errorCode = self::normalize($response->errorCode);
        $errorMessage = self::normalize($response->errorMessage);

        if (in_array($errorCode, ['error_not_found', 'not_found', 'crm_error_not_found'], true)) {
            return true;
        }

        return $errorMessage === 'not found'
            || $errorMessage === 'not_found'
            || str_contains($errorMessage, 'contact not found')
            || str_contains($errorMessage, 'контакт не найден');
    }

    private static function normalize(?string $value): string
    {
        return mb_strtolower(trim((string) $value));
    }
}
