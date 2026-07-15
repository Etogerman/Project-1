<?php

namespace Tests\Unit;

use App\Models\MessageAttachment;
use App\Services\Messages\MediaDownloadIntegrityException;
use App\Services\Messages\ValidateInboundMediaIntegrityAction;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ValidateInboundMediaIntegrityActionTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function providerErrorPayloads(): array
    {
        return [
            'html' => ['<!doctype html><html><body>upstream error</body></html>'],
            'json' => ['{"ok":false,"error":"file unavailable"}'],
        ];
    }

    #[DataProvider('providerErrorPayloads')]
    public function test_binary_document_rejects_textual_provider_error_payload(string $payload): void
    {
        $attachment = new MessageAttachment([
            'media_kind' => MessageAttachment::MEDIA_KIND_DOCUMENT,
        ]);

        $this->expectException(MediaDownloadIntegrityException::class);

        app(ValidateInboundMediaIntegrityAction::class)->inspectContents(
            attachment: $attachment,
            sample: $payload,
            actualSizeBytes: strlen($payload),
            declaredMimeType: 'application/pdf',
        );
    }

    public function test_text_document_accepts_json_contents_when_provider_declares_json(): void
    {
        $attachment = new MessageAttachment([
            'media_kind' => MessageAttachment::MEDIA_KIND_DOCUMENT,
        ]);
        $payload = '{"report":"ready"}';

        $detectedMimeType = app(ValidateInboundMediaIntegrityAction::class)->inspectContents(
            attachment: $attachment,
            sample: $payload,
            actualSizeBytes: strlen($payload),
            declaredMimeType: 'application/json',
        );

        $this->assertContains($detectedMimeType, ['application/json', 'text/plain']);
    }
}
