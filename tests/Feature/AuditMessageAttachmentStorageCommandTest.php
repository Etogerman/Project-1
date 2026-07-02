<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Dialog;
use App\Models\Message;
use App\Models\MessageAttachment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AuditMessageAttachmentStorageCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_dry_run_does_not_change_missing_downloaded_attachment(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);

        $attachment = $this->createDownloadedAttachment([
            'provider_file_id' => 'telegram-account-file-id',
            'local_path' => MessageAttachment::LOCAL_PATH_PREFIX.'/missing/account.jpg',
        ]);

        $this->artisan('message-attachments:audit-storage', [
            '--attachment' => [$attachment->id],
        ])->assertExitCode(0);

        $attachment->refresh();

        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED, $attachment->download_status);
        $this->assertSame(MessageAttachment::LOCAL_DISK_PRIVATE, $attachment->local_disk);
        $this->assertSame(MessageAttachment::LOCAL_PATH_PREFIX.'/missing/account.jpg', $attachment->local_path);
    }

    public function test_command_repair_resets_retryable_missing_attachment_to_pending_download(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);

        $attachment = $this->createDownloadedAttachment([
            'provider_file_id' => 'telegram-account-file-id',
            'local_path' => MessageAttachment::LOCAL_PATH_PREFIX.'/missing/account.jpg',
        ]);

        $this->artisan('message-attachments:audit-storage', [
            '--repair' => true,
            '--attachment' => [$attachment->id],
        ])->assertExitCode(0);

        $attachment->refresh();

        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD, $attachment->download_status);
        $this->assertNull($attachment->local_disk);
        $this->assertNull($attachment->local_path);
        $this->assertSame('local_file_missing', $attachment->safe_error_code);
    }

    public function test_command_repair_keeps_existing_downloaded_attachment_unchanged(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);

        $attachment = $this->createDownloadedAttachment([
            'provider_file_id' => 'telegram-account-file-id',
            'local_path' => MessageAttachment::LOCAL_PATH_PREFIX.'/present/account.jpg',
        ]);
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)
            ->put((string) $attachment->local_path, 'present-bytes');

        $this->artisan('message-attachments:audit-storage', [
            '--repair' => true,
            '--attachment' => [$attachment->id],
        ])->assertExitCode(0);

        $attachment->refresh();

        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED, $attachment->download_status);
        $this->assertSame(MessageAttachment::LOCAL_DISK_PRIVATE, $attachment->local_disk);
        $this->assertSame(MessageAttachment::LOCAL_PATH_PREFIX.'/present/account.jpg', $attachment->local_path);
        $this->assertNull($attachment->safe_error_code);
    }

    public function test_command_repair_marks_non_retryable_missing_attachment_as_metadata_only(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);

        $attachment = $this->createDownloadedAttachment([
            'provider_file_id' => null,
            'local_path' => MessageAttachment::LOCAL_PATH_PREFIX.'/missing/non-retryable.jpg',
        ]);

        $this->artisan('message-attachments:audit-storage', [
            '--repair' => true,
            '--attachment' => [$attachment->id],
        ])->assertExitCode(0);

        $attachment->refresh();

        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_METADATA_ONLY, $attachment->download_status);
        $this->assertNull($attachment->local_disk);
        $this->assertNull($attachment->local_path);
        $this->assertSame('local_file_missing', $attachment->safe_error_code);
    }

    public function test_command_audits_configured_message_attachment_disk(): void
    {
        config()->set('filesystems.message_attachments_disk', MessageAttachment::LOCAL_DISK_MESSAGE_ATTACHMENTS);
        Storage::fake(MessageAttachment::LOCAL_DISK_MESSAGE_ATTACHMENTS);

        $attachment = $this->createDownloadedAttachment([
            'local_disk' => MessageAttachment::LOCAL_DISK_MESSAGE_ATTACHMENTS,
            'local_path' => MessageAttachment::LOCAL_PATH_PREFIX.'/missing/durable.jpg',
            'provider_file_id' => 'telegram-account-file-id',
        ]);

        $this->artisan('message-attachments:audit-storage', [
            '--repair' => true,
            '--attachment' => [$attachment->id],
        ])->assertExitCode(0);

        $attachment->refresh();

        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD, $attachment->download_status);
        $this->assertNull($attachment->local_disk);
        $this->assertNull($attachment->local_path);
    }

    /**
     * @param  array<string, mixed>  $attachmentAttributes
     */
    private function createDownloadedAttachment(array $attachmentAttributes = []): MessageAttachment
    {
        $channel = Channel::factory()->account()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'audit-storage-user-'.fake()->unique()->numerify('###'),
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'audit-storage-chat-'.fake()->unique()->numerify('###'),
        ]);
        $message = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'external_chat_id' => $dialog->external_chat_id,
            'external_message_id' => 'audit-storage-message-'.fake()->unique()->numerify('###'),
            'provider_event_key' => 'audit-storage-event-'.fake()->unique()->numerify('###'),
        ]);

        return MessageAttachment::factory()->create(array_merge([
            'message_id' => $message->id,
            'channel_id' => $channel->id,
            'provider' => MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT,
            'provider_event_key' => $message->provider_event_key,
            'provider_attachment_key' => 'audit-storage-file-'.fake()->unique()->numerify('###'),
            'provider_file_id' => 'audit-storage-provider-file-id',
            'media_kind' => MessageAttachment::MEDIA_KIND_IMAGE,
            'mime_type' => 'image/jpeg',
            'extension' => 'jpg',
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED,
            'local_disk' => MessageAttachment::LOCAL_DISK_PRIVATE,
            'local_path' => MessageAttachment::LOCAL_PATH_PREFIX.'/audit-storage/file.jpg',
        ], $attachmentAttributes));
    }
}
