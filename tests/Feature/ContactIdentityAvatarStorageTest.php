<?php

namespace Tests\Feature;

use App\Services\Bots\ContactIdentityAvatarStorage;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ContactIdentityAvatarStorageTest extends TestCase
{
    public function test_avatar_storage_defaults_to_deployment_safe_contact_avatars_disk(): void
    {
        $storage = new ContactIdentityAvatarStorage;

        $this->assertSame('contact_avatars', $storage->diskName());
        $this->assertSame('s3', config('filesystems.disks.contact_avatars.driver'));
    }

    public function test_avatar_storage_uses_configured_disk_for_public_url(): void
    {
        config()->set('filesystems.contact_avatars_disk', 'avatar-test-disk');

        $disk = \Mockery::mock();
        $path = 'contact-identities/32/avatar/avatar.jpg';
        $expectedUrl = 'https://cdn.example.test/contact-identities/32/avatar/avatar.jpg';

        Storage::shouldReceive('disk')
            ->once()
            ->with('avatar-test-disk')
            ->andReturn($disk);

        $disk->shouldReceive('url')
            ->once()
            ->with($path)
            ->andReturn($expectedUrl);

        $storage = new ContactIdentityAvatarStorage;

        $this->assertSame($expectedUrl, $storage->url($path));
    }

    public function test_avatar_storage_exists_returns_false_for_blank_path_without_hitting_storage(): void
    {
        Storage::shouldReceive('disk')->never();

        $storage = new ContactIdentityAvatarStorage;

        $this->assertFalse($storage->exists(null));
        $this->assertFalse($storage->exists(''));
    }

    public function test_avatar_storage_exists_uses_configured_disk(): void
    {
        config()->set('filesystems.contact_avatars_disk', 'avatar-test-disk');

        $disk = \Mockery::mock();
        $path = 'contact-identities/32/avatar/avatar.jpg';

        Storage::shouldReceive('disk')
            ->once()
            ->with('avatar-test-disk')
            ->andReturn($disk);

        $disk->shouldReceive('exists')
            ->once()
            ->with($path)
            ->andReturnTrue();

        $storage = new ContactIdentityAvatarStorage;

        $this->assertTrue($storage->exists($path));
    }
}
