<?php

namespace App\Services\Bitrix24;

use App\Models\Contact;
use App\Services\Contacts\ResolveRootContactAction;

class BuildBitrix24DealPayloadAction
{
    public function __construct(
        private readonly ResolveRootContactAction $resolveRootContactAction,
        private readonly ResolveBitrix24ContactSourceAction $resolveContactSourceAction,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(Contact|int $contact): array
    {
        $rootContact = $this->resolveRootContactAction->handle($contact);
        $bitrix24ContactId = $this->requireBitrix24ContactId($rootContact);
        $sourceId = $this->resolveContactSourceAction->handle($rootContact);

        if (! filled($sourceId)) {
            throw new Bitrix24ApiException(
                sprintf('Bitrix24 deal create requires a source mapping for contact #%d.', $rootContact->id),
            );
        }

        return [
            'TITLE' => $this->buildTitle($rootContact),
            'CATEGORY_ID' => (int) config('bitrix24.defaults.deal_category_id', 22),
            'STAGE_ID' => (string) config('bitrix24.defaults.deal_stage_id', 'C22:NEW'),
            'ASSIGNED_BY_ID' => (int) config('bitrix24.defaults.assigned_user_id', 1),
            'CONTACT_ID' => $bitrix24ContactId,
            'SOURCE_ID' => $sourceId,
        ];
    }

    private function buildTitle(Contact $contact): string
    {
        $fullName = trim(implode(' ', array_filter([
            $contact->first_name,
            $contact->last_name,
        ], static fn (mixed $value): bool => filled($value))));

        if ($fullName !== '') {
            return 'Abrikosoff / '.$fullName;
        }

        return sprintf('Abrikosoff / Contact #%d', $contact->id);
    }

    private function requireBitrix24ContactId(Contact $contact): string
    {
        $value = $contact->bitrix24_contact_id;

        if (! is_scalar($value)) {
            throw new Bitrix24ApiException(
                sprintf('Bitrix24 deal create requires a linked Bitrix24 contact for contact #%d.', $contact->id),
            );
        }

        $normalized = trim((string) $value);

        if ($normalized === '' || ! ctype_digit($normalized)) {
            throw new Bitrix24ApiException(
                sprintf('Bitrix24 deal create requires a valid Bitrix24 contact id for contact #%d.', $contact->id),
            );
        }

        return $normalized;
    }
}
