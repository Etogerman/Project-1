<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactPhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CanonicalizeContactPhoneNumbersCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_runs_in_dry_run_mode_by_default_without_modifying_data(): void
    {
        $contact = Contact::factory()->create();
        $phoneNumber = ContactPhoneNumber::factory()->create([
            'contact_id' => $contact->id,
            'phone_raw' => '8 (999) 123-45-67',
            'phone_normalized' => '89991234567',
            'is_primary' => true,
        ]);

        $this->artisan('contacts:canonicalize-phone-numbers')
            ->expectsOutput('Phone canonicalization dry-run completed.')
            ->assertSuccessful();

        $this->assertSame('89991234567', $phoneNumber->fresh()->phone_normalized);
    }

    public function test_it_canonicalizes_numbers_when_apply_option_is_used(): void
    {
        $contact = Contact::factory()->create();
        $phoneNumber = ContactPhoneNumber::factory()->create([
            'contact_id' => $contact->id,
            'phone_raw' => '8 (999) 123-45-67',
            'phone_normalized' => '89991234567',
            'is_primary' => true,
        ]);

        $this->artisan('contacts:canonicalize-phone-numbers', ['--apply' => true])
            ->expectsOutput('Phone canonicalization completed.')
            ->assertSuccessful();

        $this->assertSame('+79991234567', $phoneNumber->fresh()->phone_normalized);
    }

    public function test_it_collapses_duplicates_inside_the_same_contact_while_preserving_primary(): void
    {
        $contact = Contact::factory()->create();

        $primary = ContactPhoneNumber::factory()->create([
            'contact_id' => $contact->id,
            'phone_raw' => '8 (999) 123-45-67',
            'phone_normalized' => '89991234567',
            'is_primary' => true,
        ]);
        ContactPhoneNumber::factory()->create([
            'contact_id' => $contact->id,
            'phone_raw' => '+7 999 123 45 67',
            'phone_normalized' => '+79991234567',
            'is_primary' => false,
        ]);

        $this->artisan('contacts:canonicalize-phone-numbers', ['--apply' => true])
            ->assertSuccessful();

        $this->assertDatabaseCount('contact_phone_numbers', 1);
        $this->assertSame('+79991234567', $primary->fresh()->phone_normalized);
        $this->assertTrue($primary->fresh()->is_primary);
    }

    public function test_it_reports_cross_contact_matches_without_merging_contacts(): void
    {
        $first = Contact::factory()->create();
        $second = Contact::factory()->create();

        ContactPhoneNumber::factory()->create([
            'contact_id' => $first->id,
            'phone_raw' => '8 (999) 123-45-67',
            'phone_normalized' => '89991234567',
        ]);
        ContactPhoneNumber::factory()->create([
            'contact_id' => $second->id,
            'phone_raw' => '+7 999 123 45 67',
            'phone_normalized' => '+79991234567',
        ]);

        $this->artisan('contacts:canonicalize-phone-numbers')
            ->expectsTable(
                ['Metric', 'Count'],
                [
                    ['processed', '2'],
                    ['already_canonical', '1'],
                    ['changed', '1'],
                    ['invalid', '0'],
                    ['same_contact_collisions', '0'],
                    ['cross_contact_matches', '1'],
                ],
            )
            ->assertSuccessful();

        $this->assertDatabaseCount('contacts', 2);
    }
}
