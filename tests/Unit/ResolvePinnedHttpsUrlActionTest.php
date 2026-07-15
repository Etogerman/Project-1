<?php

namespace Tests\Unit;

use App\Services\Messages\ResolvePinnedHttpsUrlAction;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ResolvePinnedHttpsUrlActionTest extends TestCase
{
    public function test_it_pins_a_trusted_host_to_a_public_address(): void
    {
        $result = (new class extends ResolvePinnedHttpsUrlAction
        {
            protected function resolveAddresses(string $host): array
            {
                return ['93.184.216.34'];
            }
        })->handle('https://cdn.max.ru/media/video.mp4', ['max.ru']);

        $this->assertSame('https://cdn.max.ru/media/video.mp4', $result->url);
        $this->assertSame(['cdn.max.ru:443:93.184.216.34'], $result->curlOptions[CURLOPT_RESOLVE]);
    }

    #[DataProvider('unsafeUrlProvider')]
    public function test_it_rejects_unsafe_or_untrusted_urls(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new class extends ResolvePinnedHttpsUrlAction
        {
            protected function resolveAddresses(string $host): array
            {
                return ['93.184.216.34'];
            }
        })->handle($url, ['max.ru']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafeUrlProvider(): array
    {
        return [
            'plain HTTP' => ['http://cdn.max.ru/media.mp4'],
            'untrusted sibling' => ['https://evilmax.ru/media.mp4'],
            'explicit port' => ['https://cdn.max.ru:8443/media.mp4'],
            'embedded credentials' => ['https://user:secret@cdn.max.ru/media.mp4'],
        ];
    }

    #[DataProvider('nonPublicAddressProvider')]
    public function test_it_rejects_private_and_special_purpose_addresses(string $address): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new class($address) extends ResolvePinnedHttpsUrlAction
        {
            public function __construct(private readonly string $address) {}

            protected function resolveAddresses(string $host): array
            {
                return [$this->address];
            }
        })->handle('https://cdn.max.ru/media.mp4', ['max.ru']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonPublicAddressProvider(): array
    {
        return [
            'loopback IPv4' => ['127.0.0.1'],
            'private IPv4' => ['10.0.0.1'],
            'metadata IPv4' => ['169.254.169.254'],
            'carrier-grade NAT' => ['100.64.0.1'],
            'benchmark IPv4' => ['198.18.0.1'],
            'documentation IPv4' => ['203.0.113.1'],
            'multicast IPv4' => ['224.0.0.1'],
            'loopback IPv6' => ['::1'],
            'IPv4-mapped IPv6' => ['::ffff:127.0.0.1'],
            'discard-only IPv6' => ['100::1'],
            'documentation IPv6' => ['2001:db8::1'],
            'unique-local IPv6' => ['fc00::1'],
            'multicast IPv6' => ['ff00::1'],
        ];
    }
}
