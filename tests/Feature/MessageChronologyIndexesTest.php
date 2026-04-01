<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MessageChronologyIndexesTest extends TestCase
{
    use RefreshDatabase;

    public function test_messages_table_has_dialog_and_contact_chronology_indexes(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Chronology index assertions are only applicable to PostgreSQL.');
        }

        $indexNames = collect(DB::select(
            "select indexname from pg_indexes where schemaname = current_schema() and tablename = 'messages'"
        ))
            ->map(fn (object $row): string => (string) $row->indexname)
            ->values()
            ->all();

        $this->assertContains('messages_dialog_chronology_idx', $indexNames);
        $this->assertContains('messages_contact_chronology_idx', $indexNames);
    }
}
