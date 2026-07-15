<?php

namespace Tests\Unit;

use App\Services\Messages\InboundMediaDownloadPolicy;
use App\Services\Messages\InboundMediaQuotaExceededException;
use App\Services\Messages\InboundMediaStorageCapacity;
use App\Services\Messages\MediaDownloadIntegrityException;
use App\Services\Messages\StreamHttpResponseToTemporaryFileAction;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class StreamHttpResponseToTemporaryFileActionTest extends TestCase
{
    public function test_redirect_response_is_not_accepted_as_media(): void
    {
        $response = new Response(new PsrResponse(
            302,
            ['Location' => 'https://example.test/file'],
        ));

        $this->expectException(RuntimeException::class);

        app(StreamHttpResponseToTemporaryFileAction::class)->handle(
            response: $response,
            maxBytes: 100,
        );
    }

    public function test_integrity_failure_checkpoints_all_received_bytes(): void
    {
        $source = fopen('php://temp', 'w+b');
        $checkpoints = [];

        $this->assertIsResource($source);
        fwrite($source, 'truncated');
        rewind($source);

        try {
            app(StreamHttpResponseToTemporaryFileAction::class)->handleStream(
                source: $source,
                maxBytes: 100,
                expectedLength: 100,
                onProgress: function (int $receivedBytes) use (&$checkpoints): void {
                    $checkpoints[] = $receivedBytes;
                },
            );

            $this->fail('Expected media integrity validation to fail.');
        } catch (MediaDownloadIntegrityException) {
            $this->assertSame([strlen('truncated')], $checkpoints);
        } finally {
            fclose($source);
        }
    }

    public function test_limit_failure_checkpoints_the_over_limit_byte(): void
    {
        $source = fopen('php://temp', 'w+b');
        $checkpoints = [];

        $this->assertIsResource($source);
        fwrite($source, '123456');
        rewind($source);

        try {
            app(StreamHttpResponseToTemporaryFileAction::class)->handleStream(
                source: $source,
                maxBytes: 5,
                onProgress: function (int $receivedBytes) use (&$checkpoints): void {
                    $checkpoints[] = $receivedBytes;
                },
            );

            $this->fail('Expected media size limit validation to fail.');
        } catch (InvalidArgumentException) {
            $this->assertSame([6], $checkpoints);
        } finally {
            fclose($source);
        }
    }

    public function test_stream_is_buffered_inside_the_configured_media_directory(): void
    {
        $directory = storage_path('framework/testing/inbound-media-'.uniqid('', true));
        $source = fopen('php://temp', 'w+b');

        $this->assertIsResource($source);
        fwrite($source, 'controlled temporary storage');
        rewind($source);

        config([
            'inbound_media.temporary_directory' => $directory,
            'inbound_media.storage.minimum_free_bytes' => 0,
            'inbound_media.storage.minimum_free_percent' => 0,
        ]);

        try {
            $download = app(StreamHttpResponseToTemporaryFileAction::class)->handleStream(
                source: $source,
                maxBytes: 100,
            );
            $metadata = stream_get_meta_data($download->stream);
            $resolvedDirectory = realpath($directory);

            $this->assertIsString($resolvedDirectory);
            $this->assertStringStartsWith(
                $resolvedDirectory.DIRECTORY_SEPARATOR,
                $metadata['uri'],
            );
            $this->assertFileDoesNotExist($metadata['uri']);

            fclose($download->stream);
        } finally {
            fclose($source);

            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function test_streaming_stops_when_temporary_storage_reserve_is_exhausted(): void
    {
        $directory = storage_path('framework/testing/inbound-media-'.uniqid('', true));
        $payload = 'storage reserve exhausted';
        $source = fopen('php://temp', 'w+b');
        $capacity = Mockery::mock(InboundMediaStorageCapacity::class);

        $this->assertIsResource($source);
        fwrite($source, $payload);
        rewind($source);

        config(['inbound_media.temporary_directory' => $directory]);

        $capacity->shouldReceive('availableBytesForPath')
            ->once()
            ->with(Mockery::type('string'))
            ->andReturn(0);

        try {
            (new StreamHttpResponseToTemporaryFileAction($capacity))->handleStream(
                source: $source,
                maxBytes: 100,
            );

            $this->fail('Expected temporary storage capacity validation to fail.');
        } catch (InboundMediaQuotaExceededException $exception) {
            $this->assertSame(
                InboundMediaDownloadPolicy::REASON_STORAGE_QUOTA_EXCEEDED,
                $exception->reason,
            );
            $this->assertSame(strlen($payload), $exception->transferredBytes);
        } finally {
            fclose($source);

            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }
}
