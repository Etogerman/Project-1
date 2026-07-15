<?php

namespace Tests\Unit;

use App\Models\Channel;
use App\Models\MessageAttachment;
use App\Services\Messages\InboundMediaDownloadPolicy;
use Tests\TestCase;

class InboundMediaDownloadPolicyTest extends TestCase
{
    public function test_manual_download_uses_configured_application_hard_limit(): void
    {
        config()->set('inbound_media.manual_hard_limit_bytes', 1024);

        $channel = (new Channel)->forceFill([
            'inbound_media_on_demand_enabled' => true,
        ]);

        $attachment = (new MessageAttachment)->forceFill([
            'provider' => MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT,
            'media_kind' => MessageAttachment::MEDIA_KIND_VIDEO,
            'provider_file_reference' => 'provider-file-reference',
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_AVAILABLE_ON_DEMAND,
            'file_size_bytes' => 1025,
        ]);
        $attachment->setRelation('channel', $channel);

        $policy = app(InboundMediaDownloadPolicy::class);

        $this->assertSame(1024, $policy->manualRequestMaxBytes($attachment));
        $this->assertSame(
            ['visible' => false, 'allowed' => false, 'reason' => null],
            $policy->manualAvailability($attachment),
        );
    }
}
