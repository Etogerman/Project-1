<?php

namespace Database\Factories;

use App\Models\Message;
use App\Models\MessageAttachment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * @extends Factory<MessageAttachment>
 */
class MessageAttachmentFactory extends Factory
{
    protected $model = MessageAttachment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'message_id' => Message::factory(),
            'channel_id' => function (array $attributes): int {
                $message = Message::query()->find($attributes['message_id']);

                if ($message === null) {
                    throw new ModelNotFoundException('Message was not created for attachment factory.');
                }

                return $message->channel_id;
            },
            'provider' => 'telegram_account',
            'provider_event_key' => fake()->uuid(),
            'provider_attachment_key' => fake()->uuid(),
            'outbound_attachment_key' => null,
            'media_kind' => MessageAttachment::MEDIA_KIND_DOCUMENT,
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'original_filename' => fake()->word().'.pdf',
            'file_size_bytes' => fake()->numberBetween(1000, 500000),
            'provider_file_id' => fake()->uuid(),
            'provider_file_unique_id' => fake()->uuid(),
            'provider_file_reference' => null,
            'provider_metadata' => [],
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD,
            'send_status' => MessageAttachment::SEND_STATUS_NOT_APPLICABLE,
            'local_disk' => null,
            'local_path' => null,
            'safe_error_code' => null,
            'safe_error_message' => null,
            'raw_payload_excerpt' => [],
            'sort_order' => 0,
        ];
    }
}
