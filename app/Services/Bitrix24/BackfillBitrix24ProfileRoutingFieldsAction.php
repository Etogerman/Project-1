<?php

namespace App\Services\Bitrix24;

use App\Models\Bitrix24Profile;

class BackfillBitrix24ProfileRoutingFieldsAction
{
    public function __construct(
        private readonly ResolveCurrentBitrix24CallbackBaseUrlAction $resolveCurrentCallbackBaseUrl,
    ) {}

    public function handle(): void
    {
        try {
            $callbackBaseUrl = $this->resolveCurrentCallbackBaseUrl->handle();
        } catch (Bitrix24ConnectionStateException) {
            return;
        }

        $profile = Bitrix24Profile::query()
            ->where('callback_base_url', $callbackBaseUrl)
            ->first();

        if (! $profile instanceof Bitrix24Profile || $profile->profile_type !== Bitrix24Profile::TYPE_FULL_LIVE) {
            return;
        }

        $updates = array_filter([
            'telegram_source_id' => $this->resolveBackfillValue($profile->telegram_source_id, config('bitrix24.sources.telegram_id')),
            'max_source_id' => $this->resolveBackfillValue($profile->max_source_id, config('bitrix24.sources.max_id')),
            'telegram_connector_code' => $this->resolveBackfillValue($profile->telegram_connector_code, config('bitrix24.openlines.telegram_connector_code')),
            'max_connector_code' => $this->resolveBackfillValue($profile->max_connector_code, config('bitrix24.openlines.max_connector_code')),
        ], static fn (mixed $value): bool => $value !== null);

        if ($updates === []) {
            return;
        }

        Bitrix24Profile::query()
            ->whereKey($profile->id)
            ->update(array_merge($updates, [
                'updated_at' => now(),
            ]));
    }

    private function resolveBackfillValue(mixed $currentValue, mixed $configValue): ?string
    {
        if ($this->nullableString($currentValue) !== null) {
            return null;
        }

        return $this->nullableString($configValue);
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
