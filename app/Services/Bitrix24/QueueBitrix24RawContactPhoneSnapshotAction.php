<?php

namespace App\Services\Bitrix24;

use App\Jobs\LogBitrix24RawContactPhoneSnapshotJob;
use App\Models\Contact;
use App\Models\Dialog;
use App\Models\Message;
use App\Services\Contacts\ResolveRootContactAction;

class QueueBitrix24RawContactPhoneSnapshotAction
{
    public function __construct(
        private readonly ResolveRootContactAction $resolveRootContactAction,
        private readonly ShouldRunBitrix24DuplicatePhoneDiagnosticAction $shouldRunDiagnosticAction,
        private readonly Bitrix24OpenLineScopedMutation $scopedMutation,
    ) {}

    public function handle(
        Contact|int $contact,
        string $stage,
        ?Dialog $dialog = null,
        ?Message $message = null,
    ): bool {
        $rootContact = $this->resolveRootContactAction->handle($contact);

        if (! $this->shouldRunDiagnosticAction->handle($rootContact)) {
            return false;
        }

        if (! filled($rootContact->bitrix24_contact_id)) {
            return false;
        }

        $this->scopedMutation->assertCurrent();
        LogBitrix24RawContactPhoneSnapshotJob::dispatch(
            $rootContact->id,
            $stage,
            $dialog?->id,
            $message?->id,
        )->delay(now()->addSeconds((int) config('bitrix24.duplicate_phone_diagnostic.delay_seconds', 90)))
            ->afterCommit();

        return true;
    }
}
