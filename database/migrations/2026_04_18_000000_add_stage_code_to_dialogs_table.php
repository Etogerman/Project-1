<?php

use App\Models\Dialog;
use App\Models\Contact;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dialogs', function (Blueprint $table): void {
            $table->string('stage_code', 64)
                ->default(Dialog::STAGE_NEW_DIALOG)
                ->after('current_contact_identity_id');

            $table->index('stage_code');
        });

        DB::table('dialogs')
            ->update([
                'stage_code' => Dialog::STAGE_NEW_DIALOG,
            ]);

        DB::statement(sprintf(
            "update dialogs
            set stage_code = '%s'
            where stage_code = '%s'
              and exists (
                    select 1
                    from contact_phone_numbers
                    where contact_phone_numbers.contact_id = dialogs.contact_id
                )",
            Dialog::STAGE_PHONE_RECEIVED,
            Dialog::STAGE_NEW_DIALOG,
        ));

        DB::statement(sprintf(
            "update dialogs
            set stage_code = '%s'
            from contacts
            where dialogs.contact_id = contacts.id
              and dialogs.stage_code = '%s'
              and exists (
                    select 1
                    from contact_phone_numbers
                    where contact_phone_numbers.contact_id = dialogs.contact_id
                )
              and (
                contacts.data_collection_status = '%s'
                or contacts.data_collection_completed_at is not null
              )",
            Dialog::STAGE_QUESTIONNAIRE_COMPLETED,
            Dialog::STAGE_PHONE_RECEIVED,
            Contact::DATA_COLLECTION_STATUS_COMPLETED,
        ));
    }

    public function down(): void
    {
        Schema::table('dialogs', function (Blueprint $table): void {
            $table->dropIndex(['stage_code']);
            $table->dropColumn('stage_code');
        });
    }
};
