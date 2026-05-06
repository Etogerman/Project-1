<?php

namespace Database\Factories;

use App\Models\Channel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Channel>
 */
class ChannelFactory extends Factory
{
    protected $model = Channel::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $platform = fake()->randomElement(array_keys(Channel::platformOptions()));

        return [
            'name' => fake()->company().' Bot',
            'platform' => $platform,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'credentials' => [
                'token' => fake()->sha256(),
            ],
            'bot_token_present' => true,
            'bot_external_id' => null,
            'bot_username' => null,
            'bot_name' => null,
            'bot_profile_url' => null,
            'auto_reply_mode' => Channel::AUTO_REPLY_MODE_RULES_ONLY,
            'last_webhook_received_at' => null,
            'last_reply_sent_at' => null,
            'last_error_at' => null,
            'last_error_message' => null,
            'is_active' => true,
            'connection_status' => Channel::CONNECTION_STATUS_CONNECTED,
            'webhook_status' => Channel::WEBHOOK_STATUS_INSTALLED,
            'connection_checked_at' => now(),
            'connection_error_message' => null,
            'provider_webhook_url' => null,
            'expected_webhook_url' => null,
        ];
    }

    public function account(): static
    {
        return $this->state(fn (): array => [
            'name' => fake()->company().' Account',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_ACCOUNT,
            'credentials' => [],
            'bot_token_present' => false,
            'bot_external_id' => null,
            'bot_username' => null,
            'bot_name' => null,
            'bot_profile_url' => null,
            'last_webhook_received_at' => null,
            'last_reply_sent_at' => null,
            'last_error_at' => null,
            'last_error_message' => null,
            'connection_status' => Channel::CONNECTION_STATUS_UNSUPPORTED,
            'webhook_status' => Channel::WEBHOOK_STATUS_UNSUPPORTED,
            'connection_checked_at' => null,
            'connection_error_message' => Channel::CONNECTION_ERROR_UNSUPPORTED,
            'provider_webhook_url' => null,
            'expected_webhook_url' => null,
        ]);
    }
}
