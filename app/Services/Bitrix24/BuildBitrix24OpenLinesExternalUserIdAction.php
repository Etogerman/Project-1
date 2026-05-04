<?php

namespace App\Services\Bitrix24;

use App\Models\Bitrix24OpenLineRoute;
use App\Models\Channel;

class BuildBitrix24OpenLinesExternalUserIdAction
{
    public function handle(Channel $channel, ?string $externalUserId, int $rootContactId): string
    {
        $channelType = Bitrix24OpenLineRoute::channelTypeForChannel($channel);
        $externalUserId = trim((string) $externalUserId);

        if ($externalUserId !== '') {
            return sprintf('%s:channel:%d:user:%s', $channelType, $channel->id, $externalUserId);
        }

        return sprintf('%s:channel:%d:contact:%d', $channelType, $channel->id, $rootContactId);
    }
}
