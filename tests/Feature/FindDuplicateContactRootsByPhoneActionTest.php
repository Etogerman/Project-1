<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactPhoneNumber;
use App\Services\Contacts\FindDuplicateContactRootsByPhoneAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FindDuplicateContactRootsByPhoneActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_empty_result_when_no_matches_are_found(): void
    {
        $result = app(FindDuplicateContactRootsByPhoneAction::class)->handle('+7 999 123 45 67');

        $this->assertSame('+79991234567', $result->phoneNormalized);
        $this->assertFalse($result->hasMatches);
        $this->assertSame([], $result->matchedRootContactIds);
    }

    public function test_it_excludes_the_current_root_from_results(): void
    {
        $root = Contact::factory()->create();
        $merged = Contact::factory()->create([
            'merged_into_contact_id' => $root->id,
            'merged_at' => now(),
        ]);

        ContactPhoneNumber::factory()->create([
            'contact_id' => $root->id,
            'phone_raw' => '8 (999) 123-45-67',
            'phone_normalized' => '+79991234567',
        ]);
        ContactPhoneNumber::factory()->create([
            'contact_id' => $merged->id,
            'phone_raw' => '+7 999 123 45 67',
            'phone_normalized' => '+79991234567',
        ]);

        $result = app(FindDuplicateContactRootsByPhoneAction::class)->handle('+7 999 123 45 67', $merged);

        $this->assertSame($root->id, $result->currentRootContactId);
        $this->assertSame([], $result->matchedRootContactIds);
        $this->assertFalse($result->hasMatches);
    }

    public function test_it_returns_a_single_other_root(): void
    {
        $current = Contact::factory()->create();
        $otherRoot = Contact::factory()->create();

        ContactPhoneNumber::factory()->create([
            'contact_id' => $current->id,
            'phone_raw' => '+7 999 123 45 67',
            'phone_normalized' => '+79991234567',
        ]);
        ContactPhoneNumber::factory()->create([
            'contact_id' => $otherRoot->id,
            'phone_raw' => '8 (999) 123-45-67',
            'phone_normalized' => '+79991234567',
        ]);

        $result = app(FindDuplicateContactRootsByPhoneAction::class)->handle('79991234567', $current);

        $this->assertTrue($result->hasSingleOtherRoot);
        $this->assertSame([$otherRoot->id], $result->matchedRootContactIds);
    }

    public function test_it_returns_multiple_unique_roots_when_the_same_phone_exists_on_multiple_contacts(): void
    {
        $current = Contact::factory()->create();
        $firstRoot = Contact::factory()->create();
        $secondRoot = Contact::factory()->create();
        $mergedChild = Contact::factory()->create([
            'merged_into_contact_id' => $firstRoot->id,
            'merged_at' => now(),
        ]);

        foreach ([$current->id, $firstRoot->id, $secondRoot->id, $mergedChild->id] as $contactId) {
            ContactPhoneNumber::factory()->create([
                'contact_id' => $contactId,
                'phone_raw' => '+7 999 123 45 67',
                'phone_normalized' => '+79991234567',
            ]);
        }

        $result = app(FindDuplicateContactRootsByPhoneAction::class)->handle('+7 999 123 45 67', $current->id);

        $this->assertTrue($result->hasMultipleOtherRoots);
        $this->assertSame([$firstRoot->id, $secondRoot->id], $result->matchedRootContactIds);
    }
}
