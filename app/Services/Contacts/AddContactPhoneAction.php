<?php

namespace App\Services\Contacts;

use App\Models\Contact;
use App\Models\ContactPhoneNumber;
use App\Services\Bitrix24\QueueBitrix24ContactSyncAction;
use Illuminate\Database\QueryException;
use RuntimeException;

class AddContactPhoneAction
{
    public function __construct(
        private readonly NormalizePhoneNumberAction $normalizePhoneNumberAction,
        private readonly ResolveRootContactAction $resolveRootContactAction,
        private readonly QueueBitrix24ContactSyncAction $queueBitrix24ContactSyncAction,
    ) {}

    public function handle(Contact $contact, string $phoneRaw, string $source): ContactPhoneNumber
    {
        $contact = $this->resolveRootContactAction->handle($contact);

        $phoneRaw = trim($phoneRaw);
        $phoneNormalized = $this->normalizePhoneNumberAction->handle($phoneRaw);

        if ($phoneNormalized === '') {
            throw new RuntimeException('Укажите корректный номер телефона.');
        }

        $existing = $contact->phoneNumbers()
            ->where('phone_normalized', $phoneNormalized)
            ->first();

        if ($existing instanceof ContactPhoneNumber) {
            return $existing;
        }

        try {
            $phoneNumber = $contact->phoneNumbers()->create([
                'phone_raw' => $phoneRaw,
                'phone_normalized' => $phoneNormalized,
                'source' => $source,
                'is_primary' => ! $contact->phoneNumbers()->exists(),
            ]);

            $this->queueBitrix24ContactSyncAction->handle($contact);

            return $phoneNumber;
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? null) !== '23505') {
                throw $exception;
            }

            return $contact->phoneNumbers()
                ->where('phone_normalized', $phoneNormalized)
                ->firstOrFail();
        }
    }

    public static function normalizePhone(string $phoneRaw): string
    {
        return app(NormalizePhoneNumberAction::class)->handle($phoneRaw);
    }

    public static function maskPhone(string $phoneNormalized): string
    {
        $lastFour = mb_substr($phoneNormalized, -4);
        $prefix = mb_substr($phoneNormalized, 0, max(0, mb_strlen($phoneNormalized) - 4));

        $maskedPrefix = preg_replace('/\d/u', '*', $prefix) ?? $prefix;

        return $maskedPrefix.$lastFour;
    }
}
