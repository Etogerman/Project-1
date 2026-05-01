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
        $path = 'contact-identities/32/avatar/avatar.jpg';

        config()->set('filesystems.contact_avatars_disk', 'avatar-test-disk');
        Storage::fake('avatar-test-disk');

        $storage = new ContactIdentityAvatarStorage;

        $this->assertSame(Storage::disk('avatar-test-disk')->url($path), $storage->url($path));
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
        $path = 'contact-identities/32/avatar/avatar.jpg';

        config()->set('filesystems.contact_avatars_disk', 'avatar-test-disk');
        Storage::fake('avatar-test-disk');
        Storage::disk('avatar-test-disk')->put($path, 'avatar');

        $storage = new ContactIdentityAvatarStorage;

        $this->assertTrue($storage->exists($path));
    }
}
