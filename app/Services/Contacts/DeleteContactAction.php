<?php

namespace App\Services\Contacts;

use App\Models\Contact;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DeleteContactAction
{
    public function handle(Contact $contact): void
    {
        if ($contact->isMerged()) {
            throw new RuntimeException('Архивный дубль нельзя удалять напрямую. Откройте основной контакт.');
        }

        if ($contact->mergedChildren()->exists()) {
            throw new RuntimeException('Нельзя удалить основной контакт, у которого есть склеенные дубли.');
        }

        DB::transaction(function () use ($contact): void {
            $contact->delete();
        });
    }
}
