<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\ContactEmail;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContactEmail>
 */
class ContactEmailFactory extends Factory
{
    protected $model = ContactEmail::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $emailRaw = fake()->unique()->safeEmail();

        return [
            'contact_id' => Contact::factory(),
            'email_raw' => $emailRaw,
            'email_normalized' => ContactEmail::normalizeEmail($emailRaw),
            'source' => ContactEmail::SOURCE_MANUAL,
            'is_primary' => false,
        ];
    }
}
