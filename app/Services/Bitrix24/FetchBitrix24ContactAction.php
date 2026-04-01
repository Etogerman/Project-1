<?php

namespace App\Services\Bitrix24;

class FetchBitrix24ContactAction
{
    public function __construct(
        private readonly Bitrix24ApiClient $apiClient,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(string $bitrix24ContactId): array
    {
        $response = $this->apiClient->call('crm.contact.get', [
            'id' => $bitrix24ContactId,
        ]);

        if (! $response->successful || ! is_array($response->result) || ! filled($response->result['ID'] ?? null)) {
            throw new Bitrix24ApiException(
                sprintf('Bitrix24 contact `%s` could not be fetched.', $bitrix24ContactId),
            );
        }

        return $response->result;
    }
}
