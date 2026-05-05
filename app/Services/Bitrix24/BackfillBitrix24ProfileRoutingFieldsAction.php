<?php

namespace App\Services\Bitrix24;

use App\Models\Bitrix24Profile;
use Illuminate\Support\Facades\Schema;

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

        $updates = [
            'telegram_source_id' => $this->resolveBackfillValue($profile->telegram_source_id, config('bitrix24.sources.telegram_id')),
            'max_source_id' => $this->resolveBackfillValue($profile->max_source_id, config('bitrix24.sources.max_id')),
            'telegram_connector_code' => $this->resolveBackfillValue($profile->telegram_connector_code, config('bitrix24.openlines.telegram_connector_code')),
            'max_connector_code' => $this->resolveBackfillValue($profile->max_connector_code, config('bitrix24.openlines.max_connector_code')),
        ];

        if (Schema::hasColumn('bitrix24_profiles', 'default_assigned_user_id')) {
            $updates['default_assigned_user_id'] = $this->resolveIntegerBackfillValue($profile->default_assigned_user_id, config('bitrix24.defaults.assigned_user_id'));
        }

        if (Schema::hasColumn('bitrix24_profiles', 'default_deal_category_id')) {
            $updates['default_deal_category_id'] = $this->resolveIntegerBackfillValue($profile->default_deal_category_id, config('bitrix24.defaults.deal_category_id'));
        }

        if (Schema::hasColumn('bitrix24_profiles', 'default_deal_stage_id')) {
            $updates['default_deal_stage_id'] = $this->resolveBackfillValue($profile->default_deal_stage_id, config('bitrix24.defaults.deal_stage_id'));
        }

        $updates = array_filter($updates, static fn (mixed $value): bool => $value !== null);

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

    private function resolveIntegerBackfillValue(mixed $currentValue, mixed $configValue): ?int
    {
        if (is_numeric($currentValue)) {
            return null;
        }

        if (! is_numeric($configValue)) {
            return null;
        }

        $integer = (int) $configValue;

        return $integer >= 0 ? $integer : null;
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
