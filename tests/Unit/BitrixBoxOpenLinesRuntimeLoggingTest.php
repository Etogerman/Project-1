<?php

namespace Tests\Unit;

use Abrikosoff\BitrixBox\OpenLines\Runtime;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

class BitrixBoxOpenLinesRuntimeLoggingTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2).'/bitrix-box/abrikosoff-openlines/local/php_interface/include/abrikosoff_openlines/src/Runtime.php';
    }

    protected function tearDown(): void
    {
        $this->setRuntimeConfig(null);

        parent::tearDown();
    }

    public function test_openlines_callback_url_for_line_uses_owner_callback_base_url(): void
    {
        $this->setRuntimeConfig($this->runtimeConfig([
            '9' => [
                'line_name' => 'Local Telegram',
                'owner_profile_key' => 'dev-local',
                'owner_callback_base_url' => 'https://local-ngrok.example.test',
            ],
        ]));

        $this->assertSame(
            'https://local-ngrok.example.test/callbacks/bitrix24/openlines',
            $this->callbackUrlForLine('abc_telegram', '9'),
        );

        $lineInfo = Runtime::onInfoLine('9');

        $this->assertIsArray($lineInfo);
        $this->assertSame('https://local-ngrok.example.test/callbacks/bitrix24/openlines', $lineInfo['url']);
        $this->assertSame('https://local-ngrok.example.test/callbacks/bitrix24/openlines', $lineInfo['url_im']);
    }

    public function test_openlines_callback_url_for_line_falls_back_to_global_url(): void
    {
        $this->setRuntimeConfig($this->runtimeConfig([
            '9' => [
                'line_name' => 'Local Telegram without owner URL',
                'owner_profile_key' => 'dev-local',
            ],
        ]));

        $this->assertSame(
            'https://staging.example.test/callbacks/bitrix24/openlines',
            $this->callbackUrlForLine('abc_telegram', '9'),
        );
    }

    public function test_openlines_callback_url_for_line_does_not_duplicate_callback_path(): void
    {
        $this->setRuntimeConfig($this->runtimeConfig([
            '9' => [
                'line_name' => 'Local Telegram',
                'owner_profile_key' => 'dev-local',
                'owner_callback_base_url' => 'https://local-ngrok.example.test/callbacks/bitrix24/openlines',
            ],
        ]));

        $this->assertSame(
            'https://local-ngrok.example.test/callbacks/bitrix24/openlines',
            $this->callbackUrlForLine('abc_telegram', '9'),
        );
    }

    public function test_runtime_resolves_legacy_and_abc_connector_codes_by_component_line(): void
    {
        $config = $this->runtimeConfig([
            '9' => [
                'line_name' => 'ABC Telegram',
                'owner_profile_key' => 'staging',
                'owner_callback_base_url' => 'https://abc-ngrok.example.test',
            ],
        ]);
        $config['connectors'] = [
            'abrikosoff_telegram' => [
                'name' => 'Legacy Telegram',
                'component' => 'abrikosoff:imconnector.telegram',
                'line_id' => '2',
                'lines' => [
                    '2' => [
                        'line_name' => 'Legacy Telegram',
                        'owner_profile_key' => 'staging',
                        'owner_callback_base_url' => 'https://legacy-ngrok.example.test',
                    ],
                ],
            ],
        ] + $config['connectors'];

        $this->setRuntimeConfig($config);

        $this->assertSame(
            'abrikosoff_telegram',
            Runtime::connectorCodeForComponentLine('abrikosoff:imconnector.telegram', '2'),
        );
        $this->assertSame(
            'abc_telegram',
            Runtime::connectorCodeForComponentLine('abrikosoff:imconnector.telegram', '9'),
        );
        $this->assertSame('abrikosoff_telegram', Runtime::onBuildTelegramConnector()['ID'] ?? null);

        $lineInfo = Runtime::onInfoLine('2');

        $this->assertIsArray($lineInfo);
        $this->assertSame('abrikosoff_telegram', $lineInfo['connector_id']);
        $this->assertSame('https://legacy-ngrok.example.test/callbacks/bitrix24/openlines', $lineInfo['url']);
    }

    public function test_runtime_refuses_ambiguous_connector_line_ownership(): void
    {
        $config = $this->runtimeConfig([
            '9' => [
                'line_name' => 'ABC Telegram',
                'owner_profile_key' => 'staging',
                'owner_callback_base_url' => 'https://abc-ngrok.example.test',
            ],
        ]);
        $config['connectors'] = [
            'abrikosoff_telegram' => [
                'name' => 'Legacy Telegram',
                'component' => 'abrikosoff:imconnector.telegram',
                'line_id' => '9',
                'lines' => [
                    '9' => [
                        'line_name' => 'Legacy Telegram',
                        'owner_profile_key' => 'staging',
                        'owner_callback_base_url' => 'https://legacy-ngrok.example.test',
                    ],
                ],
            ],
        ] + $config['connectors'];

        $this->setRuntimeConfig($config);

        $this->assertNull(
            Runtime::connectorCodeForComponentLine('abrikosoff:imconnector.telegram', '9'),
        );
        $this->assertNull(Runtime::onInfoLine('9'));
    }

    public function test_callback_failure_log_message_does_not_expose_secrets_or_message_text(): void
    {
        $payload = [
            'event' => 'OnSendMessageCustom',
            'auth' => [
                'member_id' => 'member-secret-123',
                'application_token' => 'application-token-secret-456',
            ],
            'data' => [
                'CONNECTOR' => 'abc_telegram',
                'LINE' => '32',
                'DATA' => [[
                    'message' => [
                        'text' => 'Private customer message text',
                    ],
                    'client' => [
                        'phone' => '+79990001122',
                    ],
                ]],
            ],
        ];
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertIsString($body);

        $message = $this->callbackFailureLogMessage($payload, $body);

        $this->assertStringContainsString('status=500', $message);
        $this->assertStringContainsString('url_host=laravel.example.test', $message);
        $this->assertStringContainsString('url_path=/callbacks/bitrix24/openlines', $message);
        $this->assertStringContainsString('event=OnSendMessageCustom', $message);
        $this->assertStringContainsString('body_size='.(string) strlen($body), $message);
        $this->assertStringContainsString('body_sha256='.hash('sha256', $body), $message);

        $this->assertStringNotContainsString('body={', $message);
        $this->assertStringNotContainsString('query-secret', $message);
        $this->assertStringNotContainsString('application-token-secret-456', $message);
        $this->assertStringNotContainsString('application_token', $message);
        $this->assertStringNotContainsString('member-secret-123', $message);
        $this->assertStringNotContainsString('member_id', $message);
        $this->assertStringNotContainsString('Private customer message text', $message);
        $this->assertStringNotContainsString('+79990001122', $message);
        $this->assertStringNotContainsString("\n", $message);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function callbackFailureLogMessage(array $payload, string $body): string
    {
        $method = new ReflectionMethod(
            Runtime::class,
            'openLinesCallbackFailureLogMessage',
        );

        return (string) $method->invoke(
            null,
            'https://laravel.example.test/callbacks/bitrix24/openlines?token=query-secret',
            $payload,
            $body,
            500,
            "curl failed\nwith newline",
        );
    }

    /**
     * @param  array<string, mixed>|null  $config
     */
    private function setRuntimeConfig(?array $config): void
    {
        $property = new ReflectionProperty(
            Runtime::class,
            'config',
        );

        $property->setValue(null, $config);
    }

    /**
     * @param  array<string, array<string, string>>  $telegramLines
     * @return array<string, mixed>
     */
    private function runtimeConfig(array $telegramLines): array
    {
        return [
            'laravel' => [
                'openlines_callback_url' => 'https://staging.example.test/callbacks/bitrix24/openlines',
            ],
            'auth' => [
                'portal_domain' => 'stagecrm.fvds.ru',
                'member_id' => 'member-id',
                'application_token' => 'application-token',
            ],
            'connectors' => [
                'abc_telegram' => [
                    'name' => 'ABC Telegram',
                    'component' => 'abrikosoff:imconnector.telegram',
                    'line_id' => '9',
                    'lines' => $telegramLines + [
                        '9' => [
                            'line_name' => 'Staging Telegram',
                            'owner_profile_key' => 'staging',
                            'owner_callback_base_url' => 'https://staging.example.test',
                        ],
                    ],
                ],
                'abc_max' => [
                    'name' => 'ABC MAX',
                    'component' => 'abrikosoff:imconnector.max',
                    'line_id' => '3',
                    'lines' => [
                        '3' => [
                            'line_name' => 'Staging MAX',
                            'owner_profile_key' => 'staging',
                            'owner_callback_base_url' => 'https://staging.example.test',
                        ],
                    ],
                ],
            ],
            'crm_rebinding' => [
                'enabled' => false,
                'log_payload' => false,
                'log_file' => sys_get_temp_dir().'/bitrix-box-openlines-runtime-test.log',
            ],
        ];
    }

    private function callbackUrlForLine(string $connectorCode, string $lineId): string
    {
        $method = new ReflectionMethod(
            Runtime::class,
            'laravelOpenlinesCallbackUrlForLine',
        );

        return (string) $method->invoke(null, $connectorCode, $lineId);
    }
}
