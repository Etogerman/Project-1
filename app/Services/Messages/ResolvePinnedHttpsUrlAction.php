<?php

namespace App\Services\Messages;

use App\Data\Messages\PinnedHttpsUrlData;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\IpUtils;

class ResolvePinnedHttpsUrlAction
{
    /**
     * Ranges that must never be used as provider media destinations.
     * PHP's FILTER_FLAG_NO_RES_RANGE does not cover several special-purpose networks.
     *
     * @var list<string>
     */
    private const NON_PUBLIC_NETWORKS = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
        '::/128',
        '::1/128',
        '::ffff:0:0/96',
        '64:ff9b::/96',
        '64:ff9b:1::/48',
        '100::/64',
        '2001::/32',
        '2001:2::/48',
        '2001:10::/28',
        '2001:20::/28',
        '2001:db8::/32',
        '2002::/16',
        '3fff::/20',
        'fc00::/7',
        'fe80::/10',
        'ff00::/8',
    ];

    /**
     * @param  list<string>  $trustedHosts
     * @param  array<string, string>  $configuredPinnedIps
     */
    public function handle(
        string $url,
        array $trustedHosts,
        array $configuredPinnedIps = [],
    ): PinnedHttpsUrlData {
        $parts = parse_url($url);

        if (! is_array($parts)) {
            throw new InvalidArgumentException('Provider media URL is malformed.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($scheme !== 'https' || $host === '') {
            throw new InvalidArgumentException('Provider media URL must use HTTPS and a trusted host.');
        }

        if (array_key_exists('port', $parts) || array_key_exists('user', $parts) || array_key_exists('pass', $parts)) {
            throw new InvalidArgumentException('Provider media URL contains unsupported connection parts.');
        }

        if (! $this->isTrustedHost($host, $trustedHosts)) {
            throw new InvalidArgumentException('Provider media URL host is not trusted.');
        }

        $configuredIp = $configuredPinnedIps[$host] ?? null;
        $addresses = is_string($configuredIp) && trim($configuredIp) !== ''
            ? [trim($configuredIp)]
            : $this->resolveAddresses($host);
        $pinnedIp = collect($addresses)
            ->first(fn (mixed $address): bool => is_string($address) && $this->isPublicIp($address));

        if (! is_string($pinnedIp)) {
            throw new InvalidArgumentException('Provider media host did not resolve to a public IP address.');
        }

        if (! defined('CURLOPT_RESOLVE')) {
            throw new RuntimeException('cURL host pinning is unavailable.');
        }

        $resolvedAddress = str_contains($pinnedIp, ':') ? '['.$pinnedIp.']' : $pinnedIp;

        return new PinnedHttpsUrlData(
            url: $url,
            curlOptions: [
                CURLOPT_RESOLVE => [sprintf('%s:443:%s', $host, $resolvedAddress)],
            ],
        );
    }

    /**
     * @param  list<string>  $trustedHosts
     */
    private function isTrustedHost(string $host, array $trustedHosts): bool
    {
        foreach ($trustedHosts as $trustedHost) {
            $normalized = strtolower(trim($trustedHost));

            if ($normalized !== '' && ($host === $normalized || str_ends_with($host, '.'.$normalized))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    protected function resolveAddresses(string $host): array
    {
        $records = dns_get_record($host, DNS_A | DNS_AAAA);

        if (! is_array($records)) {
            return [];
        }

        $addresses = [];

        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($address) && ! in_array($address, $addresses, true)) {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }

    private function isPublicIp(string $address): bool
    {
        return filter_var($address, FILTER_VALIDATE_IP) !== false
            && ! IpUtils::checkIp($address, self::NON_PUBLIC_NETWORKS);
    }
}
