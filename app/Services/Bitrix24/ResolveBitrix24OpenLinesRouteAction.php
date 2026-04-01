<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24OpenLinesRouteData;
use App\Models\Channel;
use App\Models\Dialog;

class ResolveBitrix24OpenLinesRouteAction
{
    public function handle(Dialog|int $dialog): Bitrix24OpenLinesRouteData
    {
        $dialog = $dialog instanceof Dialog
            ? $dialog
            : Dialog::query()->with('channel')->findOrFail($dialog);

        $dialog->loadMissing('channel');

        $channel = $dialog->channel;

        if (! $channel instanceof Channel) {
            throw new Bitrix24ApiException('Bitrix24 Open Lines export requires a dialog channel.');
        }

        [$connectorCode, $lineId] = match ($channel->platform) {
            Channel::PLATFORM_TELEGRAM => [
                (string) config('bitrix24.openlines.telegram_connector_code', ''),
                (string) config('bitrix24.openlines.telegram_line_id', ''),
            ],
            Channel::PLATFORM_MAX => [
                (string) config('bitrix24.openlines.max_connector_code', ''),
                (string) config('bitrix24.openlines.max_line_id', ''),
            ],
            default => ['', ''],
        };

        if ($connectorCode === '' || $lineId === '') {
            throw new Bitrix24ApiException(sprintf(
                'Bitrix24 Open Lines route is not configured for platform [%s].',
                $channel->platform,
            ));
        }

        return new Bitrix24OpenLinesRouteData(
            platform: $channel->platform,
            connectorCode: $connectorCode,
            lineId: $lineId,
        );
    }
}
