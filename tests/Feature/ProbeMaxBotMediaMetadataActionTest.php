<?php

namespace Tests\Feature;

use App\Jobs\DownloadBotMessageAttachmentJob;
use App\Jobs\ProbeMaxBotMediaMetadataJob;
use App\Models\Channel;
use App\Models\ContactIdentity;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Services\Bots\ProbeMaxBotMediaMetadataAction;
use App\Services\Messages\InboundMediaDownloadPolicy;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class ProbeMaxBotMediaMetadataActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('bots.max.trusted_media_hosts', ['max.example']);
    }

    public function test_probe_stores_max_size_and_duration_without_downloading_file_body(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://platform-api.max.ru/videos/max-probe-video-token' => Http::response([
                'token' => 'max-probe-video-token',
                'urls' => [
                    'mp4_720' => 'https://max.example/private/probe-video.mp4?access_token=secret',
                ],
                'width' => 1280,
                'height' => 720,
                'duration' => 341000,
            ]),
            'https://max.example/private/probe-video.mp4*' => Http::response('', 200, [
                'Content-Type' => 'video/mp4',
                'Content-Length' => '25165824',
            ]),
        ]);
        $attachment = $this->createMaxVideoAttachment();

        $result = app(ProbeMaxBotMediaMetadataAction::class)->handle($attachment->id);

        $this->assertInstanceOf(MessageAttachment::class, $result);
        $this->assertSame(25_165_824, $result->file_size_bytes);
        $this->assertSame(341, data_get($result->provider_metadata, 'duration'));
        $this->assertSame(1280, data_get($result->provider_metadata, 'width'));
        $this->assertSame(720, data_get($result->provider_metadata, 'height'));
        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_AVAILABLE_ON_DEMAND, $result->download_status);
        $this->assertSame(InboundMediaDownloadPolicy::REASON_SIZE_ABOVE_AUTO_LIMIT, $result->safe_error_code);
        $this->assertNull($result->local_disk);
        $this->assertNull($result->local_path);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && $request->url() === 'https://platform-api.max.ru/videos/max-probe-video-token');
        Http::assertSent(fn ($request): bool => $request->method() === 'HEAD'
            && str_starts_with($request->url(), 'https://max.example/private/probe-video.mp4?'));
        Http::assertNotSent(fn ($request): bool => $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://max.example/private/probe-video.mp4?'));
    }

    public function test_probe_normalizes_one_second_legacy_provider_duration_before_storing_metadata(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://platform-api.max.ru/videos/max-probe-video-token' => Http::response([
                'urls' => [
                    'mp4_720' => 'https://max.example/private/probe-video.mp4?access_token=secret',
                ],
                'duration' => 1000,
            ]),
            'https://max.example/private/probe-video.mp4*' => Http::response('', 200, [
                'Content-Length' => '25165824',
            ]),
        ]);
        $attachment = $this->createMaxVideoAttachment();

        $result = app(ProbeMaxBotMediaMetadataAction::class)->handle($attachment->id);

        $this->assertInstanceOf(MessageAttachment::class, $result);
        $this->assertSame(1, data_get($result->provider_metadata, 'duration'));
        $this->assertNull($result->local_path);
    }

    public function test_probe_preserves_provider_duration_when_head_temporarily_fails(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://platform-api.max.ru/videos/max-probe-video-token' => Http::response([
                'urls' => [
                    'mp4_720' => 'https://max.example/private/probe-video.mp4?access_token=secret',
                ],
                'duration' => 341000,
            ]),
            'https://max.example/private/probe-video.mp4*' => Http::response('', 503),
        ]);
        $attachment = $this->createMaxVideoAttachment();

        try {
            app(ProbeMaxBotMediaMetadataAction::class)->handle($attachment->id);
            $this->fail('The temporary HEAD failure must stay retryable.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('HTTP 503', $exception->getMessage());
        }

        $attachment->refresh();

        $this->assertNull($attachment->file_size_bytes);
        $this->assertSame(341, data_get($attachment->provider_metadata, 'duration'));
        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_AVAILABLE_ON_DEMAND, $attachment->download_status);
        $this->assertSame(InboundMediaDownloadPolicy::REASON_SIZE_UNKNOWN, $attachment->safe_error_code);
    }

    public function test_probe_rejects_untrusted_media_url_before_http_request(): void
    {
        Http::fake();
        $message = $this->createMaxMessage([
            'message' => [
                'body' => [
                    'attachments' => [[
                        'type' => 'image',
                        'payload' => [
                            'photo_id' => 'max-untrusted-photo',
                            'url' => 'https://untrusted.example/private/photo.jpg',
                        ],
                    ]],
                ],
            ],
        ]);
        $attachment = MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $message->channel_id,
            'provider' => MessageAttachment::PROVIDER_MAX_BOT,
            'provider_event_key' => $message->provider_event_key,
            'provider_attachment_key' => 'max-untrusted-photo',
            'provider_file_reference' => 'max-untrusted-photo',
            'media_kind' => MessageAttachment::MEDIA_KIND_IMAGE,
            'file_size_bytes' => null,
            'provider_metadata' => [],
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_AVAILABLE_ON_DEMAND,
            'safe_error_code' => InboundMediaDownloadPolicy::REASON_SIZE_UNKNOWN,
            'local_disk' => null,
            'local_path' => null,
        ]);

        $this->expectException(InvalidArgumentException::class);

        try {
            app(ProbeMaxBotMediaMetadataAction::class)->handle($attachment->id);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_probe_job_dispatches_download_only_for_its_own_size_transition(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake([
            'https://platform-api.max.ru/videos/max-probe-video-token' => Http::response([
                'urls' => [
                    'mp4_720' => 'https://max.example/private/probe-video.mp4?access_token=secret',
                ],
                'duration' => 7000,
            ]),
            'https://max.example/private/probe-video.mp4*' => Http::response('', 200, [
                'Content-Length' => '1048576',
            ]),
        ]);
        $attachment = $this->createMaxVideoAttachment();
        $automaticJob = new ProbeMaxBotMediaMetadataJob($attachment->id);
        $backfillJob = new ProbeMaxBotMediaMetadataJob($attachment->id, allowAutomaticDownload: false);

        $this->assertSame(300, $automaticJob->uniqueFor);
        $this->assertNotSame($automaticJob->uniqueId(), $backfillJob->uniqueId());
        MessageAttachment::factory()->create([
            'message_id' => $attachment->message_id,
            'channel_id' => $attachment->channel_id,
            'provider' => MessageAttachment::PROVIDER_MAX_BOT,
            'provider_event_key' => $attachment->provider_event_key,
            'provider_attachment_key' => 'token:already-pending',
            'provider_file_reference' => 'token:already-pending',
            'media_kind' => MessageAttachment::MEDIA_KIND_IMAGE,
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD,
            'local_disk' => null,
            'local_path' => null,
        ]);

        $this->runProbeJob($attachment->id);
        $this->runProbeJob($attachment->id);

        $attachment->refresh();

        $this->assertSame(1_048_576, $attachment->file_size_bytes);
        $this->assertSame(7, data_get($attachment->provider_metadata, 'duration'));
        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD, $attachment->download_status);
        $this->assertNull($attachment->safe_error_code);
        Queue::assertPushed(DownloadBotMessageAttachmentJob::class, function (DownloadBotMessageAttachmentJob $job) use ($attachment): bool {
            return $job->attachmentId === $attachment->id
                && $job->manual === false;
        });
        Queue::assertPushed(DownloadBotMessageAttachmentJob::class, 1);
    }

    public function test_backfill_selects_duration_only_gaps_and_rejects_invalid_attachment_filter(): void
    {
        Queue::fake();
        $attachment = $this->createMaxVideoAttachment();
        $attachment->forceFill([
            'file_size_bytes' => 25_165_824,
            'provider_metadata' => [],
            'safe_error_code' => InboundMediaDownloadPolicy::REASON_SIZE_ABOVE_AUTO_LIMIT,
        ])->save();

        $this->artisan('bot-media:probe-max-metadata', [
            '--force' => true,
            '--attachment' => [$attachment->id],
        ])->assertSuccessful();

        Queue::assertPushed(ProbeMaxBotMediaMetadataJob::class, function (ProbeMaxBotMediaMetadataJob $job) use ($attachment): bool {
            return $job->attachmentId === $attachment->id
                && $job->allowAutomaticDownload === false;
        });

        Queue::fake();

        $this->artisan('bot-media:probe-max-metadata', [
            '--force' => true,
            '--attachment' => ['1.9'],
        ])->assertExitCode(Command::INVALID);

        Queue::assertNothingPushed();
    }

    private function runProbeJob(int $attachmentId): void
    {
        (new ProbeMaxBotMediaMetadataJob($attachmentId))->handle(
            app(ProbeMaxBotMediaMetadataAction::class),
        );
    }

    private function createMaxVideoAttachment(): MessageAttachment
    {
        $message = $this->createMaxMessage([
            'message' => [
                'body' => [
                    'attachments' => [[
                        'type' => 'video',
                        'payload' => [
                            'token' => 'max-probe-video-token',
                            'url' => 'https://max.example/private/webhook-video.mp4?access_token=webhook-secret',
                        ],
                    ]],
                ],
            ],
        ]);

        return MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $message->channel_id,
            'provider' => MessageAttachment::PROVIDER_MAX_BOT,
            'provider_event_key' => $message->provider_event_key,
            'provider_attachment_key' => 'token:'.sha1('max-probe-video-token'),
            'provider_file_reference' => 'token:'.sha1('max-probe-video-token'),
            'media_kind' => MessageAttachment::MEDIA_KIND_VIDEO,
            'file_size_bytes' => null,
            'provider_metadata' => [],
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_AVAILABLE_ON_DEMAND,
            'safe_error_code' => InboundMediaDownloadPolicy::REASON_SIZE_UNKNOWN,
            'local_disk' => null,
            'local_path' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $rawPayload
     */
    private function createMaxMessage(array $rawPayload): Message
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'inbound_media_auto_download_max_bytes' => 20 * 1024 * 1024,
            'inbound_media_on_demand_enabled' => true,
            'credentials' => [
                'token' => 'max-token',
            ],
        ]);
        $identity = ContactIdentity::factory()->create([
            'channel_id' => $channel->id,
            'platform' => Channel::PLATFORM_MAX,
        ]);

        return Message::factory()->create([
            'contact_id' => $identity->contact_id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'provider_event_key' => 'max-probe-message-1',
            'external_message_id' => 'max-probe-message-1',
            'raw_payload' => $rawPayload,
        ]);
    }
}
