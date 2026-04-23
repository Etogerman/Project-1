<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class Bitrix24MessageExportResolvedCrmBindingMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolved_crm_binding_migration_tolerates_partial_schema_and_repeat_runs(): void
    {
        $migration = require database_path('migrations/2026_04_21_120000_add_resolved_crm_binding_fields_to_bitrix24_message_exports_table.php');

        $migration->down();

        Schema::table('bitrix24_message_exports', function (Blueprint $table): void {
            $table->string('resolved_crm_entity_type', 32)->nullable();
        });

        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasColumns('bitrix24_message_exports', [
            'resolved_crm_entity_type',
            'resolved_crm_entity_id',
        ]));
    }
}
