<?php

namespace Tests\Unit;

use App\Jobs\DownloadBotMessageAttachmentJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Tests\TestCase;

class InboundMediaQueueConfigurationTest extends TestCase
{
    public function test_media_queue_isolated_retry_window_exceeds_job_timeout(): void
    {
        config()->set('inbound_media.queue.connection', 'inbound-media');
        config()->set('inbound_media.queue.name', 'inbound-media');

        $job = new DownloadBotMessageAttachmentJob(1);

        $this->assertSame('inbound-media', $job->connection);
        $this->assertSame('inbound-media', $job->queue);
        $this->assertGreaterThan(
            $job->timeout,
            (int) config('queue.connections.inbound-media.retry_after'),
            'Dedicated media queue must not release a running media job early.',
        );
        $this->assertLessThan($job->timeout, (int) config('queue.connections.database.retry_after'));
        $this->assertLessThan($job->timeout, (int) config('queue.connections.redis.retry_after'));

        $this->assertInstanceOf(ShouldBeUniqueUntilProcessing::class, $job);
        $this->assertGreaterThan($job->timeout, $job->uniqueFor);
        $this->assertSame('1:manual', $job->uniqueId());
        $this->assertSame('1:automatic', (new DownloadBotMessageAttachmentJob(1, manual: false))->uniqueId());
    }

    public function test_scheduler_dispatches_media_downloads_without_forced_network_io(): void
    {
        $commands = collect(app(Schedule::class)->events())
            ->pluck('command')
            ->filter(fn (mixed $command): bool => is_string($command));

        $this->assertTrue($commands->contains(
            fn (string $command): bool => str_contains(
                $command,
                'bot-media:download-pending-images --dispatch --limit=25',
            ),
        ));
        $this->assertFalse($commands->contains(
            fn (string $command): bool => str_contains(
                $command,
                'bot-media:download-pending-images --force',
            ),
        ));
    }
}
