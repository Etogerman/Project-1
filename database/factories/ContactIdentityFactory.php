<?php

namespace Database\Factories;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContactIdentity>
 */
class ContactIdentityFactory extends Factory
{
    protected $model = ContactIdentity::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'contact_id' => Contact::factory(),
            'channel_id' => Channel::factory(),
            'platform' => fn (array $attributes): string => Channel::query()
                ->findOrFail($attributes['channel_id'])
                ->platform,
            'external_user_id' => (string) fake()->unique()->numerify('########'),
            'display_name' => null,
            'external_username' => fake()->optional()->userName(),
            'avatar_path' => null,
            'avatar_updated_at' => null,
        ];
    }
}
