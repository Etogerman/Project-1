<?php

namespace App\Services\Bitrix24;

use App\Models\Channel;
use App\Models\Contact;
use App\Services\Contacts\ResolveRootContactAction;

class ResolveBitrix24ContactSourceAction
{
    public function __construct(
        private readonly ResolveRootContactAction $resolveRootContactAction,
        private readonly ResolveCurrentBitrix24ProfileAction $resolveCurrentProfileAction,
    ) {}

    public function handle(Contact|int $contact): ?string
    {
        $rootContact = $this->resolveRootContactAction->handle($contact);
        $primaryIdentity = $rootContact->primaryIdentity()->with('channel')->first();
        $platform = $primaryIdentity?->channel?->platform;

        if (! in_array($platform, [
            Channel::PLATFORM_TELEGRAM,
            Channel::PLATFORM_MAX,
        ], true)) {
            return null;
        }

        $profile = $this->resolveCurrentProfileAction->handle();

        return $profile->sourceIdForPlatform($platform);
    }
}
