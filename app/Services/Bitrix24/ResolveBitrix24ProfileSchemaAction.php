<?php

namespace App\Services\Bitrix24;

use App\Models\Bitrix24Profile;

class ResolveBitrix24ProfileSchemaAction
{
    private bool $profileResolved = false;

    private ?Bitrix24Profile $profile = null;

    public function __construct(
        private readonly ResolveCurrentBitrix24ProfileAction $resolveCurrentProfileAction,
    ) {}

    /**
     * @return array<string, string>
     */
    public function fields(): array
    {
        $profile = $this->profile();

        if ($profile instanceof Bitrix24Profile) {
            return $profile->effectiveCrmFields();
        }

        /** @var array<string, string> $fields */
        $fields = config('bitrix24.fields', []);

        return $fields;
    }

    /**
     * @return array{name_source: array<string, int>, gender: array<string, int>}
     */
    public function values(): array
    {
        $profile = $this->profile();

        if ($profile instanceof Bitrix24Profile) {
            return $profile->effectiveCrmValues();
        }

        return [
            'name_source' => [
                'automatic_information_id' => (int) config('bitrix24.values.name_source.automatic_information_id'),
                'self_reported_id' => (int) config('bitrix24.values.name_source.self_reported_id'),
                'training_verified_id' => (int) config('bitrix24.values.name_source.training_verified_id'),
            ],
            'gender' => [
                'male_id' => (int) config('bitrix24.values.gender.male_id'),
                'female_id' => (int) config('bitrix24.values.gender.female_id'),
                'unknown_id' => (int) config('bitrix24.values.gender.unknown_id'),
            ],
        ];
    }

    private function profile(): ?Bitrix24Profile
    {
        if ($this->profileResolved) {
            return $this->profile;
        }

        try {
            $this->profile = $this->resolveCurrentProfileAction->handle();
        } catch (Bitrix24ConnectionStateException) {
            $this->profile = null;
        }

        $this->profileResolved = true;

        return $this->profile;
    }
}
