<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactPhoneNumber;
use App\Services\Contacts\DeleteContactAction;
use App\Services\Contacts\DeleteContactPhoneAction;
use App\Services\Contacts\UpdateContactPhoneAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class MergedContactGuardActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_contact_phone_action_rejects_phone_of_merged_contact(): void
    {
        $root = Contact::factory()->create();
        $merged = Contact::factory()->create([
            'merged_into_contact_id' => $root->id,
            'merged_at' => now(),
        ]);
        $phoneNumber = ContactPhoneNumber::factory()->create([
            'contact_id' => $merged->id,
            'phone_raw' => '+7 999 123 45 67',
            'phone_normalized' => '+79991234567',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Номер относится к архивному дублю. Измените номер у основного контакта.');

        app(UpdateContactPhoneAction::class)->handle($phoneNumber, '+7 999 555 55 55');
    }

    public function test_delete_contact_phone_action_rejects_phone_of_merged_contact(): void
    {
        $root = Contact::factory()->create();
        $merged = Contact::factory()->create([
            'merged_into_contact_id' => $root->id,
            'merged_at' => now(),
        ]);
        $phoneNumber = ContactPhoneNumber::factory()->create([
            'contact_id' => $merged->id,
            'phone_raw' => '+7 999 123 45 67',
            'phone_normalized' => '+79991234567',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Номер относится к архивному дублю. Удалите номер у основного контакта.');

        app(DeleteContactPhoneAction::class)->handle($phoneNumber);
    }

    public function test_delete_contact_action_rejects_merged_secondary(): void
    {
        $root = Contact::factory()->create();
        $merged = Contact::factory()->create([
            'merged_into_contact_id' => $root->id,
            'merged_at' => now(),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Архивный дубль нельзя удалять напрямую. Откройте основной контакт.');

        app(DeleteContactAction::class)->handle($merged);
    }

    public function test_delete_contact_action_rejects_root_with_merged_children(): void
    {
        $root = Contact::factory()->create();
        Contact::factory()->create([
            'merged_into_contact_id' => $root->id,
            'merged_at' => now(),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Нельзя удалить основной контакт, у которого есть склеенные дубли.');

        app(DeleteContactAction::class)->handle($root);
    }
}
