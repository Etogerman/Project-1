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
            'is_active' => true,
        ];
    }
}
