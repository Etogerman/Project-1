<?php

namespace Tests\Unit;

use App\Support\TelegramLocalApiConfiguration;
use Tests\TestCase;

class TelegramLocalApiConfigurationTest extends TestCase
{
    public function test_it_normalizes_only_safe_absolute_local_api_files_roots(): void
    {
        $this->assertSame(
            '/var/lib/telegram-bot-api',
            TelegramLocalApiConfiguration::absoluteFilesRoot(' /var/lib/telegram-bot-api/ '),
        );
        $this->assertSame(
            '/var/lib/telegram-bot-api',
            TelegramLocalApiConfiguration::absoluteFilesRoot('\var\lib\telegram-bot-api'),
        );
        $this->assertNull(TelegramLocalApiConfiguration::absoluteFilesRoot('telegram-bot-api'));
        $this->assertNull(TelegramLocalApiConfiguration::absoluteFilesRoot('/var/lib/../telegram-bot-api'));
    }

    public function test_it_normalizes_bracketed_ipv6_hosts_without_loosening_hostname_matching(): void
    {
        $this->assertSame('::1', TelegramLocalApiConfiguration::normalizedHost('[::1]'));
        $this->assertSame('::1', TelegramLocalApiConfiguration::normalizedHost('::1'));
        $this->assertNull(TelegramLocalApiConfiguration::normalizedHost('[::1].'));
        $this->assertNull(TelegramLocalApiConfiguration::normalizedHost('[::1] '));
        $this->assertSame(
            'telegram-gateway.example ',
            TelegramLocalApiConfiguration::normalizedHost('telegram-gateway.example '),
        );
        $this->assertNull(TelegramLocalApiConfiguration::normalizedHost('[telegram-gateway.example]'));
        $this->assertSame(
            ['::1', 'telegram-gateway.example'],
            TelegramLocalApiConfiguration::normalizedTrustedHosts([
                ' [::1] ',
                '::1',
                ' telegram-gateway.example. ',
                '',
            ]),
        );
        $this->assertSame(
            [],
            TelegramLocalApiConfiguration::normalizedTrustedHosts(['[::1].']),
        );
    }
}
