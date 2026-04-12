<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Dialog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactDialogIdentityDisplayNameRepairMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_repair_migration_fills_empty_dialog_identity_from_matching_platform_user_source(): void
    {
        $contact = Contact::factory()->create([
            'name' => null,
        ]);
        $telegramChannelA = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $telegramChannelB = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $maxChannel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
        ]);

        $telegramSourceIdentity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $telegramChannelA->id,
            'platform' => $telegramChannelA->platform,
            'external_user_id' => 'telegram-user-55',
            'display_name' => 'Abrikosov German',
        ]);
        $telegramTargetIdentity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $telegramChannelB->id,
            'platform' => $telegramChannelB->platform,
            'external_user_id' => 'telegram-user-55',
            'display_name' => null,
        ]);
        $maxIdentity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $maxChannel->id,
            'platform' => $maxChannel->platform,
            'external_user_id' => 'max-user-55',
            'display_name' => 'Герман Абрикосов',
        ]);

        Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $telegramChannelA->id,
            'current_contact_identity_id' => $telegramSourceIdentity->id,
            'last_message_at' => now()->subMinute(),
        ]);
        Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $telegramChannelB->id,
            'current_contact_identity_id' => $telegramTargetIdentity->id,
            'last_message_at' => now(),
        ]);
        Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $maxChannel->id,
            'current_contact_identity_id' => $maxIdentity->id,
            'last_message_at' => now()->subMinutes(2),
        ]);

        $this->runRepairMigration();

        $telegramTargetIdentity->refresh();
        $telegramSourceIdentity->refresh();
        $maxIdentity->refresh();

        $this->assertSame('Abrikosov German', $telegramTargetIdentity->display_name);
        $this->assertSame('Abrikosov German', $telegramSourceIdentity->display_name);
        $this->assertSame('Герман Абрикосов', $maxIdentity->display_name);
    }

    public function test_repair_migration_falls_back_to_legacy_contact_name_for_empty_dialog_identity(): void
    {
        $contact = Contact::factory()->create([
            'name' => 'Имя из legacy профиля',
        ]);
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $dialogIdentity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'legacy-user-55',
            'display_name' => null,
        ]);

        Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $dialogIdentity->id,
            'last_message_at' => now(),
        ]);

        $this->runRepairMigration();

        $dialogIdentity->refresh();

        $this->assertSame('Имя из legacy профиля', $dialogIdentity->display_name);
    }

    public function test_repair_migration_keeps_existing_dialog_display_name_and_leaves_non_dialog_identity_untouched(): void
    {
        $contact = Contact::factory()->create([
            'name' => 'Имя из legacy профиля',
        ]);
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $filledDialogIdentity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'filled-user-55',
            'display_name' => 'Уже заданное имя',
        ]);
        $emptyDialogIdentity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => Channel::factory()->create([
                'platform' => Channel::PLATFORM_TELEGRAM,
            ])->id,
            'platform' => Channel::PLATFORM_TELEGRAM,
            'external_user_id' => 'empty-user-55',
            'display_name' => null,
        ]);
        $nonDialogIdentity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => Channel::factory()->create([
                'platform' => Channel::PLATFORM_MAX,
            ])->id,
            'platform' => Channel::PLATFORM_MAX,
            'external_user_id' => 'non-dialog-user-55',
            'display_name' => null,
        ]);

        Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $filledDialogIdentity->channel_id,
            'current_contact_identity_id' => $filledDialogIdentity->id,
            'last_message_at' => now()->subMinute(),
        ]);
        Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $emptyDialogIdentity->channel_id,
            'current_contact_identity_id' => $emptyDialogIdentity->id,
            'last_message_at' => now(),
        ]);

        $this->runRepairMigration();

        $filledDialogIdentity->refresh();
        $emptyDialogIdentity->refresh();
        $nonDialogIdentity->refresh();

        $this->assertSame('Уже заданное имя', $filledDialogIdentity->display_name);
        $this->assertSame('Имя из legacy профиля', $emptyDialogIdentity->display_name);
        $this->assertNull($nonDialogIdentity->display_name);
    }

    public function test_repair_migration_is_idempotent(): void
    {
        $contact = Contact::factory()->create([
            'name' => 'Имя из legacy профиля',
        ]);
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $dialogIdentity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'idempotent-user-55',
            'display_name' => null,
        ]);

        Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $dialogIdentity->id,
            'last_message_at' => now(),
        ]);

        $this->runRepairMigration();
        $this->runRepairMigration();

        $this->assertSame('Имя из legacy профиля', $dialogIdentity->fresh()->display_name);
    }

    private function runRepairMigration(): void
    {
        $migration = require database_path('migrations/2026_04_12_150000_repair_dialog_identity_display_names.php');
        $migration->up();
    }
}
