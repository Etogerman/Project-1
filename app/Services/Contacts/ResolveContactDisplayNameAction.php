<?php

namespace App\Services\Contacts;

use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Dialog;

class ResolveContactDisplayNameAction
{
    public function handle(Contact $contact, ?Dialog $dialogContext = null): string
    {
        $firstName = $this->normalizeString($contact->first_name);
        $lastName = $this->normalizeString($contact->last_name);

        if ($firstName !== null) {
            return $this->resolveDisplayNameFromOperatorProfile($firstName, $lastName);
        }

        $dialogIdentity = $dialogContext?->currentContactIdentity;

        if ($dialogIdentity instanceof ContactIdentity) {
            $dialogLabel = $this->resolveIdentityLabel($dialogIdentity);

            if ($dialogLabel !== null) {
                return $dialogLabel;
            }
        }

        $legacyName = $this->normalizeString($contact->name);

        if ($legacyName !== null) {
            return $legacyName;
        }

        $identity = $this->resolveRelevantIdentity($contact);

        if ($identity instanceof ContactIdentity) {
            $identityLabel = $this->resolveIdentityLabel($identity);

            if ($identityLabel !== null) {
                return $identityLabel;
            }
        }

        return sprintf('Контакт #%d', $contact->id);
    }

    private function resolveRelevantIdentity(Contact $contact): ?ContactIdentity
    {
        $dialog = $contact->dialogs()
            ->with('currentContactIdentity')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->first();

        if ($dialog?->currentContactIdentity instanceof ContactIdentity) {
            return $dialog->currentContactIdentity;
        }

        $identity = $contact->relationLoaded('primaryIdentity')
            ? $contact->primaryIdentity
            : $contact->primaryIdentity()->first();

        return $identity instanceof ContactIdentity ? $identity : null;
    }

    private function resolveDisplayNameFromOperatorProfile(string $firstName, ?string $lastName): string
    {
        if ($lastName === null) {
            return $firstName;
        }

        $firstNameLastWord = $this->resolveLastWord($firstName);

        if ($firstNameLastWord !== null && mb_strtolower($firstNameLastWord) === mb_strtolower($lastName)) {
            return $firstName;
        }

        return trim($firstName.' '.$lastName);
    }

    private function resolveIdentityLabel(ContactIdentity $identity): ?string
    {
        $displayName = $this->normalizeString($identity->getAttribute('display_name'));

        if ($displayName !== null) {
            return $displayName;
        }

        $externalUsername = $this->normalizeString($identity->external_username);

        if ($externalUsername !== null) {
            return '@'.ltrim($externalUsername, '@');
        }

        $externalUserId = $this->normalizeString($identity->external_user_id);

        if ($externalUserId !== null) {
            return $externalUserId;
        }

        return null;
    }

    private function normalizeString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = preg_replace('/\s+/u', ' ', trim((string) $value));

        if (! is_string($normalized)) {
            return null;
        }

        return $normalized === '' ? null : $normalized;
    }

    private function resolveLastWord(string $value): ?string
    {
        $parts = preg_split('/\s+/u', $value, -1, PREG_SPLIT_NO_EMPTY);

        if (! is_array($parts) || $parts === []) {
            return null;
        }

        $lastPart = array_pop($parts);

        if (! is_string($lastPart)) {
            return null;
        }

        $normalized = trim($lastPart, " \t\n\r\0\x0B.,;:!?");

        return $normalized === '' ? null : $normalized;
    }
}
