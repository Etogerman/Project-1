<?php

namespace Database\Factories;

use App\Models\Channel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Channel>
 */
class ChannelFactory extends Factory
{
    protected $model = Channel::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company().' Bot',
            'platform' => fake()->randomElement(array_keys(Channel::platformOptions())),
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
        ];
    }
}
