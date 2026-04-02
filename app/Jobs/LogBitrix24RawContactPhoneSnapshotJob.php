<?php

namespace App\Jobs;

use App\Models\Contact;
use App\Services\Bitrix24\LogBitrix24RawContactPhoneSnapshotAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class LogBitrix24RawContactPhoneSnapshotJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $contactId,
        public readonly string $stage,
        public readonly ?int $dialogId = null,
        public readonly ?int $messageId = null,
    ) {}

    public function handle(LogBitrix24RawContactPhoneSnapshotAction $logBitrix24RawContactPhoneSnapshotAction): void
    {
        $contact = Contact::query()->find($this->contactId);

        if (! $contact instanceof Contact) {
            return;
        }

        $dialog = $this->dialogId === null ? null : $contact->dialogs()->find($this->dialogId);
        $message = $this->messageId === null ? null : $contact->messages()->find($this->messageId);

        $logBitrix24RawContactPhoneSnapshotAction->handle(
            $contact,
            $this->stage,
            $dialog,
            $message,
        );
    }
}
