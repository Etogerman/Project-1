<?php

namespace App\Data\Bitrix24;

final readonly class Bitrix24OpenLinesRouteData
{
    public function __construct(
        public string $platform,
        public string $connectorCode,
        public string $lineId,
    ) {}
}
