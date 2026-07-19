<?php

namespace Tests\Feature;

use App\Data\Messages\PreparedMessageAttachmentFile;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Dialog;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Services\Messages\DeleteRolledBackInboundMediaFileAction;
use App\Services\Messages\MediaDownloadIntegrityException;
use App\Services\Messages\MediaDownloadLeaseLostException;
use App\Services\Messages\StoreMessageAttachmentLocalFileAction;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

class MessageAttachmentDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_display_filename_is_reduced_to_a_normalized_safe_basename(): void
    {
        $attachment = new MessageAttachment([
            'original_filename' => "../../folder/\u{202E}e\u{0301}vil\0.pdf",
        ]);

        $this->assertSame('évil.pdf', $attachment->original_filename);
        $this->assertSame('évil.pdf', $attachment->downloadFilename());
    }

    public function test_store_action_rolls_back_attachment_and_file_when_finalization_fails(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);

        $attachment = $this->createAttachment([
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING,
            'extension' => 'pdf',
            'mime_type' => 'application/pdf',
        ]);
        $path = app(StoreMessageAttachmentLocalFileAction::class)->buildPath($attachment, 'pdf');
        $stream = fopen('php://temp', 'w+b');

        $this->assertIsResource($stream);
        fwrite($stream, 'private-pdf-bytes');
        rewind($stream);

        try {
            app(StoreMessageAttachmentLocalFileAction::class)->handleStream(
                $attachment,
                $stream,
                strlen('private-pdf-bytes'),
                'pdf',
                static function (): void {
                    throw new RuntimeException('Quota finalization failed.');
                },
            );

            $this->fail('Expected quota finalization to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Quota finalization failed.', $exception->getMessage());
        } finally {
            fclose($stream);
        }

        $attachment->refresh();

        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING, $attachment->download_status);
        $this->assertNull($attachment->local_disk);
        $this->assertNull($attachment->local_path);
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->assertMissing($path);
        $this->assertSame(
            [],
            Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)
                ->allFiles(MessageAttachment::LOCAL_PATH_PREFIX.'/'.$attachment->message_id),
        );
    }

    public function test_store_action_rejects_a_stream_whose_actual_size_differs_from_declared_size(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);

        $attachment = $this->createAttachment([
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING,
            'extension' => 'pdf',
            'mime_type' => 'application/pdf',
        ]);
        $stream = fopen('php://temp', 'w+b');

        $this->assertIsResource($stream);
        fwrite($stream, 'private-pdf-bytes');
        rewind($stream);

        try {
            app(StoreMessageAttachmentLocalFileAction::class)->handleStream(
                $attachment,
                $stream,
                1,
                'pdf',
            );

            $this->fail('Expected stream integrity validation to fail.');
        } catch (MediaDownloadIntegrityException $exception) {
            $this->assertSame(
                'Stored media size does not match the declared file size.',
                $exception->getMessage(),
            );
        } finally {
            fclose($stream);
        }

        $attachment->refresh();

        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING, $attachment->download_status);
        $this->assertNull($attachment->local_disk);
        $this->assertNull($attachment->local_path);
        $this->assertSame(
            [],
            Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)
                ->allFiles(MessageAttachment::LOCAL_PATH_PREFIX.'/'.$attachment->message_id),
        );
    }

    public function test_store_action_schedules_durable_cleanup_for_a_failed_prepared_file(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);

        $attachment = $this->createAttachment([
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING,
            'extension' => 'pdf',
            'mime_type' => 'application/pdf',
        ]);
        $cleanup = Mockery::mock(DeleteRolledBackInboundMediaFileAction::class);
        $cleanup->shouldReceive('handlePrepared')
            ->once()
            ->withArgs(function (int $attachmentId, string $disk, string $path) use ($attachment): bool {
                return $attachmentId === (int) $attachment->getKey()
                    && $disk === MessageAttachment::LOCAL_DISK_PRIVATE
                    && str_contains($path, '.g1.unclaimed.commit.')
                    && str_ends_with($path, '.pdf');
            })
            ->andReturnFalse();
        $stream = fopen('php://temp', 'w+b');

        $this->assertIsResource($stream);
        fwrite($stream, 'private-pdf-bytes');
        rewind($stream);

        try {
            (new StoreMessageAttachmentLocalFileAction($cleanup))->handleStream(
                $attachment,
                $stream,
                1,
                'pdf',
            );

            $this->fail('Expected stream integrity validation to fail.');
        } catch (MediaDownloadIntegrityException) {
            $this->assertTrue(true);
        } finally {
            fclose($stream);
        }
    }

    public function test_prepare_stream_exposes_throttled_storage_progress_callbacks_for_put(): void
    {
        config()->set(
            'filesystems.disks.'.MessageAttachment::LOCAL_DISK_PRIVATE.'.driver',
            's3',
        );

        $attachment = $this->createAttachment([
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING,
            'extension' => 'pdf',
            'mime_type' => 'application/pdf',
        ]);
        $contents = 'private-pdf-bytes';
        $stream = fopen('php://temp', 'w+b');
        $heartbeatCount = 0;
        $storage = Mockery::mock(FilesystemAdapter::class);

        $this->assertIsResource($stream);
        fwrite($stream, $contents);
        rewind($stream);

        $storage->shouldReceive('put')
            ->once()
            ->andReturnUsing(function (string $path, mixed $storedStream, array $options) use (&$heartbeatCount): bool {
                $this->assertIsResource($storedStream);
                $this->assertStringContainsString('.g1.unclaimed.commit.', $path);
                $this->assertIsCallable($options['before_upload'] ?? null);
                $this->assertIsCallable(data_get($options, 'params.@http.progress'));
                $this->assertSame(1, $heartbeatCount);

                data_get($options, 'params.@http.progress')();
                $this->assertSame(1, $heartbeatCount);

                $this->travel(31)->seconds();
                data_get($options, 'params.@http.progress')();
                $this->assertSame(2, $heartbeatCount);

                $this->travel(31)->seconds();
                $options['before_upload']();
                $this->assertSame(3, $heartbeatCount);

                return true;
            });
        $storage->shouldReceive('size')
            ->once()
            ->with(Mockery::type('string'))
            ->andReturn(strlen($contents));
        Storage::shouldReceive('disk')
            ->with(MessageAttachment::LOCAL_DISK_PRIVATE)
            ->andReturn($storage);

        try {
            $prepared = app(StoreMessageAttachmentLocalFileAction::class)->prepareStream(
                $attachment,
                $stream,
                strlen($contents),
                'pdf',
                onStorageProgress: static function () use (&$heartbeatCount): void {
                    $heartbeatCount++;
                },
            );
        } finally {
            fclose($stream);
        }

        $this->assertSame(strlen($contents), $prepared->sizeBytes);
        $this->assertSame(4, $heartbeatCount);
    }

    public function test_prepare_stream_heartbeats_between_large_local_file_chunks(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);

        $fileSizeBytes = (64 * 1024 * 1024) + 1;
        $attachment = $this->createAttachment([
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING,
            'extension' => 'bin',
            'mime_type' => 'application/octet-stream',
        ]);
        $heartbeatCount = 0;
        $stream = tmpfile();

        $this->assertIsResource($stream);
        $this->assertSame(0, fseek($stream, $fileSizeBytes - 1));
        $this->assertSame(1, fwrite($stream, 'a'));
        $sourcePath = stream_get_meta_data($stream)['uri'] ?? null;
        $this->assertIsString($sourcePath);
        $expectedHash = hash_file('sha256', $sourcePath);
        $this->assertIsString($expectedHash);
        rewind($stream);

        try {
            $prepared = app(StoreMessageAttachmentLocalFileAction::class)->prepareStream(
                $attachment,
                $stream,
                $fileSizeBytes,
                'bin',
                onStorageProgress: static function () use (&$heartbeatCount): void {
                    $heartbeatCount++;
                },
            );

            $this->assertIsResource($stream);
        } finally {
            fclose($stream);
        }

        $storage = Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE);

        $this->assertSame(4, $heartbeatCount);
        $storage->assertExists($prepared->path);
        $this->assertSame($fileSizeBytes, $prepared->sizeBytes);
        $this->assertSame(
            $expectedHash,
            hash_file('sha256', $storage->path($prepared->path)),
        );
    }

    public function test_prepared_claim_is_authoritative_when_expected_claim_argument_is_omitted(): void
    {
        foreach (['stale-claim', null] as $preparedClaimToken) {
            $attachment = $this->createAttachment([
                'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING,
                'media_download_generation' => 3,
                'media_download_claim_token' => 'current-claim',
                'media_download_attempt_deadline_at' => now()->addHour(),
            ]);
            $prepared = new PreparedMessageAttachmentFile(
                attachmentId: (int) $attachment->id,
                messageId: (int) $attachment->message_id,
                generation: 3,
                claimToken: $preparedClaimToken,
                disk: MessageAttachment::storageDiskName(),
                path: "message-attachments/{$attachment->message_id}/{$attachment->id}.candidate.pdf",
                sizeBytes: 7,
            );

            try {
                DB::transaction(function () use ($attachment, $prepared): void {
                    $locked = MessageAttachment::query()
                        ->whereKey($attachment->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    app(StoreMessageAttachmentLocalFileAction::class)->adoptPreparedFile(
                        $locked,
                        $prepared,
                    );
                });

                $this->fail('Expected a stale or unclaimed prepared file to be rejected against the active claim.');
            } catch (MediaDownloadLeaseLostException) {
                $this->assertTrue(true);
            }

            $attachment->refresh();

            $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING, $attachment->download_status);
            $this->assertSame('current-claim', $attachment->media_download_claim_token);
            $this->assertNull($attachment->local_path);
        }
    }

    public function test_prepare_copy_with_progress_uses_real_flysystem_adapter_contract(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);

        $contents = 'private-pdf-bytes';
        $sourcePath = 'message-attachments/source.pdf';
        $attachment = $this->createAttachment([
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING,
            'extension' => 'pdf',
            'mime_type' => 'application/pdf',
        ]);
        $heartbeatCount = 0;

        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->put($sourcePath, $contents);

        $prepared = app(StoreMessageAttachmentLocalFileAction::class)->prepareCopy(
            $attachment,
            $sourcePath,
            strlen($contents),
            'pdf',
            onStorageProgress: static function () use (&$heartbeatCount): void {
                $heartbeatCount++;
            },
        );

        $this->assertSame(3, $heartbeatCount);
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->assertExists($prepared->path);
        $this->assertSame(
            $contents,
            Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->get($prepared->path),
        );
    }

    public function test_prepare_copy_heartbeats_between_large_local_file_chunks(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);

        $sourcePath = 'message-attachments/large-source.bin';
        $fileSizeBytes = (64 * 1024 * 1024) + 1;
        $attachment = $this->createAttachment([
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING,
            'extension' => 'bin',
            'mime_type' => 'application/octet-stream',
        ]);
        $heartbeatCount = 0;
        $storage = Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE);

        $this->assertTrue($storage->makeDirectory(dirname($sourcePath)));
        $source = fopen($storage->path($sourcePath), 'w+b');
        $this->assertIsResource($source);
        $this->assertSame(0, fseek($source, $fileSizeBytes - 1));
        $this->assertSame(1, fwrite($source, 'a'));
        fclose($source);

        $prepared = app(StoreMessageAttachmentLocalFileAction::class)->prepareCopy(
            $attachment,
            $sourcePath,
            $fileSizeBytes,
            'bin',
            onStorageProgress: static function () use (&$heartbeatCount): void {
                $heartbeatCount++;
            },
        );

        $this->assertSame(4, $heartbeatCount);
        $storage->assertExists($prepared->path);
        $this->assertSame(
            hash_file('sha256', $storage->path($sourcePath)),
            hash_file('sha256', $storage->path($prepared->path)),
        );
    }

    public function test_repeated_unclaimed_store_removes_the_previous_object(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);

        $attachment = $this->createAttachment([
            'extension' => 'pdf',
            'mime_type' => 'application/pdf',
        ]);
        $action = app(StoreMessageAttachmentLocalFileAction::class);
        $first = $action->handle($attachment, 'first-version', 'pdf');
        $firstPath = (string) $first->local_path;
        $second = $action->handle($first, 'second-version', 'pdf');

        $this->assertNotSame($firstPath, $second->local_path);
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->assertMissing($firstPath);
        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->assertExists((string) $second->local_path);
        $this->assertSame(
            'second-version',
            Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)->get((string) $second->local_path),
        );
    }

    public function test_claimed_store_rejects_an_expired_lease_at_final_publish(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);

        $attachment = $this->createAttachment([
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING,
            'extension' => 'pdf',
            'mime_type' => 'application/pdf',
            'media_download_claim_token' => 'expired-claim',
            'media_download_attempt_deadline_at' => now()->subSecond(),
        ]);
        $stream = fopen('php://temp', 'w+b');

        $this->assertIsResource($stream);
        fwrite($stream, 'private-pdf-bytes');
        rewind($stream);

        try {
            app(StoreMessageAttachmentLocalFileAction::class)->handleStream(
                $attachment,
                $stream,
                strlen('private-pdf-bytes'),
                'pdf',
                expectedClaimToken: 'expired-claim',
            );

            $this->fail('Expected the expired media lease to be rejected.');
        } catch (MediaDownloadLeaseLostException) {
            $this->assertTrue(true);
        } finally {
            fclose($stream);
        }

        $attachment->refresh();

        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING, $attachment->download_status);
        $this->assertNull($attachment->local_path);
    }

    public function test_claimed_stable_paths_are_isolated_by_generation_and_claim(): void
    {
        $attachment = $this->createAttachment([
            'media_download_generation' => 2,
        ]);
        $action = app(StoreMessageAttachmentLocalFileAction::class);

        $firstPath = $action->buildClaimedPath($attachment, 'pdf', 'claim-one');
        $secondPath = $action->buildClaimedPath($attachment, 'pdf', 'claim-two');

        $this->assertNotSame($firstPath, $secondPath);
        $this->assertStringContainsString('.g2.claim-one.pdf', $firstPath);
        $this->assertStringContainsString('.g2.claim-two.pdf', $secondPath);
    }

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

        $this->assertStringStartsWith(
            'message-attachments/'.$storedAttachment->message_id.'/'.$storedAttachment->id.'.g1.unclaimed.commit.',
            (string) $storedAttachment->local_path,
        );
        $this->assertStringEndsWith('.pdf', (string) $storedAttachment->local_path);
        $this->assertTrue($storedAttachment->isLocallyDownloadable());

        $response = $this->actingAs($admin)
            ->get(route('admin.message-attachments.download', $storedAttachment));

        $response->assertOk();
        $this->assertSame('private-pdf-bytes', $response->streamedContent());
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString(
            'final.pdf',
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
        $this->assertBinaryFileResponseContent($response, 'private-jpeg-bytes');
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
        $this->assertBinaryFileResponseContent($response, 'private-video-bytes');
        $this->assertSame('video/mp4', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('inline', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_preview_uses_local_path_extension_for_downloaded_video_when_metadata_is_missing(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);

        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
            'role' => User::ROLE_ADMIN,
        ]);
        $attachment = $this->createAttachment([
            'media_kind' => MessageAttachment::MEDIA_KIND_VIDEO,
            'mime_type' => null,
            'extension' => null,
            'original_filename' => null,
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED,
            'local_disk' => MessageAttachment::LOCAL_DISK_PRIVATE,
            'local_path' => MessageAttachment::LOCAL_PATH_PREFIX.'/legacy-max/video-93.mp4',
        ]);

        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)
            ->put((string) $attachment->local_path, 'private-video-bytes');

        $this->assertTrue($attachment->isInlinePreviewable());
        $this->assertSame(MessageAttachment::PREVIEW_KIND_VIDEO, $attachment->previewKind());
        $this->assertSame('video/mp4', $attachment->previewMimeType());

        $response = $this->actingAs($admin)
            ->get(route('admin.message-attachments.preview', $attachment));

        $response->assertOk();
        $this->assertBinaryFileResponseContent($response, 'private-video-bytes');
        $this->assertSame('video/mp4', $response->headers->get('Content-Type'));
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
        $this->assertBinaryFileResponseContent($response, 'private-audio-bytes');
        $this->assertSame('audio/mpeg', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('inline', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_preview_supports_byte_range_requests_for_browser_video_playback(): void
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
                'mime_type' => null,
                'extension' => 'mp4',
                'original_filename' => 'clip.mp4',
            ]), '0123456789', 'mp4');

        $this->assertTrue($attachment->isInlinePreviewable());
        $this->assertSame('video/mp4', $attachment->previewMimeType());

        $response = $this->actingAs($admin)
            ->withHeader('Range', 'bytes=0-0')
            ->get(route('admin.message-attachments.preview', $attachment));

        $response->assertStatus(Response::HTTP_PARTIAL_CONTENT);
        $this->assertSame('video/mp4', $response->headers->get('Content-Type'));
        $this->assertSame('bytes', $response->headers->get('Accept-Ranges'));
        $this->assertSame('bytes 0-0/10', $response->headers->get('Content-Range'));
        $this->assertSame('1', $response->headers->get('Content-Length'));
    }

    public function test_preview_uses_local_path_extension_for_downloaded_audio_when_metadata_is_missing(): void
    {
        Storage::fake(MessageAttachment::LOCAL_DISK_PRIVATE);

        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
            'role' => User::ROLE_ADMIN,
        ]);
        $attachment = $this->createAttachment([
            'media_kind' => MessageAttachment::MEDIA_KIND_AUDIO,
            'mime_type' => null,
            'extension' => null,
            'original_filename' => null,
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED,
            'local_disk' => MessageAttachment::LOCAL_DISK_PRIVATE,
            'local_path' => MessageAttachment::LOCAL_PATH_PREFIX.'/legacy-max/audio-94.mp3',
        ]);

        Storage::disk(MessageAttachment::LOCAL_DISK_PRIVATE)
            ->put((string) $attachment->local_path, 'private-audio-bytes');

        $this->assertTrue($attachment->isInlinePreviewable());
        $this->assertSame(MessageAttachment::PREVIEW_KIND_AUDIO, $attachment->previewKind());
        $this->assertSame('audio/mpeg', $attachment->previewMimeType());

        $response = $this->actingAs($admin)
            ->get(route('admin.message-attachments.preview', $attachment));

        $response->assertOk();
        $this->assertBinaryFileResponseContent($response, 'private-audio-bytes');
        $this->assertSame('audio/mpeg', $response->headers->get('Content-Type'));
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

    private function assertBinaryFileResponseContent(mixed $response, string $expectedContent): void
    {
        $baseResponse = $response->baseResponse;

        $this->assertInstanceOf(BinaryFileResponse::class, $baseResponse);
        $this->assertSame($expectedContent, file_get_contents($baseResponse->getFile()->getPathname()));
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
