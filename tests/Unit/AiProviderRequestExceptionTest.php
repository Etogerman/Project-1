<?php

namespace Tests\Unit;

use App\Services\AI\AiProviderRequestException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class AiProviderRequestExceptionTest extends TestCase
{
    /**
     * @return iterable<string, array{int|null, bool}>
     */
    public static function temporaryStatusProvider(): iterable
    {
        yield 'rate limit' => [429, true];
        yield 'bad gateway' => [502, true];
        yield 'service unavailable' => [503, true];
        yield 'gateway timeout' => [504, true];
        yield 'internal server error' => [500, false];
        yield 'bad request' => [400, false];
    }

    #[DataProvider('temporaryStatusProvider')]
    public function test_temporary_http_statuses_are_classified_explicitly(?int $httpStatus, bool $expected): void
    {
        $exception = new AiProviderRequestException(
            message: 'provider error',
            provider: 'gemini',
            model: 'test-model',
            requestBodyRaw: '{}',
            responseBodyRaw: '{}',
            httpStatus: $httpStatus,
        );

        $this->assertSame($expected, $exception->isTemporary());
    }

    public function test_network_error_without_http_status_is_temporary(): void
    {
        $exception = new AiProviderRequestException(
            message: 'network error',
            provider: 'gemini',
            model: 'test-model',
            requestBodyRaw: '{}',
            responseBodyRaw: '',
            httpStatus: null,
            previous: new RuntimeException('connection reset'),
        );

        $this->assertTrue($exception->isTemporary());
    }
}
