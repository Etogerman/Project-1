<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Dialog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ContactNameBackfillMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_adds_display_name_to_contact_identities(): void
    {
        $this->assertTrue(Schema::hasColumn('contact_identities', 'display_name'));
    }

    public function test_backfill_migration_sets_first_name_source_and_identity_display_name(): void
    {
        $channel = Channel::factory()->create();

        $confirmedContact = Contact::factory()->create([
            'name' => null,
            'first_name' => 'Герман',
            'first_name_source' => null,
        ]);

        $legacyNameContact = Contact::factory()->create([
            'name' => 'Имя из профиля',
            'first_name' => null,
            'first_name_source' => null,
        ]);

        $legacyIdentity = ContactIdentity::factory()->create([
            'contact_id' => $legacyNameContact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'legacy-user',
            'display_name' => null,
        ]);

        Dialog::factory()->create([
            'contact_id' => $legacyNameContact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $legacyIdentity->id,
            'last_message_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_04_12_130100_backfill_contact_name_sources_and_identity_display_names.php');
        $migration->up();

        $this->assertSame(
            Contact::FIRST_NAME_SOURCE_CONTACT_CONFIRMED,
            $confirmedContact->fresh()->first_name_source,
        );

        $legacyNameContact->refresh();
        $legacyIdentity->refresh();

        $this->assertSame('Имя из профиля', $legacyNameContact->first_name);
        $this->assertSame(Contact::FIRST_NAME_SOURCE_AUTO, $legacyNameContact->first_name_source);
        $this->assertSame('Имя из профиля', $legacyIdentity->display_name);
    }
}
