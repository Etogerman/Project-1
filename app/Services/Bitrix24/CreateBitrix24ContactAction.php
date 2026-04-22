<?php

namespace App\Services\Bitrix24;

use App\Models\Bitrix24Connection;
use App\Models\Contact;
use App\Services\Contacts\ResolveRootContactAction;

class CreateBitrix24ContactAction
{
    public function __construct(
        private readonly Bitrix24ApiClient $apiClient,
        private readonly ResolveRootContactAction $resolveRootContactAction,
    ) {}

    public function handle(Contact|int $contact, array $payload, ?Bitrix24Connection $connection = null): string
    {
        $rootContact = $this->resolveRootContactAction->handle($contact);
        $response = $this->apiClient->call('crm.contact.add', [
            'fields' => $payload,
        ], $connection);

        if (! $response->successful) {
            throw new Bitrix24ApiException(
                sprintf('Bitrix24 contact create failed for contact #%d: %s', $rootContact->id, $response->errorMessage ?? 'Unknown error.'),
            );
        }

        $contactId = $this->extractCreatedContactId($response->result);

        if ($contactId === null) {
            throw new Bitrix24ApiException(
                sprintf('Bitrix24 contact create did not return a contact id for contact #%d.', $rootContact->id),
            );
        }

        return $contactId;
    }

    private function extractCreatedContactId(mixed $result): ?string
    {
        if (is_scalar($result)) {
            $value = trim((string) $result);

            return $value === '' ? null : $value;
        }

        if (! is_array($result)) {
            return null;
        }

        foreach (['ID', 'id', 'result'] as $key) {
            $value = $result[$key] ?? null;

            if (! is_scalar($value)) {
                continue;
            }

            $normalized = trim((string) $value);

            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }
}
