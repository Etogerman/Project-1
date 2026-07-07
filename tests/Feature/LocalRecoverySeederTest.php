<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\MessageAttachment;
use Database\Seeders\LocalRecoverySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LocalRecoverySeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_recovery_seeder_creates_demo_dataset(): void
    {
        Storage::fake('local');

        $this->seed(LocalRecoverySeeder::class);

        $this->assertDatabaseHas('channels', [
            'name' => 'Local Demo Telegram Bot',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
        ]);
        $this->assertDatabaseHas('channels', [
            'name' => 'Local Demo Telegram Account',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_ACCOUNT,
        ]);
        $this->assertDatabaseHas('channels', [
            'name' => 'Local Demo MAX Bot',
            'platform' => Channel::PLATFORM_MAX,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
        ]);

        $this->assertDatabaseHas('auto_reply_rules', [
            'keyword' => 'привет',
            'reply_text' => 'Здравствуйте! Чем можем помочь?',
        ]);
        $this->assertDatabaseHas('contacts', [
            'name' => 'Local Demo: Елена Смирнова',
            'first_name' => 'Елена',
        ]);
        $this->assertDatabaseHas('contacts', [
            'name' => 'Local Demo: Иван Петров',
            'first_name' => 'Иван',
        ]);
        $this->assertDatabaseHas('contacts', [
            'name' => 'Local Demo: Мария Волкова',
            'first_name' => 'Мария',
        ]);
        $this->assertDatabaseHas('messages', [
            'provider_event_key' => 'local-demo:tga:2001:inbound:document',
            'text' => 'Файл во вложении.',
        ]);
        $this->assertDatabaseHas('message_attachments', [
            'provider' => 'local_recovery',
            'provider_event_key' => 'local-demo:tga:2001:inbound:document',
            'provider_attachment_key' => 'document:0',
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED,
            'local_path' => 'message-attachments/local-recovery/arrival-checklist.txt',
        ]);

        Storage::disk('local')->assertExists('message-attachments/local-recovery/arrival-checklist.txt');
    }

    public function test_local_recovery_seeder_does_not_overwrite_existing_contacts_with_plain_names(): void
    {
        $contact = Contact::factory()->create([
            'name' => 'Елена Смирнова',
            'first_name' => 'Не менять',
            'city' => 'Тула',
        ]);

        $this->seed(LocalRecoverySeeder::class);

        $contact->refresh();

        $this->assertSame('Елена Смирнова', $contact->name);
        $this->assertSame('Не менять', $contact->first_name);
        $this->assertSame('Тула', $contact->city);
        $this->assertDatabaseHas('contacts', [
            'name' => 'Local Demo: Елена Смирнова',
            'first_name' => 'Елена',
        ]);
    }

    public function test_local_recovery_seeder_does_not_run_outside_local_or_testing(): void
    {
        $originalEnvironment = $this->app['env'];
        $this->app['env'] = 'production';

        try {
            (new LocalRecoverySeeder)->run();
        } finally {
            $this->app['env'] = $originalEnvironment;
        }

        $this->assertDatabaseMissing('channels', [
            'name' => 'Local Demo Telegram Bot',
        ]);
    }
}
