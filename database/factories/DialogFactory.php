<?php

namespace Database\Factories;

use App\Models\ContactIdentity;
use App\Models\Dialog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Dialog>
 */
class DialogFactory extends Factory
{
    protected $model = Dialog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'current_contact_identity_id' => ContactIdentity::factory(),
            'contact_id' => fn (array $attributes): int => ContactIdentity::query()
                ->findOrFail($attributes['current_contact_identity_id'])
                ->contact_id,
            'channel_id' => fn (array $attributes): int => ContactIdentity::query()
                ->findOrFail($attributes['current_contact_identity_id'])
                ->channel_id,
            'manual_reply_dismissed_source_message_id' => null,
            'bot_subscription_status' => null,
            'bot_subscription_changed_at' => null,
            'bot_subscription_source_message_id' => null,
            'external_chat_id' => (string) fake()->numerify('########'),
            'confirmed_phone_raw' => null,
            'confirmed_phone_normalized' => null,
            'phone_confirmed_at' => null,
            'phone_confirmed_via' => null,
            'last_message_at' => null,
            'last_inbound_at' => null,
            'last_outbound_at' => null,
        ];
    }

    public function withoutCurrentIdentity(): static
    {
        return $this->state(function (array $attributes): array {
            $identity = ContactIdentity::query()->findOrFail($attributes['current_contact_identity_id']);

            return [
                'contact_id' => $identity->contact_id,
                'channel_id' => $identity->channel_id,
                'current_contact_identity_id' => null,
            ];
        });
    }
}
