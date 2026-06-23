<?php

namespace App\Services\Bitrix24;

use App\Models\Bitrix24Connection;
use InvalidArgumentException;

class BuildBitrix24CrmEntityUrlAction
{
    public const ENTITY_CONTACT = 'contact';

    public const ENTITY_DEAL = 'deal';

    public function __construct(
        private readonly ResolveCurrentBitrix24ConnectionAction $resolveCurrentConnection,
    ) {}

    public function handle(string $entityType, mixed $entityId): ?string
    {
        $id = $this->normalizeEntityId($entityId);

        if ($id === null) {
            return null;
        }

        $entitySegment = match ($entityType) {
            self::ENTITY_CONTACT => 'contact',
            self::ENTITY_DEAL => 'deal',
            default => throw new InvalidArgumentException(sprintf('Unsupported Bitrix24 CRM entity type `%s`.', $entityType)),
        };

        try {
            $connection = $this->resolveCurrentConnection->handle();
        } catch (Bitrix24ConnectionStateException|NoActiveBitrix24ConnectionException) {
            return null;
        }

        $portalDomain = $this->resolvePortalDomain($connection);

        if ($portalDomain === null) {
            return null;
        }

        return sprintf('https://%s/crm/%s/details/%s/', $portalDomain, $entitySegment, $id);
    }

    private function normalizeEntityId(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $id = trim((string) $value);

        if ($id === '' || ! preg_match('/^\d+$/', $id)) {
            return null;
        }

        return $id;
    }

    private function resolvePortalDomain(Bitrix24Connection $connection): ?string
    {
        return $this->normalizePortalDomain($connection->portal_domain)
            ?? $this->normalizePortalDomain(parse_url((string) $connection->client_endpoint, PHP_URL_HOST));
    }

    private function normalizePortalDomain(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $domain = trim((string) $value);

        if ($domain === '') {
            return null;
        }

        if (str_contains($domain, '://')) {
            $host = parse_url($domain, PHP_URL_HOST);

            if (! is_string($host)) {
                return null;
            }

            $domain = $host;
        }

        $domain = strtolower(trim($domain, " \t\n\r\0\x0B/"));

        if ($domain === '' || str_contains($domain, '/') || str_contains($domain, '\\') || preg_match('/\s/', $domain) === 1) {
            return null;
        }

        return $domain;
    }
}
