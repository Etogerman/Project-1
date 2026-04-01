<?php

namespace App\Services\Bitrix24;

use App\Models\Channel;
use App\Models\Contact;
use App\Services\Contacts\ResolveRootContactAction;

class ResolveBitrix24ContactSourceAction
{
    public function __construct(
        private readonly ResolveRootContactAction $resolveRootContactAction,
    ) {}

    public function handle(Contact|int $contact): ?string
    {
        $rootContact = $this->resolveRootContactAction->handle($contact);
        $primaryIdentity = $rootContact->primaryIdentity()->with('channel')->first();
        $platform = $primaryIdentity?->channel?->platform;

        return match ($platform) {
            Channel::PLATFORM_TELEGRAM => $this->nullableString(config('bitrix24.sources.telegram_id')),
            Channel::PLATFORM_MAX => $this->nullableString(config('bitrix24.sources.max_id')),
            default => null,
        };
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
