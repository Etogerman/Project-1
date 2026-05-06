<?php

if (! defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    exit();
}

require_once $_SERVER['DOCUMENT_ROOT'].'/local/php_interface/include/abrikosoff_openlines/bootstrap.php';

use Abrikosoff\BitrixBox\OpenLines\Runtime;

class AbrikosoffImconnectorMaxComponent extends CBitrixComponent
{
    public function executeComponent(): void
    {
        $lineId = trim((string) ($this->arParams['LINE'] ?? ''));
        $connectorCode = Runtime::connectorCodeForComponentLine('abrikosoff:imconnector.max', $lineId);

        if ($connectorCode !== null) {
            Runtime::markConnectorReady($connectorCode, $lineId);
        }

        $this->arResult = [
            'LINE_ID' => $lineId,
            'LINE_NAME' => $connectorCode !== null ? Runtime::lineName($connectorCode, $lineId) : '',
            'CALLBACK_URL' => $connectorCode !== null ? Runtime::laravelOpenlinesCallbackUrlForLine($connectorCode, $lineId) : '',
            'CONNECTOR_CODE' => $connectorCode ?? '',
        ];

        $this->includeComponentTemplate();
    }
}
