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

        foreach ($this->crmSchemaBackfillValues($profile) as $column => $value) {
            if (Schema::hasColumn('bitrix24_profiles', $column)) {
                $updates[$column] = $value;
            }
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

    /**
     * @return array<string, mixed>
     */
    private function crmSchemaBackfillValues(Bitrix24Profile $profile): array
    {
        return [
            'crm_field_name_source' => $this->resolveBackfillValue($profile->crm_field_name_source, config('bitrix24.fields.name_source')),
            'crm_field_age_exact' => $this->resolveBackfillValue($profile->crm_field_age_exact, config('bitrix24.fields.age_exact')),
            'crm_field_gender' => $this->resolveBackfillValue($profile->crm_field_gender, config('bitrix24.fields.gender')),
            'crm_field_age_range' => $this->resolveBackfillValue($profile->crm_field_age_range, config('bitrix24.fields.age_range')),
            'crm_field_contact_id' => $this->resolveBackfillValue($profile->crm_field_contact_id, config('bitrix24.fields.contact_id')),
            'crm_field_channel_id' => $this->resolveBackfillValue($profile->crm_field_channel_id, config('bitrix24.fields.channel_id')),
            'crm_field_channel_name' => $this->resolveBackfillValue($profile->crm_field_channel_name, config('bitrix24.fields.channel_name')),
            'crm_field_platform' => $this->resolveBackfillValue($profile->crm_field_platform, config('bitrix24.fields.platform')),
            'crm_field_bot_code' => $this->resolveBackfillValue($profile->crm_field_bot_code, config('bitrix24.fields.bot_code')),
            'crm_field_bot_name' => $this->resolveBackfillValue($profile->crm_field_bot_name, config('bitrix24.fields.bot_name')),
            'crm_field_alt_first_name' => $this->resolveBackfillValue($profile->crm_field_alt_first_name, config('bitrix24.fields.alt_first_name')),
            'crm_field_alt_last_name' => $this->resolveBackfillValue($profile->crm_field_alt_last_name, config('bitrix24.fields.alt_last_name')),
            'crm_field_name_conflict' => $this->resolveBackfillValue($profile->crm_field_name_conflict, config('bitrix24.fields.name_conflict')),
            'crm_name_source_automatic_id' => $this->resolveIntegerBackfillValue($profile->crm_name_source_automatic_id, config('bitrix24.values.name_source.automatic_information_id')),
            'crm_name_source_self_reported_id' => $this->resolveIntegerBackfillValue($profile->crm_name_source_self_reported_id, config('bitrix24.values.name_source.self_reported_id')),
            'crm_name_source_training_verified_id' => $this->resolveIntegerBackfillValue($profile->crm_name_source_training_verified_id, config('bitrix24.values.name_source.training_verified_id')),
            'crm_gender_male_id' => $this->resolveIntegerBackfillValue($profile->crm_gender_male_id, config('bitrix24.values.gender.male_id')),
            'crm_gender_female_id' => $this->resolveIntegerBackfillValue($profile->crm_gender_female_id, config('bitrix24.values.gender.female_id')),
            'crm_gender_unknown_id' => $this->resolveIntegerBackfillValue($profile->crm_gender_unknown_id, config('bitrix24.values.gender.unknown_id')),
        ];
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
