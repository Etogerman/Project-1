<?php

if (! defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}
?>
<div style="padding: 16px; font-size: 14px; line-height: 1.5;">
    <p><strong>Abrikosoff Telegram connector готов к работе.</strong></p>
    <p>Код коннектора: <code><?= htmlspecialcharsbx($arResult['CONNECTOR_CODE']) ?></code></p>
    <p>ID линии: <code><?= htmlspecialcharsbx($arResult['LINE_ID']) ?></code></p>
    <p>Рекомендуемое имя линии: <strong><?= htmlspecialcharsbx($arResult['LINE_NAME']) ?></strong></p>
    <p>Laravel callback URL: <code><?= htmlspecialcharsbx($arResult['CALLBACK_URL']) ?></code></p>
</div>
