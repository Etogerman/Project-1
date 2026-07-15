<?php

namespace Tests\Feature;

use App\Jobs\DownloadBotMessageAttachmentJob;
use App\Models\Channel;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Services\Bots\DownloadBotMessageAttachmentsAction;
use App\Services\Messages\InboundMediaAdmissionDeniedException;
use App\Services\Messages\InboundMediaAdmissionGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class InboundMediaAdmissionGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'inbound_media.admission.channel_max_active' => 2,
            'inbound_media.admission.identity_max_active' => 2,
            'inbound_media.admission.global_max_active' => 4,
            'inbound_media.admission.manual_to_automatic_ratio' => 3,
            'inbound_media.admission.retry_after_seconds' => 5,
        ]);
    }

    public function test_channel_limit_denies_a_third_active_download(): void
    {
        $channel = $this->createChannel('channel-limit');
        $this->createActiveAttachment($channel);
        $this->createActiveAttachment($channel);
        $candidate = $this->createPendingAttachment($channel);

        $this->assertSame(
            InboundMediaAdmissionGate::REASON_CHANNEL_CONCURRENCY,
            $this->denialReason($channel, false, $candidate),
        );
    }

    public function test_identity_limit_is_shared_by_channels_of_the_same_bot(): void
    {
        config(['inbound_media.admission.channel_max_active' => 10]);

        $first = $this->createChannel('shared-bot');
        $second = $this->createChannel('shared-bot');
        $third = $this->createChannel('shared-bot');
        $this->createActiveAttachment($first);
        $this->createActiveAttachment($second);
        $candidate = $this->createPendingAttachment($third);

        $this->assertSame(
            InboundMediaAdmissionGate::REASON_IDENTITY_CONCURRENCY,
            $this->denialReason($third, false, $candidate),
        );
    }

    public function test_global_limit_is_shared_by_different_identities(): void
    {
        config([
            'inbound_media.admission.channel_max_active' => 10,
            'inbound_media.admission.identity_max_active' => 10,
        ]);

        foreach (range(1, 4) as $index) {
            $this->createActiveAttachment($this->createChannel('global-'.$index));
        }

        $candidateChannel = $this->createChannel('global-candidate');
        $candidate = $this->createPendingAttachment($candidateChannel);

        $this->assertSame(
            InboundMediaAdmissionGate::REASON_GLOBAL_CONCURRENCY,
            $this->denialReason($candidateChannel, false, $candidate),
        );
    }

    public function test_stale_lease_does_not_consume_an_active_slot(): void
    {
        config([
            'inbound_media.admission.channel_max_active' => 1,
            'inbound_media.admission.identity_max_active' => 1,
            'inbound_media.admission.global_max_active' => 1,
        ]);

        $channel = $this->createChannel('stale-lease');
        $this->createActiveAttachment($channel, now()->subMinutes(5));
        $candidate = $this->createPendingAttachment($channel);

        DB::transaction(function () use ($channel, $candidate): void {
            $gate = app(InboundMediaAdmissionGate::class);
            $gate->lock();
            $gate->assertCanClaim($channel, false, (int) $candidate->id);
        });

        $this->assertTrue(true);
    }

    public function test_three_manual_claims_yield_to_a_due_automatic_candidate(): void
    {
        $channel = $this->createChannel('fairness');
        $automatic = $this->createPendingAttachment($channel);
        $manual = $this->createPendingAttachment($channel, manual: true);

        foreach (range(1, 3) as $_) {
            DB::transaction(function () use ($channel): void {
                $gate = app(InboundMediaAdmissionGate::class);
                $gate->lock();
                $gate->recordClaim($channel, true);
            });
        }

        $this->assertSame(
            InboundMediaAdmissionGate::REASON_QUEUE_FAIRNESS,
            $this->denialReason($channel, true, $manual),
        );

        DB::transaction(function () use ($channel, $automatic): void {
            $gate = app(InboundMediaAdmissionGate::class);
            $gate->lock();
            $gate->assertCanClaim($channel, false, (int) $automatic->id);
            $gate->recordClaim($channel, false);
        });

        DB::transaction(function () use ($channel, $manual): void {
            $gate = app(InboundMediaAdmissionGate::class);
            $gate->lock();
            $gate->assertCanClaim($channel, true, (int) $manual->id);
        });

        $this->assertTrue(true);
    }

    public function test_bot_download_is_requeued_when_admission_is_temporarily_full(): void
    {
        Queue::fake();
        config([
            'inbound_media.admission.channel_max_active' => 1,
            'inbound_media.admission.identity_max_active' => 10,
            'inbound_media.admission.global_max_active' => 10,
        ]);

        $channel = $this->createChannel('bot-requeue');
        $this->createActiveAttachment($channel);
        $pending = $this->createPendingAttachment($channel);

        app(DownloadBotMessageAttachmentsAction::class)->handle(
            $pending->message()->firstOrFail(),
            attachmentIds: [(int) $pending->id],
        );

        $this->assertSame(
            MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD,
            $pending->fresh()->download_status,
        );
        Queue::assertPushed(
            DownloadBotMessageAttachmentJob::class,
            fn (DownloadBotMessageAttachmentJob $job): bool => $job->attachmentId === (int) $pending->id
                && $job->manual === false
                && $job->delay !== null,
        );
    }

    private function createChannel(string $botExternalId): Channel
    {
        return Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'bot_external_id' => $botExternalId,
        ]);
    }

    private function createActiveAttachment(
        Channel $channel,
        mixed $heartbeatAt = null,
    ): MessageAttachment {
        $attachment = $this->createPendingAttachment($channel);
        $claimedAt = $heartbeatAt ?? now();

        $attachment->forceFill([
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING,
            'media_download_claim_token' => fake()->uuid(),
            'media_download_claimed_at' => $claimedAt,
            'media_download_heartbeat_at' => $claimedAt,
            'media_download_attempt_deadline_at' => now()->addHour(),
        ])->save();

        return $attachment;
    }

    private function createPendingAttachment(
        Channel $channel,
        bool $manual = false,
    ): MessageAttachment {
        $message = Message::factory()->create(['channel_id' => $channel->id]);

        return MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $channel->id,
            'provider' => MessageAttachment::PROVIDER_TELEGRAM_BOT,
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD,
            'manual_download_requested_at' => $manual ? now() : null,
        ]);
    }

    private function denialReason(
        Channel $channel,
        bool $manual,
        MessageAttachment $attachment,
    ): ?string {
        try {
            DB::transaction(function () use ($channel, $manual, $attachment): void {
                $gate = app(InboundMediaAdmissionGate::class);
                $gate->lock();
                $gate->assertCanClaim($channel, $manual, (int) $attachment->id);
            });
        } catch (InboundMediaAdmissionDeniedException $exception) {
            return $exception->reason;
        }

        return null;
    }
}
