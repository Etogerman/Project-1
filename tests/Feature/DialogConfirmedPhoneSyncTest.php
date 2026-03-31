<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\ContactPhoneNumber;
use App\Models\Dialog;
use App\Services\Contacts\DeleteContactPhoneAction;
use App\Services\Contacts\UpdateContactPhoneAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DialogConfirmedPhoneSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_contact_phone_action_does_not_change_dialog_confirmed_phone(): void
    {
        $contact = Contact::factory()->create();
        $channel = Channel::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
        ]);

        $phoneNumber = ContactPhoneNumber::factory()->create([
            'contact_id' => $contact->id,
            'phone_raw' => '+7 999 123 45 67',
            'phone_normalized' => '+79991234567',
            'is_primary' => true,
        ]);

        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'confirmed_phone_raw' => '+7 999 123 45 67',
            'confirmed_phone_normalized' => '+79991234567',
            'phone_confirmed_at' => now(),
            'phone_confirmed_via' => Dialog::PHONE_CONFIRMED_VIA_PHONE_CAPTURE,
        ]);

        app(UpdateContactPhoneAction::class)->handle($phoneNumber, '+7 999 555 55 55');

        $dialog->refresh();

        $this->assertSame('+7 999 123 45 67', $dialog->confirmed_phone_raw);
        $this->assertSame('+79991234567', $dialog->confirmed_phone_normalized);
        $this->assertSame(Dialog::PHONE_CONFIRMED_VIA_PHONE_CAPTURE, $dialog->phone_confirmed_via);
    }

    public function test_delete_contact_phone_action_does_not_change_dialog_confirmed_phone(): void
    {
        $contact = Contact::factory()->create();
        $channel = Channel::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
        ]);

        $phoneNumber = ContactPhoneNumber::factory()->create([
            'contact_id' => $contact->id,
            'phone_raw' => '+7 999 123 45 67',
            'phone_normalized' => '+79991234567',
            'is_primary' => true,
        ]);

        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'confirmed_phone_raw' => '+7 999 123 45 67',
            'confirmed_phone_normalized' => '+79991234567',
            'phone_confirmed_at' => now(),
            'phone_confirmed_via' => Dialog::PHONE_CONFIRMED_VIA_PHONE_CAPTURE,
        ]);

        app(DeleteContactPhoneAction::class)->handle($phoneNumber);

        $dialog->refresh();

        $this->assertSame('+7 999 123 45 67', $dialog->confirmed_phone_raw);
        $this->assertSame('+79991234567', $dialog->confirmed_phone_normalized);
        $this->assertSame(Dialog::PHONE_CONFIRMED_VIA_PHONE_CAPTURE, $dialog->phone_confirmed_via);
    }
}
