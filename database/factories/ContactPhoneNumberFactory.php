<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\ContactPhoneNumber;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContactPhoneNumber>
 */
class ContactPhoneNumberFactory extends Factory
{
    protected $model = ContactPhoneNumber::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $phoneRaw = '+7 '.fake()->numerify('9## ### ## ##');
        $phoneNormalized = preg_replace('/[^\d+]/u', '', $phoneRaw) ?: '+79990000000';

        return [
            'contact_id' => Contact::factory(),
            'phone_raw' => $phoneRaw,
            'phone_normalized' => $phoneNormalized,
            'source' => ContactPhoneNumber::SOURCE_TELEGRAM_CONTACT_SHARE,
            'is_primary' => false,
        ];
    }
}
