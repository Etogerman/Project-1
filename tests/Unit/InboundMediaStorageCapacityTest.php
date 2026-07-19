<?php

namespace Tests\Unit;

use App\Services\Messages\InboundMediaStorageCapacity;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class InboundMediaStorageCapacityTest extends TestCase
{
    public function test_s3_storage_uses_logical_quotas_instead_of_host_disk_capacity(): void
    {
        config([
            'filesystems.message_attachments_disk' => 'capacity_s3',
            'filesystems.disks.capacity_s3' => [
                'driver' => 's3',
            ],
            'inbound_media.storage.minimum_free_bytes' => 10 * 1024 * 1024 * 1024,
            'inbound_media.storage.minimum_free_percent' => 10,
        ]);

        $this->assertSame(PHP_INT_MAX, app(InboundMediaStorageCapacity::class)->availableBytes());
    }

    public function test_unknown_storage_driver_remains_fail_closed(): void
    {
        config([
            'filesystems.message_attachments_disk' => 'capacity_unknown',
            'filesystems.disks.capacity_unknown' => [
                'driver' => 'unsupported',
            ],
        ]);

        $this->assertNull(app(InboundMediaStorageCapacity::class)->availableBytes());
    }

    public function test_local_storage_keeps_physical_capacity_check(): void
    {
        $root = storage_path('framework/testing/capacity-root-'.uniqid('', true));
        File::ensureDirectoryExists($root);

        config([
            'filesystems.message_attachments_disk' => 'capacity_local',
            'filesystems.disks.capacity_local' => [
                'driver' => 'local',
                'root' => $root,
            ],
            'inbound_media.storage.minimum_free_bytes' => 1,
            'inbound_media.storage.minimum_free_percent' => 0,
        ]);

        try {
            $availableBytes = app(InboundMediaStorageCapacity::class)->availableBytes();

            $this->assertIsInt($availableBytes);
            $this->assertNotSame(PHP_INT_MAX, $availableBytes);
        } finally {
            File::deleteDirectory($root);
        }
    }
}
