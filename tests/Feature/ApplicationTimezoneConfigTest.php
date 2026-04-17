<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApplicationTimezoneConfigTest extends TestCase
{
    public function test_application_timezone_defaults_to_europe_moscow(): void
    {
        $this->assertSame('Europe/Moscow', config('app.timezone'));
        $this->assertSame('Europe/Moscow', date_default_timezone_get());
    }
}
