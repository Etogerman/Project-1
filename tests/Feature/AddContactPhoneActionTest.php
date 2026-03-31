<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Services\Contacts\AddContactPhoneAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AddContactPhoneActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_add_contact_phone_creates_new_phone_and_marks_first_as_primary(): void
    {
        $contact = Contact::factory()->create();

        $phoneNumber = app(AddContactPhoneAction::class)->handle(
            $contact,
            '+7 (999) 123-45-67',
            'telegram_contact_share',
        );

        $this->assertSame('+7 (999) 123-45-67', $phoneNumber->phone_raw);
        $this->assertSame('+79991234567', $phoneNumber->phone_normalized);
        $this->assertTrue($phoneNumber->is_primary);
        $this->assertDatabaseCount('contact_phone_numbers', 1);
    }

    public function test_add_contact_phone_deduplicates_by_normalized_phone(): void
    {
        $contact = Contact::factory()->create();
        $action = app(AddContactPhoneAction::class);

        $first = $action->handle($contact, '+7 999 123 45 67', 'telegram_contact_share');
        $second = $action->handle($contact, '+7(999)123-45-67', 'telegram_contact_share');

        $this->assertTrue($first->is($second));
        $this->assertDatabaseCount('contact_phone_numbers', 1);
    }

    public function test_add_contact_phone_uses_canonical_plus_seven_format_for_russian_numbers(): void
    {
        $contact = Contact::factory()->create();

        $phoneNumber = app(AddContactPhoneAction::class)->handle(
            $contact,
            '8 (999) 123-45-67',
            'telegram_contact_share',
        );

        $this->assertSame('+79991234567', $phoneNumber->phone_normalized);
    }

    public function test_add_contact_phone_resolves_merged_contact_to_root(): void
    {
        $root = Contact::factory()->create();
        $merged = Contact::factory()->create([
            'merged_into_contact_id' => $root->id,
            'merged_at' => now(),
        ]);

        $phoneNumber = app(AddContactPhoneAction::class)->handle(
            $merged,
            '+7 999 123 45 67',
            'manual',
        );

        $this->assertSame($root->id, $phoneNumber->contact_id);
        $this->assertDatabaseHas('contact_phone_numbers', [
            'id' => $phoneNumber->id,
            'contact_id' => $root->id,
            'phone_normalized' => '+79991234567',
        ]);
    }
}
