<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\Dialog;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DialogStageMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_migration_recomputes_automatic_legacy_stages_and_keeps_manual_stages(): void
    {
        $channel = Channel::factory()->create();
        $phoneContact = Contact::factory()->create();
        $completedContact = Contact::factory()->create([
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_COMPLETED,
            'data_collection_completed_at' => now(),
        ]);
        $plainContact = Contact::factory()->create();
        $manualContact = Contact::factory()->create([
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_COMPLETED,
            'data_collection_completed_at' => now(),
        ]);
        $phoneDialog = Dialog::factory()->create([
            'contact_id' => $phoneContact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => null,
            'stage' => Dialog::STAGE_NEW_DIALOG,
            'phone_confirmed_at' => now(),
        ]);
        $completedDialog = Dialog::factory()->create([
            'contact_id' => $completedContact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => null,
            'stage' => Dialog::STAGE_NEW_DIALOG,
        ]);
        $staleAutomaticDialog = Dialog::factory()->create([
            'contact_id' => $plainContact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => null,
            'stage' => Dialog::STAGE_PHONE_RECEIVED,
            'phone_confirmed_at' => null,
        ]);
        $manualDialog = Dialog::factory()->create([
            'contact_id' => $manualContact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => null,
            'stage' => Dialog::STAGE_TRANSFERRED_TO_MPP,
            'phone_confirmed_at' => now(),
        ]);
        $migration = require dirname(__DIR__, 2)
            .'/database/migrations/2026_07_07_000001_create_dialog_stages_and_add_stage_id_to_dialogs.php';

        $migration->down();
        $migration->up();

        $this->assertDialogStageBackfilled($phoneDialog->id, Dialog::STAGE_PHONE_RECEIVED);
        $this->assertDialogStageBackfilled($completedDialog->id, Dialog::STAGE_QUESTIONNAIRE_COMPLETED);
        $this->assertDialogStageBackfilled($staleAutomaticDialog->id, Dialog::STAGE_NEW_DIALOG);
        $this->assertDialogStageBackfilled($manualDialog->id, Dialog::STAGE_TRANSFERRED_TO_MPP);
    }

    private function assertDialogStageBackfilled(int $dialogId, string $expectedStage): void
    {
        $dialog = DB::table('dialogs')
            ->where('id', $dialogId)
            ->first(['stage', 'stage_id']);
        $stageId = DB::table('dialog_stages')
            ->where('key', $expectedStage)
            ->value('id');

        $this->assertNotNull($dialog);
        $this->assertNotNull($stageId);
        $this->assertSame($expectedStage, (string) $dialog->stage);
        $this->assertSame((int) $stageId, (int) $dialog->stage_id);
    }
}
