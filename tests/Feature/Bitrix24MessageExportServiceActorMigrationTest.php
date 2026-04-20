<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class Bitrix24MessageExportServiceActorMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_actor_migration_tolerates_partial_schema_and_repeat_runs(): void
    {
        $migration = require database_path('migrations/2026_04_20_120000_add_service_actor_fields_to_bitrix24_message_exports_table.php');

        $migration->down();

        Schema::table('bitrix24_message_exports', function (Blueprint $table): void {
            $table->string('transport_method', 64)->nullable();
        });

        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasColumns('bitrix24_message_exports', [
            'transport_method',
            'resolved_bitrix_chat_id',
            'bitrix_remote_message_id',
            'failure_code',
            'failure_uncertain',
        ]));
    }
}
