<?php

namespace Tests\Feature;

use App\Data\Bitrix24\Bitrix24OpenLinesRouteData;
use App\Models\Bitrix24Connection;
use App\Models\Bitrix24MessageExport;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Dialog;
use App\Services\Bitrix24\Bitrix24OpenLineMutationGuardException;
use App\Services\Bitrix24\GuardBitrix24OpenLineMutationAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Bitrix24OpenLineMutationGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('bitrix24.openlines.official_connector_codes', [
            'abrikosoff_telegram',
            'abrikosoff_max',
        ]);
        config()->set('bitrix24.openlines.allow_official_connectors_in_local', false);
    }

    public function test_local_runtime_blocks_official_open_line_connector_mutation(): void
    {
        config()->set('app.env', 'local');

        try {
            $this->guard()->assertRuntimeAllowsRouteMutation($this->route('abrikosoff_max', '3'));
        } catch (Bitrix24OpenLineMutationGuardException $exception) {
            $this->assertSame(
                Bitrix24MessageExport::FAILURE_LOCAL_OFFICIAL_OPEN_LINE_MUTATION_BLOCKED,
                $exception->failureCode,
            );
            $this->assertStringContainsString('official connector [abrikosoff_max]', $exception->getMessage());

            return;
        }

        $this->fail('Expected local official Open Lines mutation guard exception.');
    }

    public function test_local_runtime_allows_dev_open_line_connector_codes(): void
    {
        config()->set('app.env', 'local');

        $this->guard()->assertRuntimeAllowsRouteMutation($this->route('abc_max_dev_local', '33'));

        $this->assertTrue(true);
    }

    public function test_local_runtime_allows_official_connector_when_explicitly_overridden(): void
    {
        config()->set('app.env', 'local');
        config()->set('bitrix24.openlines.allow_official_connectors_in_local', true);

        $this->guard()->assertRuntimeAllowsRouteMutation($this->route('abrikosoff_max', '3'));

        $this->assertTrue(true);
    }

    public function test_non_local_runtime_allows_official_open_line_connector_codes(): void
    {
        config()->set('app.env', 'staging');

        $this->guard()->assertRuntimeAllowsRouteMutation($this->route('abrikosoff_max', '3'));

        $this->assertTrue(true);
    }

    public function test_full_mutation_guard_applies_local_runtime_isolation_before_platform_specific_checks(): void
    {
        config()->set('app.env', 'local');

        try {
            $this->guard()->handle(
                new Dialog,
                new Contact,
                $this->route('abrikosoff_max', '3'),
                new Bitrix24Connection,
            );
        } catch (Bitrix24OpenLineMutationGuardException $exception) {
            $this->assertSame(
                Bitrix24MessageExport::FAILURE_LOCAL_OFFICIAL_OPEN_LINE_MUTATION_BLOCKED,
                $exception->failureCode,
            );

            return;
        }

        $this->fail('Expected local official Open Lines mutation guard exception.');
    }

    private function guard(): GuardBitrix24OpenLineMutationAction
    {
        return app(GuardBitrix24OpenLineMutationAction::class);
    }

    private function route(string $connectorCode, string $lineId): Bitrix24OpenLinesRouteData
    {
        return new Bitrix24OpenLinesRouteData(
            platform: Channel::PLATFORM_MAX,
            connectorCode: $connectorCode,
            lineId: $lineId,
        );
    }
}
