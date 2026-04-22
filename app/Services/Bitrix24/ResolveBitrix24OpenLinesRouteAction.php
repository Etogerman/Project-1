<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24OpenLinesRouteData;
use App\Models\Channel;
use App\Models\Dialog;

class ResolveBitrix24OpenLinesRouteAction
{
    public function __construct(
        private readonly ResolveCurrentBitrix24ProfileAction $resolveCurrentProfileAction,
    ) {}

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

        $profile = $this->resolveCurrentProfileAction->handle();
        $connectorCode = $profile->openLinesConnectorCodeForPlatform($channel->platform) ?? '';
        $lineId = $profile->openLinesLineIdForPlatform($channel->platform) ?? '';

        if ($connectorCode === '' || $lineId === '') {
            throw new Bitrix24ApiException(sprintf(
                'Bitrix24 Open Lines route is not configured for platform [%s] on current runtime profile `%s`.',
                $channel->platform,
                $profile->profile_key,
            ));
        }

        return new Bitrix24OpenLinesRouteData(
            platform: $channel->platform,
            connectorCode: $connectorCode,
            lineId: $lineId,
        );
    }
}
