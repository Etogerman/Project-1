<?php

namespace Database\Factories;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    protected $model = Contact::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->optional()->name(),
            'is_auto_reply_enabled' => true,
            'duplicate_review_status' => Contact::DUPLICATE_REVIEW_STATUS_NONE,
        ];
    }
}
