<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('create index if not exists messages_dialog_chronology_idx on messages (dialog_id, (coalesce(received_at, created_at)) desc, id desc)');
        DB::statement('create index if not exists messages_contact_chronology_idx on messages (contact_id, (coalesce(received_at, created_at)) desc, id desc)');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('drop index if exists messages_dialog_chronology_idx');
        DB::statement('drop index if exists messages_contact_chronology_idx');
    }
};
