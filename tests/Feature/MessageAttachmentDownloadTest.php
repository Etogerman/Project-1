<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Dialog;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Services\Messages\StoreMessageAttachmentLocalFileAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MessageAttachmentDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_with_dialog_view_permission_can_download_private_attachment(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);

        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
            'role' => User::ROLE_ADMIN,
        ]);
        $attachment = $this->createAttachment([
            'extension' => 'pdf',
            'mime_type' => 'application/pdf',
            'original_filename' => 'report/final.pdf',
            'file_size_bytes' => null,
        ]);

        $storedAttachment = app(StoreMessageAttachmentLocalFileAction::class)
            ->handle($attachment, 'private-pdf-bytes');

        $this->assertSame(
            'message-attachments/'.$storedAttachment->message_id.'/'.$storedAttachment->id.'.pdf',
            $storedAttachment->local_path,
        );
        $this->assertTrue($storedAttachment->isLocallyDownloadable());

        $response = $this->actingAs($admin)
            ->get(route('admin.message-attachments.download', $storedAttachment));

        $response->assertOk();
        $this->assertSame('private-pdf-bytes', $response->streamedContent());
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString(
            'report-final.pdf',
            (string) $response->headers->get('Content-Disposition'),
        );
    }

    public function test_store_action_uses_configured_message_attachment_disk(): void
    {
        config()->set('filesystems.message_attachments_disk', MessageAttachment::LOCAL_DISK_MESSAGE_ATTACHMENTS);
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        Storage::fake(MessageAttachment::LOCAL_DISK_MESSAGE_ATTACHMENTS);

        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
            'role' => User::ROLE_ADMIN,
        ]);
        $attachment = $this->createAttachment([
            'extension' => 'pdf',
            'mime_type' => 'application/pdf',
            'original_filename' => 'durable.pdf',
        ]);

        $storedAttachment = app(StoreMessageAttachmentLocalFileAction::class)
            ->handle($attachment, 'durable-pdf-bytes');

        $this->assertSame(MessageAttachment::LOCAL_DISK_MESSAGE_ATTACHMENTS, $storedAttachment->local_disk);
        $this->assertTrue($storedAttachment->isLocallyDownloadable());
        Storage::disk(MessageAttachment::LOCAL_DISK_MESSAGE_ATTACHMENTS)
            ->assertExists((string) $storedAttachment->local_path);
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)
            ->assertMissing((string) $storedAttachment->local_path);

        $response = $this->actingAs($admin)
            ->get(route('admin.message-attachments.download', $storedAttachment));

        $response->assertOk();
        $this->assertSame('durable-pdf-bytes', $response->streamedContent());
    }

    public function test_existing_local_attachment_remains_downloadable_when_message_attachment_disk_is_configured(): void
    {
        config()->set('filesystems.message_attachments_disk', MessageAttachment::LOCAL_DISK_MESSAGE_ATTACHMENTS);
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        Storage::fake(MessageAttachment::LOCAL_DISK_MESSAGE_ATTACHMENTS);

        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
            'role' => User::ROLE_ADMIN,
        ]);
        $attachment = $this->createAttachment([
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED,
            'local_disk' => MessageAttachment::LOCAL_DISK_PRIVATE,
            'local_path' => MessageAttachment::LOCAL_PATH_PREFIX.'/legacy/local.txt',
            'mime_type' => 'text/plain',
            'extension' => 'txt',
        ]);

        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)
            ->put((string) $attachment->local_path, 'legacy-local-bytes');

        $this->assertTrue($attachment->isLocallyDownloadable());

        $response = $this->actingAs($admin)
            ->get(route('admin.message-attachments.download', $attachment));

        $response->assertOk();
        $this->assertSame('legacy-local-bytes', $response->streamedContent());
    }

    public function test_guest_is_redirected_before_downloading_attachment(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);

        $attachment = app(StoreMessageAttachmentLocalFileAction::class)
            ->handle($this->createAttachment(), 'private-bytes');

        $this->get(route('admin.message-attachments.download', $attachment))
            ->assertRedirect('/admin/login');
    }

    public function test_user_with_dialog_view_permission_can_preview_private_image_attachment(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);

        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
            'role' => User::ROLE_ADMIN,
        ]);
        $attachment = $this->createAttachment([
            'media_kind' => MessageAttachment::MEDIA_KIND_IMAGE,
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'original_filename' => 'photo.jpg',
            'file_size_bytes' => null,
        ]);

        $storedAttachment = app(StoreMessageAttachmentLocalFileAction::class)
            ->handle($attachment, 'private-jpeg-bytes', 'jpg');

        $this->assertTrue($storedAttachment->isInlinePreviewable());

        $response = $this->actingAs($admin)
            ->get(route('admin.message-attachments.preview', $storedAttachment));

        $response->assertOk();
        $this->assertSame('private-jpeg-bytes', $response->streamedContent());
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('inline', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_preview_uses_safe_image_extension_when_mime_type_is_missing(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);

        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
            'role' => User::ROLE_ADMIN,
        ]);
        $attachment = $this->createAttachment([
            'media_kind' => MessageAttachment::MEDIA_KIND_IMAGE,
            'extension' => 'jpg',
            'mime_type' => null,
            'original_filename' => 'photo.jpg',
        ]);

        $storedAttachment = app(StoreMessageAttachmentLocalFileAction::class)
            ->handle($attachment, 'private-jpeg-bytes', 'jpg');

        $this->assertTrue($storedAttachment->isInlinePreviewable());

        $response = $this->actingAs($admin)
            ->get(route('admin.message-attachments.preview', $storedAttachment));

        $response->assertOk();
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
    }

    public function test_user_with_dialog_view_permission_cannot_inline_preview_private_pdf_attachment(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);

        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
            'role' => User::ROLE_ADMIN,
        ]);
        $attachment = app(StoreMessageAttachmentLocalFileAction::class)
            ->handle($this->createAttachment([
                'media_kind' => MessageAttachment::MEDIA_KIND_DOCUMENT,
                'mime_type' => 'application/pdf',
                'extension' => 'pdf',
                'original_filename' => 'contract.pdf',
            ]), "%PDF-1.4\nprivate-pdf-bytes", 'pdf');

        $this->assertFalse($attachment->isInlinePreviewable());
        $this->assertNull($attachment->previewKind());

        $response = $this->actingAs($admin)
            ->get(route('admin.message-attachments.preview', $attachment));

        $response->assertNotFound();
    }

    public function test_user_with_dialog_view_permission_can_preview_browser_playable_video_attachment(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);

        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
            'role' => User::ROLE_ADMIN,
        ]);
        $attachment = app(StoreMessageAttachmentLocalFileAction::class)
            ->handle($this->createAttachment([
                'media_kind' => MessageAttachment::MEDIA_KIND_VIDEO,
                'mime_type' => 'video/mp4',
                'extension' => 'mp4',
                'original_filename' => 'clip.mp4',
            ]), 'private-video-bytes', 'mp4');

        $this->assertTrue($attachment->isInlinePreviewable());
        $this->assertSame(MessageAttachment::PREVIEW_KIND_VIDEO, $attachment->previewKind());

        $response = $this->actingAs($admin)
            ->get(route('admin.message-attachments.preview', $attachment));

        $response->assertOk();
        $this->assertSame('private-video-bytes', $response->streamedContent());
        $this->assertSame('video/mp4', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('inline', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_user_with_dialog_view_permission_can_preview_max_video_poster(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        Http::fake([
            'https://pimg.mycdn.me/getImage*' => Http::response('poster-bytes', 200, [
                'Content-Type' => 'image/jpeg',
            ]),
        ]);
        config()->set('bots.max.trusted_media_hosts', ['mycdn.me']);

        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
            'role' => User::ROLE_ADMIN,
        ]);
        $attachment = app(StoreMessageAttachmentLocalFileAction::class)
            ->handle($this->createAttachment([
                'provider' => MessageAttachment::PROVIDER_MAX_BOT,
                'provider_file_reference' => 'token:'.sha1('max-video-token'),
                'provider_attachment_key' => 'token:'.sha1('max-video-token'),
                'media_kind' => MessageAttachment::MEDIA_KIND_VIDEO,
                'mime_type' => 'video/mp4',
                'extension' => 'mp4',
                'original_filename' => 'clip.mp4',
            ]), 'private-video-bytes', 'mp4');

        $attachment->message()->update([
            'raw_payload' => [
                'message' => [
                    'body' => [
                        'attachments' => [
                            [
                                'type' => 'video',
                                'payload' => [
                                    'token' => 'max-video-token',
                                ],
                                'thumbnail' => [
                                    'url' => 'https://pimg.mycdn.me/getImage?signatureToken=secret-poster-token',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $response = $this->actingAs($admin)
            ->get(route('admin.message-attachments.poster', $attachment));

        $response->assertOk();
        $this->assertSame('poster-bytes', $response->getContent());
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('inline', (string) $response->headers->get('Content-Disposition'));
        Http::assertSent(
            static fn ($request): bool => str_starts_with($request->url(), 'https://pimg.mycdn.me/getImage?'),
        );
    }

    public function test_max_video_poster_rejects_untrusted_thumbnail_host(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);
        Http::fake();
        config()->set('bots.max.trusted_media_hosts', ['mycdn.me']);

        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
            'role' => User::ROLE_ADMIN,
        ]);
        $attachment = app(StoreMessageAttachmentLocalFileAction::class)
            ->handle($this->createAttachment([
                'provider' => MessageAttachment::PROVIDER_MAX_BOT,
                'provider_file_reference' => 'token:'.sha1('max-video-token'),
                'provider_attachment_key' => 'token:'.sha1('max-video-token'),
                'media_kind' => MessageAttachment::MEDIA_KIND_VIDEO,
                'mime_type' => 'video/mp4',
                'extension' => 'mp4',
                'original_filename' => 'clip.mp4',
            ]), 'private-video-bytes', 'mp4');

        $attachment->message()->update([
            'raw_payload' => [
                'message' => [
                    'body' => [
                        'attachments' => [
                            [
                                'type' => 'video',
                                'payload' => [
                                    'token' => 'max-video-token',
                                ],
                                'thumbnail' => [
                                    'url' => 'https://evil.example/getImage?signatureToken=secret-poster-token',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->actingAs($admin)
            ->get(route('admin.message-attachments.poster', $attachment))
            ->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_user_with_dialog_view_permission_can_preview_browser_playable_audio_attachment(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);

        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
            'role' => User::ROLE_ADMIN,
        ]);
        $attachment = app(StoreMessageAttachmentLocalFileAction::class)
            ->handle($this->createAttachment([
                'media_kind' => MessageAttachment::MEDIA_KIND_VOICE,
                'mime_type' => 'audio/mpeg',
                'extension' => 'mp3',
                'original_filename' => 'voice.mp3',
            ]), 'private-audio-bytes', 'mp3');

        $this->assertTrue($attachment->isInlinePreviewable());
        $this->assertSame(MessageAttachment::PREVIEW_KIND_AUDIO, $attachment->previewKind());

        $response = $this->actingAs($admin)
            ->get(route('admin.message-attachments.preview', $attachment));

        $response->assertOk();
        $this->assertSame('private-audio-bytes', $response->streamedContent());
        $this->assertSame('audio/mpeg', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('inline', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_voice_preview_uses_audio_mime_for_ogg_extension_when_mime_type_is_generic(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);

        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
            'role' => User::ROLE_ADMIN,
        ]);
        $attachment = app(StoreMessageAttachmentLocalFileAction::class)
            ->handle($this->createAttachment([
                'media_kind' => MessageAttachment::MEDIA_KIND_VOICE,
                'mime_type' => 'application/octet-stream',
                'extension' => 'ogg',
                'original_filename' => 'voice.ogg',
            ]), 'private-ogg-bytes', 'ogg');

        $this->assertTrue($attachment->isInlinePreviewable());
        $this->assertSame(MessageAttachment::PREVIEW_KIND_AUDIO, $attachment->previewKind());
        $this->assertSame('audio/ogg', $attachment->previewMimeType());

        $response = $this->actingAs($admin)
            ->get(route('admin.message-attachments.preview', $attachment));

        $response->assertOk();
        $this->assertSame('audio/ogg', $response->headers->get('Content-Type'));
    }

    public function test_preview_rejects_non_browser_playable_video_attachment(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);

        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
            'role' => User::ROLE_ADMIN,
        ]);
        $attachment = app(StoreMessageAttachmentLocalFileAction::class)
            ->handle($this->createAttachment([
                'media_kind' => MessageAttachment::MEDIA_KIND_VIDEO,
                'mime_type' => 'video/quicktime',
                'extension' => 'mov',
                'original_filename' => 'clip.mov',
            ]), 'private-mov-bytes', 'mov');

        $this->assertFalse($attachment->isInlinePreviewable());

        $this->actingAs($admin)
            ->get(route('admin.message-attachments.preview', $attachment))
            ->assertNotFound();
    }

    public function test_preview_rejects_non_browser_playable_audio_attachment(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);

        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
            'role' => User::ROLE_ADMIN,
        ]);
        $attachment = app(StoreMessageAttachmentLocalFileAction::class)
            ->handle($this->createAttachment([
                'media_kind' => MessageAttachment::MEDIA_KIND_AUDIO,
                'mime_type' => 'audio/flac',
                'extension' => 'flac',
                'original_filename' => 'track.flac',
            ]), 'private-flac-bytes', 'flac');

        $this->assertFalse($attachment->isInlinePreviewable());

        $this->actingAs($admin)
            ->get(route('admin.message-attachments.preview', $attachment))
            ->assertNotFound();
    }

    public function test_preview_rejects_non_previewable_document_attachment(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);

        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
            'role' => User::ROLE_ADMIN,
        ]);
        $attachment = app(StoreMessageAttachmentLocalFileAction::class)
            ->handle($this->createAttachment([
                'media_kind' => MessageAttachment::MEDIA_KIND_DOCUMENT,
                'mime_type' => 'text/plain',
                'extension' => 'txt',
            ]), 'plain-document-bytes', 'txt');

        $this->assertFalse($attachment->isInlinePreviewable());

        $this->actingAs($admin)
            ->get(route('admin.message-attachments.preview', $attachment))
            ->assertNotFound();
    }

    public function test_preview_rejects_svg_even_when_attachment_is_image(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);

        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
            'role' => User::ROLE_ADMIN,
        ]);
        $attachment = app(StoreMessageAttachmentLocalFileAction::class)
            ->handle($this->createAttachment([
                'media_kind' => MessageAttachment::MEDIA_KIND_IMAGE,
                'mime_type' => 'image/svg+xml',
                'extension' => 'svg',
            ]), '<svg></svg>', 'svg');

        $this->assertFalse($attachment->isInlinePreviewable());

        $this->actingAs($admin)
            ->get(route('admin.message-attachments.preview', $attachment))
            ->assertNotFound();
    }

    public function test_non_numeric_attachment_route_key_returns_not_found(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
            'role' => User::ROLE_ADMIN,
        ]);

        $this->actingAs($admin)
            ->get('/admin/message-attachments/not-a-number/download')
            ->assertNotFound();
    }

    public function test_user_without_dialog_view_permission_cannot_download_attachment(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);

        DB::table('role_permissions')
            ->where('role', User::ROLE_EMPLOYEE)
            ->where('permission_key', 'dialogs.view')
            ->update([
                'granted' => false,
                'updated_at' => now(),
            ]);

        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
            'role' => User::ROLE_EMPLOYEE,
        ]);
        $attachment = app(StoreMessageAttachmentLocalFileAction::class)
            ->handle($this->createAttachment(), 'private-bytes');

        $this->actingAs($employee)
            ->get(route('admin.message-attachments.download', $attachment))
            ->assertForbidden();
    }

    public function test_download_returns_not_found_when_private_file_is_missing(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);

        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
            'role' => User::ROLE_ADMIN,
        ]);
        $attachment = $this->createAttachment([
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED,
            'local_disk' => MessageAttachment::LOCAL_DISK_PRIVATE,
            'local_path' => 'message-attachments/999/missing.pdf',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.message-attachments.download', $attachment))
            ->assertNotFound();
    }

    public function test_download_falls_back_to_octet_stream_for_unsafe_mime_type(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);

        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
            'role' => User::ROLE_ADMIN,
        ]);
        $attachment = $this->createAttachment([
            'mime_type' => "text/plain\r\nX-Injected: yes",
            'original_filename' => "unsafe\r\nname.pdf",
        ]);

        $storedAttachment = app(StoreMessageAttachmentLocalFileAction::class)
            ->handle($attachment, 'private-bytes', 'pdf');

        $response = $this->actingAs($admin)
            ->get(route('admin.message-attachments.download', $storedAttachment));

        $response->assertOk();
        $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));
        $this->assertStringContainsString(
            'unsafe name.pdf',
            (string) $response->headers->get('Content-Disposition'),
        );
    }

    public function test_download_rejects_local_path_traversal(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);

        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
            'role' => User::ROLE_ADMIN,
        ]);
        $attachment = $this->createAttachment([
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED,
            'local_disk' => MessageAttachment::LOCAL_DISK_PRIVATE,
            'local_path' => 'message-attachments/1/../secret.pdf',
        ]);

        $this->assertFalse($attachment->isLocallyDownloadable());

        $this->actingAs($admin)
            ->get(route('admin.message-attachments.download', $attachment))
            ->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $attachmentAttributes
     */
    private function createAttachment(array $attachmentAttributes = []): MessageAttachment
    {
        $channel = Channel::factory()->account()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'attachment-download-user-'.fake()->unique()->numerify('###'),
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'attachment-download-chat-'.fake()->unique()->numerify('###'),
        ]);
        $message = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'external_chat_id' => $dialog->external_chat_id,
            'external_message_id' => 'attachment-download-message-'.fake()->unique()->numerify('###'),
            'provider_event_key' => 'attachment-download-event-'.fake()->unique()->numerify('###'),
        ]);

        return MessageAttachment::factory()->create(array_merge([
            'message_id' => $message->id,
            'channel_id' => $channel->id,
            'provider' => MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT,
            'provider_event_key' => $message->provider_event_key,
            'provider_attachment_key' => 'attachment-download-file-'.fake()->unique()->numerify('###'),
        ], $attachmentAttributes));
    }
}
