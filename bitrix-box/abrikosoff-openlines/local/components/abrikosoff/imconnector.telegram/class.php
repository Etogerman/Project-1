<?php

if (! defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

require_once $_SERVER['DOCUMENT_ROOT'].'/local/php_interface/include/abrikosoff_openlines/bootstrap.php';

use Abrikosoff\BitrixBox\OpenLines\Runtime;

class AbrikosoffImconnectorTelegramComponent extends CBitrixComponent
{
    public function executeComponent(): void
    {
        $lineId = trim((string) ($this->arParams['LINE'] ?? ''));

        Runtime::markConnectorReady('abrikosoff_telegram', $lineId);

        $this->arResult = [
            'LINE_ID' => $lineId,
            'LINE_NAME' => Runtime::lineName('abrikosoff_telegram', $lineId),
            'CALLBACK_URL' => Runtime::laravelOpenlinesCallbackUrl(),
            'CONNECTOR_CODE' => 'abrikosoff_telegram',
        ];

        $this->includeComponentTemplate();
    }
}
