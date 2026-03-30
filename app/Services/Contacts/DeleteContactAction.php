<?php

namespace App\Services\Contacts;

use App\Models\Contact;
use Illuminate\Support\Facades\DB;

class DeleteContactAction
{
    public function handle(Contact $contact): void
    {
        DB::transaction(function () use ($contact): void {
            $contact->delete();
        });
    }
}
