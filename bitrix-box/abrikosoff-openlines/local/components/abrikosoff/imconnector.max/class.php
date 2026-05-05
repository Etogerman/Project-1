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

        Runtime::markConnectorReady('abrikosoff_max', $lineId);

        $this->arResult = [
            'LINE_ID' => $lineId,
            'LINE_NAME' => Runtime::lineName('abrikosoff_max', $lineId),
            'CALLBACK_URL' => Runtime::laravelOpenlinesCallbackUrlForLine('abrikosoff_max', $lineId),
            'CONNECTOR_CODE' => 'abrikosoff_max',
        ];

        $this->includeComponentTemplate();
    }
}
