<?php

namespace Tests\Feature;

use Closure;
use Tests\TestCase;

class Bitrix24SetupReportCommandTest extends TestCase
{
    public function test_command_fails_when_required_setup_values_are_missing(): void
    {
        config()->set('bitrix24', array_replace_recursive(config('bitrix24'), [
            'application' => [
                'client_id' => null,
                'client_secret' => null,
                'code' => null,
            ],
            'oauth' => [
                'server_url' => null,
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
            ->expectsOutputToContain('Bitrix24 application code')
            ->expectsOutputToContain('Bitrix24 OAuth server URL')
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
                'code' => 'local.app.code',
            ],
            'oauth' => [
                'server_url' => 'https://oauth.example',
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

    public function test_command_fails_when_openlines_service_user_id_is_missing_for_live_export(): void
    {
        config()->set('bitrix24', array_replace_recursive(config('bitrix24'), [
            'features' => [
                'openlines_enabled' => true,
                'fake_happy_path_enabled' => false,
            ],
            'application' => [
                'client_id' => 'client-id',
                'client_secret' => 'client-secret',
                'code' => 'local.app.code',
            ],
            'oauth' => [
                'server_url' => 'https://oauth.example',
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
                'service_user_id' => null,
            ],
        ]));

        $this->artisan('bitrix24:setup-report')
            ->expectsOutputToContain('Open Lines service user ID')
            ->expectsOutputToContain('Set a positive BITRIX24_OPENLINES_SERVICE_USER_ID.')
            ->assertFailed();
    }

    public function test_command_allows_missing_openlines_service_user_id_for_fake_happy_path(): void
    {
        config()->set('bitrix24', array_replace_recursive(config('bitrix24'), [
            'features' => [
                'openlines_enabled' => true,
                'fake_happy_path_enabled' => true,
            ],
            'application' => [
                'client_id' => 'client-id',
                'client_secret' => 'client-secret',
                'code' => 'local.app.code',
            ],
            'oauth' => [
                'server_url' => 'https://oauth.example',
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
                'service_user_id' => null,
            ],
        ]));

        $this->artisan('bitrix24:setup-report')
            ->doesntExpectOutputToContain('Set a positive BITRIX24_OPENLINES_SERVICE_USER_ID.')
            ->assertSuccessful();
    }

    public function test_command_still_requires_openlines_service_user_id_in_production_even_if_fake_happy_path_is_enabled(): void
    {
        $this->runInEnvironment('production', function (): void {
            config()->set('bitrix24', array_replace_recursive(config('bitrix24'), [
                'features' => [
                    'openlines_enabled' => true,
                    'fake_happy_path_enabled' => true,
                ],
                'application' => [
                    'client_id' => 'client-id',
                    'client_secret' => 'client-secret',
                    'code' => 'local.app.code',
                ],
                'oauth' => [
                    'server_url' => 'https://oauth.example',
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
                    'service_user_id' => null,
                ],
            ]));

            $this->artisan('bitrix24:setup-report')
                ->expectsOutputToContain('Open Lines service user ID')
                ->expectsOutputToContain('Set a positive BITRIX24_OPENLINES_SERVICE_USER_ID.')
                ->expectsOutputToContain('Fake happy-path is ignored in production.')
                ->assertFailed();
        });
    }

    public function test_command_succeeds_when_setup_contract_is_fully_frozen(): void
    {
        config()->set('bitrix24', array_replace_recursive(config('bitrix24'), [
            'application' => [
                'client_id' => 'client-id',
                'client_secret' => 'client-secret',
                'code' => 'local.app.code',
            ],
            'oauth' => [
                'server_url' => 'https://oauth.example',
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
            ->expectsOutputToContain('session_finish_event_names')
            ->expectsOutputToContain('["OnSessionFinish"]')
            ->expectsOutputToContain('Bitrix24 setup is ready for the integration foundation stage.')
            ->assertSuccessful();
    }

    private function runInEnvironment(string $environment, Closure $callback): void
    {
        $original = app()->environment();
        $originalAppEnv = env('APP_ENV');

        putenv("APP_ENV={$environment}");
        $_ENV['APP_ENV'] = $environment;
        $_SERVER['APP_ENV'] = $environment;
        $this->app->detectEnvironment(fn (): string => $environment);

        try {
            $callback();
        } finally {
            if ($originalAppEnv === false || $originalAppEnv === null) {
                putenv('APP_ENV');
                unset($_ENV['APP_ENV'], $_SERVER['APP_ENV']);
            } else {
                putenv("APP_ENV={$originalAppEnv}");
                $_ENV['APP_ENV'] = $originalAppEnv;
                $_SERVER['APP_ENV'] = $originalAppEnv;
            }

            $this->app->detectEnvironment(fn (): string => $original);
        }
    }
}
