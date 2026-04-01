<?php

namespace App\Data\Bitrix24;

final readonly class Bitrix24OpenLinesOperatorMessageData
{
    /**
     * @param  array<string, mixed>  $im
     * @param  array<string, mixed>  $rawPayload
     */
    public function __construct(
        public string $connectorCode,
        public string $lineId,
        public string $chatId,
        public string $bitrixMessageId,
        public string $text,
        public array $im,
        public array $rawPayload,
    ) {}
}
