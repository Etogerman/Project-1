<?php

namespace App\Services\Bitrix24;

use App\Models\Channel;
use App\Models\Contact;
use App\Services\Contacts\ResolveRootContactAction;

class BuildBitrix24ContactPayloadAction
{
    public function __construct(
        private readonly ResolveRootContactAction $resolveRootContactAction,
        private readonly CollectBitrix24ContactPhonesAction $collectContactPhonesAction,
        private readonly ResolveBitrix24ContactSourceAction $resolveContactSourceAction,
        private readonly ResolveBitrix24ProfileSchemaAction $resolveProfileSchemaAction,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(Contact|int $contact): array
    {
        $rootContact = $this->resolveRootContactAction->handle($contact);
        $primaryIdentity = $rootContact->primaryIdentity()->with('channel')->first();
        $channel = $primaryIdentity?->channel;
        $sourceId = $this->resolveContactSourceAction->handle($rootContact);
        $fields = $this->resolveProfileSchemaAction->fields();
        $nameSourceId = $this->resolveNameSourceId($rootContact);
        $phones = $this->collectContactPhonesAction->handle($rootContact);

        if (! $channel instanceof Channel || ! filled($sourceId)) {
            return [];
        }

        $payload = array_filter([
            'NAME' => $this->nullableString($rootContact->first_name),
            'LAST_NAME' => $this->nullableString($rootContact->last_name),
            'ADDRESS_CITY' => $this->nullableString($rootContact->city),
            'ADDRESS_COUNTRY' => $this->nullableString($rootContact->country),
            'SOURCE_ID' => $sourceId,
            $fields['name_source'] => $nameSourceId,
            $fields['age_exact'] => $rootContact->effective_age_years,
            $fields['age_range'] => $this->nullableString($rootContact->age_range),
            $fields['gender'] => $this->resolveGenderFieldValue($rootContact->gender),
            $fields['contact_id'] => (string) $rootContact->id,
            $fields['channel_id'] => (string) $channel->id,
            $fields['channel_name'] => $this->nullableString($channel->name),
            $fields['platform'] => $this->nullableString($channel->platform),
            $fields['bot_code'] => $this->resolveBotCode($channel),
            $fields['bot_name'] => $this->resolveBotName($channel),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        if ($phones !== []) {
            $payload['PHONE'] = array_map(
                static fn (string $phone, int $index): array => [
                    'VALUE' => $phone,
                    'VALUE_TYPE' => $index === 0 ? 'WORK' : 'OTHER',
                ],
                $phones,
                array_keys($phones),
            );
        }

        return $payload;
    }

    private function resolveBotCode(Channel $channel): ?string
    {
        foreach ([$channel->bot_external_id, $channel->bot_username] as $value) {
            $string = $this->nullableString($value);

            if ($string !== null) {
                return $string;
            }
        }

        return 'channel:'.$channel->id;
    }

    private function resolveBotName(Channel $channel): ?string
    {
        return $this->nullableString($channel->bot_name)
            ?? $this->nullableString($channel->name);
    }

    private function resolveGenderFieldValue(?string $gender): ?int
    {
        $values = $this->resolveProfileSchemaAction->values();

        return match ($gender) {
            'male' => $values['gender']['male_id'],
            'female' => $values['gender']['female_id'],
            'unknown' => $values['gender']['unknown_id'],
            default => null,
        };
    }

    private function resolveNameSourceId(Contact $contact): ?int
    {
        $values = $this->resolveProfileSchemaAction->values();

        return match ($contact->first_name_source) {
            Contact::FIRST_NAME_SOURCE_AUTO => $values['name_source']['automatic_information_id'],
            Contact::FIRST_NAME_SOURCE_CONTACT_CONFIRMED => $values['name_source']['self_reported_id'],
            Contact::FIRST_NAME_SOURCE_MANUAL => $values['name_source']['training_verified_id'],
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
