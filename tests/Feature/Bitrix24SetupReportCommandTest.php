<?php

namespace Tests\Feature;

use Tests\TestCase;

class Bitrix24SetupReportCommandTest extends TestCase
{
    public function test_command_fails_when_required_setup_values_are_missing(): void
    {
        config()->set('bitrix24', array_replace_recursive(config('bitrix24'), [
            'application' => [
                'client_id' => null,
                'client_secret' => null,
            ],
            'callbacks' => [
                'install_url' => null,
                'events_url' => null,
                'openlines_url' => null,
            ],
            'sources' => [
                'telegram_id' => null,
                'max_id' => null,
            ],
            'openlines' => [
                'telegram_line_id' => null,
                'max_line_id' => null,
                'telegram_connector_code' => null,
                'max_connector_code' => null,
            ],
        ]));

        $this->artisan('bitrix24:setup-report')
            ->expectsOutput('Bitrix24 setup readiness check completed.')
            ->expectsOutputToContain('Bitrix24 client_id')
            ->expectsOutputToContain('Install callback URL')
            ->expectsOutputToContain('Bitrix24 setup is not ready for implementation. Resolve all missing required items first.')
            ->assertFailed();
    }

    public function test_command_fails_when_probe_callback_is_used_as_production_endpoint(): void
    {
        config()->set('bitrix24', array_replace_recursive(config('bitrix24'), [
            'application' => [
                'client_id' => 'client-id',
                'client_secret' => 'client-secret',
            ],
            'callbacks' => [
                'install_url' => 'https://project.example.com/callbacks/bitrix24/probe',
                'events_url' => 'https://project.example.com/callbacks/bitrix24/events',
                'openlines_url' => 'https://project.example.com/callbacks/bitrix24/openlines',
            ],
            'sources' => [
                'telegram_id' => 'ABRIKOSOFF_TG',
                'max_id' => 'ABRIKOSOFF_MAX',
            ],
            'openlines' => [
                'telegram_line_id' => '101',
                'max_line_id' => '102',
                'telegram_connector_code' => 'abrikosoff_telegram',
                'max_connector_code' => 'abrikosoff_max',
            ],
        ]));

        $this->artisan('bitrix24:setup-report')
            ->expectsOutputToContain('Discovery probe callbacks are not valid production endpoints.')
            ->assertFailed();
    }

    public function test_command_succeeds_when_setup_contract_is_fully_frozen(): void
    {
        config()->set('bitrix24', array_replace_recursive(config('bitrix24'), [
            'application' => [
                'client_id' => 'client-id',
                'client_secret' => 'client-secret',
            ],
            'callbacks' => [
                'install_url' => 'https://project.example.com/callbacks/bitrix24/install',
                'events_url' => 'https://project.example.com/callbacks/bitrix24/events',
                'openlines_url' => 'https://project.example.com/callbacks/bitrix24/openlines',
            ],
            'sources' => [
                'telegram_id' => 'ABRIKOSOFF_TG',
                'max_id' => 'ABRIKOSOFF_MAX',
            ],
            'openlines' => [
                'telegram_line_id' => '101',
                'max_line_id' => '102',
                'telegram_connector_code' => 'abrikosoff_telegram',
                'max_connector_code' => 'abrikosoff_max',
            ],
        ]));

        $this->artisan('bitrix24:setup-report')
            ->expectsOutput('Bitrix24 setup readiness check completed.')
            ->expectsOutputToContain('Bitrix24 portal domain')
            ->doesntExpectOutputToContain('client-secret')
            ->expectsOutputToContain('*** redacted ***')
            ->expectsOutputToContain('Bitrix24 setup is ready for the integration foundation stage.')
            ->assertSuccessful();
    }
}
