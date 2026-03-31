<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactMergeLog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactMergeLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_enforces_unique_secondary_contact_id(): void
    {
        $primary = Contact::factory()->create();
        $secondary = Contact::factory()->create();

        ContactMergeLog::factory()->create([
            'primary_contact_id' => $primary->id,
            'secondary_contact_id' => $secondary->id,
        ]);

        $this->expectException(QueryException::class);

        ContactMergeLog::factory()->create([
            'primary_contact_id' => Contact::factory()->create()->id,
            'secondary_contact_id' => $secondary->id,
        ]);
    }
}
