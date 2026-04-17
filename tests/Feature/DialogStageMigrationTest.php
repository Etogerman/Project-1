<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\ContactPhoneNumber;
use App\Models\Dialog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DialogStageMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_requires_current_contact_phone_before_completed_stage(): void
    {
        [$contact, $dialog] = $this->createLegacyDialog();

        $contact->forceFill([
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_COMPLETED,
            'data_collection_completed_at' => Carbon::parse('2026-04-18 10:00:00'),
        ])->save();

        $migration = require database_path('migrations/2026_04_18_000000_add_stage_code_to_dialogs_table.php');

        $migration->down();
        $migration->up();

        $this->assertSame(Dialog::STAGE_NEW_DIALOG, $dialog->fresh()->stage_code);
    }

    public function test_backfill_ignores_historical_dialog_confirmed_phone_without_current_contact_phone(): void
    {
        [$contact, $dialog] = $this->createLegacyDialog([
            'confirmed_phone_raw' => '+7 999 123 45 67',
            'confirmed_phone_normalized' => '+79991234567',
            'phone_confirmed_at' => Carbon::parse('2026-04-18 09:00:00'),
            'phone_confirmed_via' => Dialog::PHONE_CONFIRMED_VIA_PHONE_CAPTURE,
        ]);

        $contact->forceFill([
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_COMPLETED,
            'data_collection_completed_at' => Carbon::parse('2026-04-18 10:00:00'),
        ])->save();

        $migration = require database_path('migrations/2026_04_18_000000_add_stage_code_to_dialogs_table.php');

        $migration->down();
        $migration->up();

        $this->assertSame(Dialog::STAGE_NEW_DIALOG, $dialog->fresh()->stage_code);
    }

    public function test_backfill_keeps_completed_stage_when_contact_still_has_current_phone(): void
    {
        [$contact, $dialog] = $this->createLegacyDialog();

        ContactPhoneNumber::factory()->create([
            'contact_id' => $contact->id,
            'phone_raw' => '+7 999 123 45 67',
            'phone_normalized' => '79991234567',
            'source' => ContactPhoneNumber::SOURCE_MAX_CONTACT_SHARE,
            'is_primary' => true,
        ]);

        $contact->forceFill([
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_COMPLETED,
            'data_collection_completed_at' => Carbon::parse('2026-04-18 10:00:00'),
        ])->save();

        $migration = require database_path('migrations/2026_04_18_000000_add_stage_code_to_dialogs_table.php');

        $migration->down();
        $migration->up();

        $this->assertSame(Dialog::STAGE_QUESTIONNAIRE_COMPLETED, $dialog->fresh()->stage_code);
    }

    /**
     * @param  array<string, mixed>  $dialogOverrides
     * @return array{Contact, Dialog}
     */
    protected function createLegacyDialog(array $dialogOverrides = []): array
    {
        $channel = Channel::factory()->create();
        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
        ]);

        $dialog = Dialog::factory()->create(array_merge([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'stage_code' => Dialog::STAGE_NEW_DIALOG,
            'confirmed_phone_raw' => null,
            'confirmed_phone_normalized' => null,
            'phone_confirmed_at' => null,
            'phone_confirmed_via' => null,
        ], $dialogOverrides));

        return [$contact, $dialog];
    }
}
