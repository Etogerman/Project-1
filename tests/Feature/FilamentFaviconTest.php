<?php

namespace Tests\Feature;

use Filament\Facades\Filament;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FilamentFaviconTest extends TestCase
{
    #[DataProvider('faviconEnvironmentProvider')]
    public function test_admin_panel_favicon_depends_on_environment(string $environment, string $path): void
    {
        $this->app->detectEnvironment(fn (): string => $environment);

        $this->assertSame(asset($path), Filament::getPanel('admin')->getFavicon());
    }

    public static function faviconEnvironmentProvider(): array
    {
        return [
            'local' => ['local', 'favicons/favicon-local.svg'],
            'staging' => ['staging', 'favicons/favicon-staging.svg'],
            'production' => ['production', 'favicons/favicon-production.svg'],
            'unknown' => ['qa', 'favicons/favicon-production.svg'],
        ];
    }
}
