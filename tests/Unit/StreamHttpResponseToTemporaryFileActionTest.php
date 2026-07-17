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
use Psr\Http\Message\StreamInterface;
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

    public function test_expected_length_stops_a_stream_that_does_not_report_eof(): void
    {
        $payload = 'complete';
        $source = Mockery::mock(StreamInterface::class);
        $source->shouldReceive('eof')->once()->andReturnFalse();
        $source->shouldReceive('read')->once()->with(strlen($payload) + 1)->andReturn($payload);

        $download = app(StreamHttpResponseToTemporaryFileAction::class)->handleStream(
            source: $source,
            maxBytes: 100,
            expectedLength: strlen($payload),
        );

        try {
            $this->assertSame($payload, stream_get_contents($download->stream));
            $this->assertSame(strlen($payload), $download->sizeBytes);
            $this->assertSame(strlen($payload), $download->expectedLengthBytes);
        } finally {
            fclose($download->stream);
        }
    }

    public function test_http_response_uses_expected_length_fallback_when_header_is_missing(): void
    {
        $payload = 'fallback';
        $response = new Response(new PsrResponse(200, [], $payload));

        $download = app(StreamHttpResponseToTemporaryFileAction::class)->handle(
            response: $response,
            maxBytes: 100,
            expectedLengthFallback: strlen($payload),
        );

        try {
            $this->assertSame($payload, stream_get_contents($download->stream));
            $this->assertSame(strlen($payload), $download->sizeBytes);
            $this->assertSame(strlen($payload), $download->expectedLengthBytes);
        } finally {
            fclose($download->stream);
        }
    }

    public function test_http_content_length_must_match_provider_fallback(): void
    {
        $payload = 'header';
        $response = new Response(new PsrResponse(
            200,
            ['Content-Length' => (string) strlen($payload)],
            $payload,
        ));

        $this->expectException(MediaDownloadIntegrityException::class);
        $this->expectExceptionMessage(
            'Downloaded media HTTP length does not match the provider-declared length.',
        );

        app(StreamHttpResponseToTemporaryFileAction::class)->handle(
            response: $response,
            maxBytes: 100,
            expectedLengthFallback: strlen($payload) + 20,
        );
    }

    public function test_matching_http_and_provider_lengths_are_accepted(): void
    {
        $payload = 'header';
        $response = new Response(new PsrResponse(
            200,
            ['Content-Length' => (string) strlen($payload)],
            $payload,
        ));

        $download = app(StreamHttpResponseToTemporaryFileAction::class)->handle(
            response: $response,
            maxBytes: 100,
            expectedLengthFallback: strlen($payload),
        );

        try {
            $this->assertSame($payload, stream_get_contents($download->stream));
            $this->assertSame(strlen($payload), $download->sizeBytes);
            $this->assertSame(strlen($payload), $download->expectedLengthBytes);
        } finally {
            fclose($download->stream);
        }
    }

    public function test_prepared_sink_is_validated_and_returned_without_second_copy(): void
    {
        $payload = 'prepared-sink';
        $response = new Response(new PsrResponse(
            200,
            [
                'Content-Type' => 'video/mp4',
                'Content-Length' => (string) strlen($payload),
            ],
        ));
        $action = app(StreamHttpResponseToTemporaryFileAction::class);
        $sinkData = $action->openTemporaryDownloadSink(strlen($payload));
        $sink = $sinkData['stream'];

        fwrite($sink, $payload);

        $download = $action->finalizeTemporaryDownloadSink(
            response: $response,
            sink: $sink,
            maxBytes: 100,
            expectedLengthFallback: strlen($payload),
            filenameHint: 'video.mp4',
        );

        try {
            $this->assertSame($sink, $download->stream);
            $this->assertSame($payload, stream_get_contents($download->stream));
            $this->assertSame(strlen($payload), $download->sizeBytes);
            $this->assertSame(strlen($payload), $download->expectedLengthBytes);
            $this->assertSame('video/mp4', $download->contentType);
            $this->assertSame('video.mp4', $download->filenameHint);
        } finally {
            fclose($download->stream);
        }
    }

    public function test_prepared_sink_integrity_failure_closes_partial_file(): void
    {
        $response = new Response(new PsrResponse(200, ['Content-Length' => '10']));
        $action = app(StreamHttpResponseToTemporaryFileAction::class);
        $sinkData = $action->openTemporaryDownloadSink(10);
        $sink = $sinkData['stream'];

        fwrite($sink, 'short');

        try {
            $action->finalizeTemporaryDownloadSink(
                response: $response,
                sink: $sink,
                maxBytes: 100,
                expectedLengthFallback: 10,
            );

            $this->fail('Expected prepared sink integrity validation to fail.');
        } catch (MediaDownloadIntegrityException) {
            $this->assertFalse(is_resource($sink));
        }
    }

    public function test_prepared_sink_checks_capacity_before_transfer(): void
    {
        $directory = storage_path('framework/testing/inbound-media-'.uniqid('', true));
        $capacity = Mockery::mock(InboundMediaStorageCapacity::class);

        config(['inbound_media.temporary_directory' => $directory]);
        $capacity->shouldReceive('availableBytesForPath')
            ->once()
            ->with(Mockery::type('string'))
            ->andReturn(9);

        try {
            (new StreamHttpResponseToTemporaryFileAction($capacity))
                ->openTemporaryDownloadSink(10);

            $this->fail('Expected prepared sink capacity validation to fail.');
        } catch (InboundMediaQuotaExceededException $exception) {
            $this->assertSame(
                InboundMediaDownloadPolicy::REASON_STORAGE_QUOTA_EXCEEDED,
                $exception->reason,
            );
            $this->assertSame(0, $exception->transferredBytes);
        } finally {
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function test_expected_length_rejects_an_overlong_stream(): void
    {
        $source = fopen('php://temp', 'w+b');

        $this->assertIsResource($source);
        fwrite($source, '123456');
        rewind($source);

        try {
            $this->expectException(MediaDownloadIntegrityException::class);

            app(StreamHttpResponseToTemporaryFileAction::class)->handleStream(
                source: $source,
                maxBytes: 100,
                expectedLength: 5,
            );
        } finally {
            fclose($source);
        }
    }

    public function test_expected_length_rejects_an_overlong_stream_at_chunk_boundary(): void
    {
        $expectedBytes = 64 * 1024;
        $source = fopen('php://temp', 'w+b');

        $this->assertIsResource($source);
        fwrite($source, str_repeat('a', $expectedBytes).'x');
        rewind($source);

        try {
            $this->expectException(MediaDownloadIntegrityException::class);

            app(StreamHttpResponseToTemporaryFileAction::class)->handleStream(
                source: $source,
                maxBytes: $expectedBytes + 10,
                expectedLength: $expectedBytes,
            );
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
