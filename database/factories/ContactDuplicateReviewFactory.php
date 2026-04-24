<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\ContactDuplicateReview;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContactDuplicateReview>
 */
class ContactDuplicateReviewFactory extends Factory
{
    protected $model = ContactDuplicateReview::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'contact_id' => Contact::factory(),
            'phone_normalized' => '+79991234567',
            'identity_key' => null,
            'review_type' => ContactDuplicateReview::TYPE_PHONE_MULTIPLE_ROOTS,
            'routed_contact_id' => null,
            'candidate_root_contact_ids' => null,
            'context_payload' => null,
            'trigger_message_id' => null,
            'status' => ContactDuplicateReview::STATUS_OPEN,
            'reason' => null,
            'resolved_at' => null,
        ];
    }
}
