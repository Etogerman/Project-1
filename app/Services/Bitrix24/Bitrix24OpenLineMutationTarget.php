<?php

namespace App\Services\Bitrix24;

use App\Models\Bitrix24OpenLineRoute;

final readonly class Bitrix24OpenLineMutationTarget
{
    public string $portalDomain;

    public string $connectorCode;

    public string $lineId;

    public string $chatId;

    public function __construct(
        string $portalDomain,
        string $connectorCode,
        string $lineId,
        string $chatId,
    ) {
        $normalizedPortal = mb_strtolower(rtrim(trim($portalDomain), '.'));

        if ($normalizedPortal === ''
            || trim($connectorCode) !== $connectorCode
            || ! Bitrix24OpenLineRoute::isValidConnectorCode($connectorCode)
            || ! Bitrix24OpenLineRoute::isValidLineId($lineId)
            || trim($chatId) !== $chatId
            || $chatId === ''
        ) {
            throw new Bitrix24OpenLineMutationAuthorityException(
                'openlines_mutation_target_invalid',
                'Цель Open Lines mutation сформирована некорректно.',
            );
        }

        $this->portalDomain = $normalizedPortal;
        $this->connectorCode = $connectorCode;
        $this->lineId = $lineId;
        $this->chatId = $chatId;
    }
}
