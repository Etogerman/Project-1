<?php

namespace Tests\Feature;

use App\Jobs\SyncContactIdentityAvatarJob;
use App\Models\Channel;
use App\Models\ChannelActivityLog;
use App\Models\ContactIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SyncContactIdentityAvatarJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_contact_identity_avatar_job_downloads_telegram_avatar_to_public_disk(): void
    {
        Storage::fake('public');

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
                'telegram-avatar-binary',
                200,
                ['Content-Type' => 'image/jpeg'],
            ),
        ]);

        $job = new SyncContactIdentityAvatarJob($identity->id);

        app()->call([$job, 'handle']);

        $identity->refresh();

        $this->assertNotNull($identity->avatar_path);
        $this->assertNotNull($identity->avatar_updated_at);
        Storage::disk('public')->assertExists((string) $identity->avatar_path);
    }

    public function test_sync_contact_identity_avatar_job_downloads_max_avatar_to_public_disk(): void
    {
        Storage::fake('public');

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
            'https://cdn.max.example/avatar.png' => Http::response(
                'max-avatar-binary',
                200,
                ['Content-Type' => 'image/png'],
            ),
        ]);

        $job = new SyncContactIdentityAvatarJob($identity->id, 'https://cdn.max.example/avatar.png');

        app()->call([$job, 'handle']);

        $identity->refresh();

        $this->assertNotNull($identity->avatar_path);
        $this->assertStringEndsWith('.png', (string) $identity->avatar_path);
        Storage::disk('public')->assertExists((string) $identity->avatar_path);
    }

    public function test_sync_contact_identity_avatar_job_keeps_identity_without_avatar_when_telegram_chat_has_no_photo(): void
    {
        Storage::fake('public');

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
        Storage::fake('public');

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
        Storage::fake('public');

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
}
