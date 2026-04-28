<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class BitrixBoxOpenLinesRuntimeLoggingTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2).'/bitrix-box/abrikosoff-openlines/local/php_interface/include/abrikosoff_openlines/src/Runtime.php';
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
                'CONNECTOR' => 'abrikosoff_telegram',
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
            \Abrikosoff\BitrixBox\OpenLines\Runtime::class,
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
}
