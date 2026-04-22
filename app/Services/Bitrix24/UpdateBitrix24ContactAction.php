<?php

namespace App\Services\Bitrix24;

use App\Models\Bitrix24Connection;
use App\Models\Contact;

class UpdateBitrix24ContactAction
{
    public function __construct(
        private readonly Bitrix24ApiClient $apiClient,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(Contact $contact, string $bitrix24ContactId, array $payload, ?Bitrix24Connection $connection = null): void
    {
        $response = $this->apiClient->call('crm.contact.update', [
            'id' => $bitrix24ContactId,
            'fields' => $payload,
        ], $connection);

        if (! $response->successful || ! in_array($response->result, [true, 1, '1'], true)) {
            throw new Bitrix24ApiException(
                sprintf('Bitrix24 contact `%s` could not be updated.', $bitrix24ContactId),
            );
        }
    }
}
