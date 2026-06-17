<?php

namespace App\Services\Contacts;

use App\Models\Contact;
use App\Models\ContactEmail;
use App\Services\Bitrix24\QueueBitrix24ContactSyncAction;
use Illuminate\Database\QueryException;
use RuntimeException;

class AddContactEmailAction
{
    public function __construct(
        private readonly ResolveRootContactAction $resolveRootContactAction,
        private readonly QueueBitrix24ContactSyncAction $queueBitrix24ContactSyncAction,
    ) {}

    public function handle(Contact $contact, string $emailRaw, string $source): ContactEmail
    {
        $contact = $this->resolveRootContactAction->handle($contact);

        $emailRaw = trim($emailRaw);
        $emailNormalized = ContactEmail::normalizeEmail($emailRaw);

        if (! filter_var($emailNormalized, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Укажите корректный email.');
        }

        $existing = $contact->emails()
            ->where('email_normalized', $emailNormalized)
            ->first();

        if ($existing instanceof ContactEmail) {
            return $existing;
        }

        try {
            $email = $contact->emails()->create([
                'email_raw' => $emailRaw,
                'email_normalized' => $emailNormalized,
                'source' => $source,
                'is_primary' => ! $contact->emails()->exists(),
            ]);

            $this->queueBitrix24ContactSyncAction->handle($contact);

            return $email;
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? null) !== '23505') {
                throw $exception;
            }

            return $contact->emails()
                ->where('email_normalized', $emailNormalized)
                ->firstOrFail();
        }
    }
}
