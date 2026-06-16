<?php

use App\Models\FieldDictionaryField;
use App\Services\Dialogs\SyncSystemDialogCardViewAction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('card_views')) {
            return;
        }

        FieldDictionaryField::syncSystemDefinitions();
        app(SyncSystemDialogCardViewAction::class)->handle();
    }

    public function down(): void
    {
        if (! Schema::hasTable('card_views')) {
            return;
        }

        DB::table('card_views')
            ->where('entity', 'dialog')
            ->whereIn('view_key', ['system_dialog_card', 'dialog_card_default'])
            ->delete();
    }
};
