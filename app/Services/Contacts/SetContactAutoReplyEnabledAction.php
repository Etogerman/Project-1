<?php

namespace App\Services\Contacts;

use App\Models\Contact;

class SetContactAutoReplyEnabledAction
{
    public function handle(Contact $contact, bool $isEnabled): Contact
    {
        $contact->forceFill([
            'is_auto_reply_enabled' => $isEnabled,
        ])->save();

        return $contact->refresh();
    }
}
