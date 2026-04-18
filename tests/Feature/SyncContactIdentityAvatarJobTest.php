<?php

namespace Tests\Feature;

use App\Data\Bots\DownloadedAvatarData;
use App\Jobs\SyncContactIdentityAvatarJob;
use App\Models\Channel;
use App\Models\ChannelActivityLog;
use App\Models\ContactIdentity;
use App\Services\Bots\ContactIdentityAvatarStorage;
use App\Services\Bots\StoreContactIdentityAvatarAction;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SyncContactIdentityAvatarJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_contact_identity_avatar_job_downloads_telegram_avatar_to_contact_avatars_disk(): void
    {
        $this->fakeAvatarDisks();
        $telegramAvatar = $this->tinyJpegAvatar();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => ['token' => 'telegram-token'],
        ]);
        $identity = ContactIdentity::factory()->create([
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '228532008',
        ]);

        Http::fake([
            'https://api.telegram.org/bottelegram-token/getChat*' => Http::response([
                'ok' => true,
                'result' => [
                    'photo' => [
                        'big_file_id' => 'big-photo-file',
                    ],
                ],
            ]),
            'https://api.telegram.org/bottelegram-token/getFile*' => Http::response([
                'ok' => true,
                'result' => [
                    'file_path' => 'photos/avatar-big.jpg',
                ],
            ]),
            'https://api.telegram.org/file/bottelegram-token/photos/avatar-big.jpg' => Http::response(
                $telegramAvatar,
                200,
                ['Content-Type' => 'image/jpeg'],
            ),
        ]);

        $job = new SyncContactIdentityAvatarJob($identity->id);

        app()->call([$job, 'handle']);

        $identity->refresh();

        $this->assertNotNull($identity->avatar_path);
        $this->assertNotNull($identity->avatar_updated_at);
        $this->assertStringEndsWith('.jpg', (string) $identity->avatar_path);
        Storage::disk('contact_avatars')->assertExists((string) $identity->avatar_path);
    }

    public function test_sync_contact_identity_avatar_job_downloads_max_avatar_to_contact_avatars_disk(): void
    {
        $this->fakeAvatarDisks();
        $maxAvatar = $this->tinyPngAvatar();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => ['token' => 'max-token'],
        ]);
        $identity = ContactIdentity::factory()->create([
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '228532008',
        ]);

        Http::fake([
            'https://cdn.max.ru/avatar.php' => Http::response(
                $maxAvatar,
                200,
                ['Content-Type' => 'image/png'],
            ),
        ]);

        $job = new SyncContactIdentityAvatarJob($identity->id, 'https://cdn.max.ru/avatar.php');

        app()->call([$job, 'handle']);

        $identity->refresh();

        $this->assertNotNull($identity->avatar_path);
        $this->assertStringEndsWith('.png', (string) $identity->avatar_path);
        $this->assertStringEndsWith('.png', basename((string) $identity->avatar_path));
        Storage::disk('contact_avatars')->assertExists((string) $identity->avatar_path);
    }

    public function test_sync_contact_identity_avatar_job_fetches_max_avatar_via_chats_api_when_direct_url_is_missing(): void
    {
        $this->fakeAvatarDisks();
        $maxAvatar = $this->tinyPngAvatar();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => ['token' => 'max-token'],
        ]);
        $identity = ContactIdentity::factory()->create([
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '228532008',
        ]);

        Http::fake([
            'https://platform-api.max.ru/chats/65238156' => Http::response([
                'dialog_with_user' => [
                    'avatar_url' => 'https://cdn.max.ru/avatar-from-chat.png',
                    'full_avatar_url' => 'https://cdn.max.ru/avatar-from-chat-full.png',
                ],
            ]),
            'https://cdn.max.ru/avatar-from-chat-full.png' => Http::response(
                $maxAvatar,
                200,
                ['Content-Type' => 'image/png'],
            ),
        ]);

        $job = new SyncContactIdentityAvatarJob($identity->id, null, '65238156');

        app()->call([$job, 'handle']);

        $identity->refresh();

        $this->assertNotNull($identity->avatar_path);
        $this->assertStringEndsWith('.png', (string) $identity->avatar_path);
        Storage::disk('contact_avatars')->assertExists((string) $identity->avatar_path);

        Http::assertSentCount(2);
    }

    public function test_sync_contact_identity_avatar_job_keeps_identity_without_avatar_when_max_chat_has_no_avatar_fields(): void
    {
        $this->fakeAvatarDisks();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => ['token' => 'max-token'],
        ]);
        $identity = ContactIdentity::factory()->create([
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '228532008',
        ]);

        Http::fake([
            'https://platform-api.max.ru/chats/65238156' => Http::response([
                'dialog_with_user' => [
                    'name' => 'MAX user',
                ],
            ]),
        ]);

        $job = new SyncContactIdentityAvatarJob($identity->id, null, '65238156');

        app()->call([$job, 'handle']);

        $identity->refresh();

        $this->assertNull($identity->avatar_path);
        $this->assertNull($identity->avatar_updated_at);
        $this->assertDatabaseMissing('channel_activity_logs', [
            'channel_id' => $channel->id,
            'event' => 'contact.avatar_sync_failed',
        ]);
    }

    public function test_sync_contact_identity_avatar_job_detects_telegram_avatar_type_from_contents_when_provider_returns_octet_stream(): void
    {
        $this->fakeAvatarDisks();
        $telegramAvatar = $this->tinyJpegAvatar();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => ['token' => 'telegram-token'],
        ]);
        $identity = ContactIdentity::factory()->create([
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '228532008',
        ]);

        Http::fake([
            'https://api.telegram.org/bottelegram-token/getChat*' => Http::response([
                'ok' => true,
                'result' => [
                    'photo' => [
                        'big_file_id' => 'big-photo-file',
                    ],
                ],
            ]),
            'https://api.telegram.org/bottelegram-token/getFile*' => Http::response([
                'ok' => true,
                'result' => [
                    'file_path' => 'photos/avatar-big.jpg',
                ],
            ]),
            'https://api.telegram.org/file/bottelegram-token/photos/avatar-big.jpg' => Http::response(
                $telegramAvatar,
                200,
                ['Content-Type' => 'application/octet-stream'],
            ),
        ]);

        $job = new SyncContactIdentityAvatarJob($identity->id);

        app()->call([$job, 'handle']);

        $identity->refresh();

        $this->assertNotNull($identity->avatar_path);
        $this->assertStringEndsWith('.jpg', (string) $identity->avatar_path);
        Storage::disk('contact_avatars')->assertExists((string) $identity->avatar_path);
    }

    public function test_store_contact_identity_avatar_action_falls_back_to_image_size_when_finfo_returns_generic_mime(): void
    {
        $this->fakeAvatarDisks();

        $identity = ContactIdentity::factory()->create();
        $avatar = new DownloadedAvatarData(
            contents: $this->tinyJpegAvatar(),
            contentType: 'application/octet-stream',
        );

        $action = new class extends StoreContactIdentityAvatarAction
        {
            protected function detectMimeTypeWithFileInfo(string $contents): ?string
            {
                return 'application/octet-stream';
            }

            protected function detectMimeTypeWithImageSize(string $contents): ?string
            {
                return 'image/jpeg';
            }
        };

        $action->handle($identity, $avatar);

        $identity->refresh();

        $this->assertNotNull($identity->avatar_path);
        $this->assertStringEndsWith('.jpg', (string) $identity->avatar_path);
        Storage::disk('contact_avatars')->assertExists((string) $identity->avatar_path);
    }

    public function test_sync_contact_identity_avatar_job_keeps_identity_without_avatar_when_telegram_chat_has_no_photo(): void
    {
        $this->fakeAvatarDisks();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => ['token' => 'telegram-token'],
        ]);
        $identity = ContactIdentity::factory()->create([
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '228532008',
        ]);

        Http::fake([
            'https://api.telegram.org/bottelegram-token/getChat*' => Http::response([
                'ok' => true,
                'result' => [],
            ]),
        ]);

        $job = new SyncContactIdentityAvatarJob($identity->id);

        app()->call([$job, 'handle']);

        $identity->refresh();

        $this->assertNull($identity->avatar_path);
        $this->assertNull($identity->avatar_updated_at);
    }

    public function test_sync_contact_identity_avatar_job_clears_stale_telegram_avatar_when_chat_has_no_photo(): void
    {
        $this->fakeAvatarDisks();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => ['token' => 'telegram-token'],
        ]);
        $identity = ContactIdentity::factory()->create([
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '228532008',
            'avatar_path' => 'contact-identities/temp/avatar/stale-avatar.jpg',
            'avatar_updated_at' => now(),
        ]);

        $staleAvatarPath = sprintf('contact-identities/%d/avatar/stale-avatar.jpg', $identity->id);

        $identity->forceFill([
            'avatar_path' => $staleAvatarPath,
        ])->save();

        Storage::disk('contact_avatars')->put($staleAvatarPath, 'stale-avatar');

        Http::fake([
            'https://api.telegram.org/bottelegram-token/getChat*' => Http::response([
                'ok' => true,
                'result' => [],
            ]),
        ]);

        $job = new SyncContactIdentityAvatarJob($identity->id);

        app()->call([$job, 'handle']);

        $identity->refresh();

        $this->assertNull($identity->avatar_path);
        $this->assertNull($identity->avatar_updated_at);
        Storage::disk('contact_avatars')->assertMissing($staleAvatarPath);
    }

    public function test_sync_contact_identity_avatar_job_clears_legacy_public_avatar_when_telegram_chat_has_no_photo(): void
    {
        $this->fakeAvatarDisks();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => ['token' => 'telegram-token'],
        ]);
        $identity = ContactIdentity::factory()->create([
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '228532008',
            'avatar_path' => 'contact-identities/temp/avatar/stale-avatar.jpg',
            'avatar_updated_at' => now(),
        ]);

        $staleAvatarPath = sprintf('contact-identities/%d/avatar/stale-avatar.jpg', $identity->id);

        $identity->forceFill([
            'avatar_path' => $staleAvatarPath,
        ])->save();

        Storage::disk('public')->put($staleAvatarPath, 'stale-avatar');

        Http::fake([
            'https://api.telegram.org/bottelegram-token/getChat*' => Http::response([
                'ok' => true,
                'result' => [],
            ]),
        ]);

        $job = new SyncContactIdentityAvatarJob($identity->id);

        app()->call([$job, 'handle']);

        $identity->refresh();

        $this->assertNull($identity->avatar_path);
        $this->assertNull($identity->avatar_updated_at);
        Storage::disk('public')->assertMissing($staleAvatarPath);
    }

    public function test_sync_contact_identity_avatar_job_logs_warning_when_provider_avatar_download_fails(): void
    {
        $this->fakeAvatarDisks();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => ['token' => 'max-token'],
        ]);
        $identity = ContactIdentity::factory()->create([
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '228532008',
        ]);

        Http::fake([
            'https://cdn.max.example/avatar-fail.png' => Http::response(['error' => 'denied'], 403),
        ]);

        $job = new SyncContactIdentityAvatarJob($identity->id, 'https://cdn.max.example/avatar-fail.png');

        app()->call([$job, 'handle']);

        $identity->refresh();

        $this->assertNull($identity->avatar_path);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'level' => ChannelActivityLog::LEVEL_WARNING,
            'event' => 'contact.avatar_sync_failed',
        ]);
    }

    public function test_sync_contact_identity_avatar_job_keeps_existing_avatar_when_contact_avatar_storage_write_fails(): void
    {
        $this->fakeAvatarDisks();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => ['token' => 'max-token'],
        ]);
        $identity = ContactIdentity::factory()->create([
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '228532008',
            'avatar_path' => 'contact-identities/temp/avatar/stale-avatar.jpg',
            'avatar_updated_at' => now(),
        ]);

        $originalAvatarPath = sprintf('contact-identities/%d/avatar/stale-avatar.jpg', $identity->id);
        $originalAvatarUpdatedAt = $identity->avatar_updated_at;

        $identity->forceFill([
            'avatar_path' => $originalAvatarPath,
        ])->save();

        Storage::disk('public')->put($originalAvatarPath, 'stale-avatar');

        $failingDisk = \Mockery::mock(FilesystemAdapter::class);
        $failingDisk->shouldReceive('exists')
            ->once()
            ->with(\Mockery::type('string'))
            ->andReturnFalse();
        $failingDisk->shouldReceive('put')
            ->once()
            ->with(\Mockery::type('string'), $this->tinyPngAvatar())
            ->andReturnFalse();
        $failingDisk->shouldReceive('delete')->never();

        $avatarStorage = \Mockery::mock(ContactIdentityAvatarStorage::class);
        $avatarStorage->shouldReceive('disk')->andReturn($failingDisk);

        app()->instance(ContactIdentityAvatarStorage::class, $avatarStorage);

        Http::fake([
            'https://cdn.max.ru/avatar.php' => Http::response(
                $this->tinyPngAvatar(),
                200,
                ['Content-Type' => 'image/png'],
            ),
        ]);

        $job = new SyncContactIdentityAvatarJob($identity->id, 'https://cdn.max.ru/avatar.php');

        app()->call([$job, 'handle']);

        $identity->refresh();

        $this->assertSame($originalAvatarPath, $identity->avatar_path);
        $this->assertTrue($identity->avatar_updated_at?->equalTo($originalAvatarUpdatedAt));
        Storage::disk('public')->assertExists($originalAvatarPath);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'level' => ChannelActivityLog::LEVEL_WARNING,
            'event' => 'contact.avatar_sync_failed',
        ]);
    }

    public function test_sync_contact_identity_avatar_job_rejects_untrusted_max_avatar_host_without_http_request(): void
    {
        $this->fakeAvatarDisks();
        Http::fake();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => ['token' => 'max-token'],
        ]);
        $identity = ContactIdentity::factory()->create([
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '228532008',
        ]);

        $job = new SyncContactIdentityAvatarJob($identity->id, 'https://evil.example/avatar.png');

        app()->call([$job, 'handle']);

        $identity->refresh();

        $this->assertNull($identity->avatar_path);
        Http::assertNothingSent();
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'level' => ChannelActivityLog::LEVEL_WARNING,
            'event' => 'contact.avatar_sync_failed',
        ]);
    }

    public function test_sync_contact_identity_avatar_job_keeps_stale_telegram_avatar_when_avatar_fetch_partially_fails(): void
    {
        $this->fakeAvatarDisks();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => ['token' => 'telegram-token'],
        ]);
        $identity = ContactIdentity::factory()->create([
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '228532008',
            'avatar_path' => 'contact-identities/temp/avatar/stale-avatar.jpg',
            'avatar_updated_at' => now(),
        ]);

        $staleAvatarPath = sprintf('contact-identities/%d/avatar/stale-avatar.jpg', $identity->id);

        $identity->forceFill([
            'avatar_path' => $staleAvatarPath,
        ])->save();

        Storage::disk('contact_avatars')->put($staleAvatarPath, 'stale-avatar');

        Http::fake([
            'https://api.telegram.org/bottelegram-token/getChat*' => Http::response([
                'ok' => true,
                'result' => [
                    'photo' => [
                        'big_file_id' => 'big-photo-file',
                    ],
                ],
            ]),
            'https://api.telegram.org/bottelegram-token/getFile*' => Http::response([
                'ok' => true,
                'result' => [],
            ]),
        ]);

        $job = new SyncContactIdentityAvatarJob($identity->id);

        app()->call([$job, 'handle']);

        $identity->refresh();

        $this->assertSame($staleAvatarPath, $identity->avatar_path);
        $this->assertNotNull($identity->avatar_updated_at);
        Storage::disk('contact_avatars')->assertExists($staleAvatarPath);
        $this->assertDatabaseHas('channel_activity_logs', [
            'channel_id' => $channel->id,
            'level' => ChannelActivityLog::LEVEL_WARNING,
            'event' => 'contact.avatar_sync_failed',
        ]);
    }

    protected function fakeAvatarDisks(): void
    {
        Storage::fake('contact_avatars');
        Storage::fake('public');
    }

    protected function tinyPngAvatar(): string
    {
        return (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aF9sAAAAASUVORK5CYII=',
            true,
        );
    }

    protected function tinyJpegAvatar(): string
    {
        return (string) base64_decode(
            '/9j/4AAQSkZJRgABAQAAAQABAAD/2wCEAAkGBxAQEBUQEBAVFRUVFRUVFRUVFRUVFRUVFRUWFhUVFRUYHSggGBolHRUVITEhJSkrLi4uFx8zODMsNygtLisBCgoKDg0OGhAQGi0fHyUtLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLf/AABEIAAEAAQMBEQACEQEDEQH/xAAbAAEAAwEBAQEAAAAAAAAAAAAABAUGAgMBB//EADYQAAIBAgQDBgQEBwAAAAAAAAECAAMRBBIhMQVBUQYiYXGBEzKRobHB0RQjQlJy8AcWJDNSYv/EABkBAAMBAQEAAAAAAAAAAAAAAAABAgMEBf/EACMRAAICAgICAgMBAAAAAAAAAAABAhEDIRIxBBNBUWEiMnH/2gAMAwEAAhEDEQA/APv4ooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooor/2Q==',
            true,
        );
    }
}
