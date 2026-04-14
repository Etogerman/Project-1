<?php

namespace Database\Factories;

use App\Models\ContactIdentity;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    protected $model = Message::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'dialog_id' => null,
            'contact_identity_id' => ContactIdentity::factory(),
            'contact_id' => function (array $attributes): int {
                $identity = ContactIdentity::query()->find($attributes['contact_identity_id']);

                if ($identity === null) {
                    throw new ModelNotFoundException('Contact identity was not created for message factory.');
                }

                return $identity->contact_id;
            },
            'channel_id' => function (array $attributes): int {
                $identity = ContactIdentity::query()->find($attributes['contact_identity_id']);

                if ($identity === null) {
                    throw new ModelNotFoundException('Contact identity was not created for message factory.');
                }

                return $identity->channel_id;
            },
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'system_event_code' => null,
            'sent_by_type' => null,
            'sent_by_user_id' => null,
            'sent_by_system_code' => null,
            'reply_to_message_id' => null,
            'provider_event_key' => null,
            'external_chat_id' => (string) fake()->numerify('########'),
            'external_message_id' => (string) fake()->numerify('########'),
            'text' => fake()->optional()->sentence(),
            'text_format' => Message::TEXT_FORMAT_PLAIN_TEXT,
            'source_text' => null,
            'raw_payload' => ['message' => 'payload'],
            'received_at' => now(),
            'auto_reply_sent_at' => null,
        ];
    }
}
