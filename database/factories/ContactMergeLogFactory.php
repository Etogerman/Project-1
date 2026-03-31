<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\ContactMergeLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContactMergeLog>
 */
class ContactMergeLogFactory extends Factory
{
    protected $model = ContactMergeLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'primary_contact_id' => Contact::factory(),
            'secondary_contact_id' => Contact::factory(),
            'trigger_phone' => '+79991234567',
            'trigger_message_id' => null,
            'merge_reason' => 'phone_exact_match',
            'messages_moved_count' => 0,
            'identities_moved_count' => 0,
            'phones_moved_count' => 0,
            'fields_copied' => null,
            'fields_conflicted' => null,
            'created_by_type' => ContactMergeLog::CREATED_BY_TYPE_SYSTEM,
            'created_at' => now(),
        ];
    }
}
