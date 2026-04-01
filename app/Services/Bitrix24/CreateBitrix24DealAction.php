<?php

namespace App\Services\Bitrix24;

use App\Models\Contact;
use App\Services\Contacts\ResolveRootContactAction;

class CreateBitrix24DealAction
{
    public function __construct(
        private readonly Bitrix24ApiClient $apiClient,
        private readonly ResolveRootContactAction $resolveRootContactAction,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(Contact|int $contact, array $payload): string
    {
        $rootContact = $this->resolveRootContactAction->handle($contact);
        $response = $this->apiClient->call('crm.deal.add', [
            'fields' => $payload,
        ]);

        if (! $response->successful) {
            throw new Bitrix24ApiException(
                sprintf('Bitrix24 deal create failed for contact #%d: %s', $rootContact->id, $response->errorMessage ?? 'Unknown error.'),
            );
        }

        $dealId = $this->extractCreatedDealId($response->result);

        if ($dealId === null) {
            throw new Bitrix24ApiException(
                sprintf('Bitrix24 deal create did not return a deal id for contact #%d.', $rootContact->id),
            );
        }

        return $dealId;
    }

    private function extractCreatedDealId(mixed $result): ?string
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
