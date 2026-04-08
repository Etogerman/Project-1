<?php

namespace Tests\Feature;

use App\Data\Messages\PreparedMessageContentData;
use App\Models\Message;
use App\Services\Messages\PrepareMessageContentAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrepareMessageContentActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_keeps_plain_text_reply_without_extra_transformations(): void
    {
        $content = app(PrepareMessageContentAction::class)->handle(
            "  Простой ответ\nс новой строкой  ",
            Message::TEXT_FORMAT_PLAIN_TEXT,
        );

        $this->assertInstanceOf(PreparedMessageContentData::class, $content);
        $this->assertSame(Message::TEXT_FORMAT_PLAIN_TEXT, $content->textFormat);
        $this->assertSame("Простой ответ\nс новой строкой", $content->plainText);
        $this->assertNull($content->sourceText);
        $this->assertSame("Простой ответ\nс новой строкой", $content->transportText);
    }

    public function test_it_sanitizes_supported_html_and_builds_plain_text_fallback(): void
    {
        $content = app(PrepareMessageContentAction::class)->handle(
            '<div><b onclick="evil()">Привет</b> <script>alert(1)</script><a href="javascript:alert(2)">ссылка</a> <a href="https://example.com">пример</a></div>',
            Message::TEXT_FORMAT_HTML,
        );

        $this->assertSame(Message::TEXT_FORMAT_HTML, $content->textFormat);
        $this->assertNotNull($content->sourceText);
        $this->assertStringContainsString('<b>Привет</b>', $content->sourceText);
        $this->assertStringNotContainsString('<a>ссылка</a>', $content->sourceText);
        $this->assertStringNotContainsString('<a>пример</a>', $content->sourceText);
        $this->assertStringContainsString('<a href="https://example.com">пример</a>', $content->sourceText);
        $this->assertStringNotContainsString('script', $content->sourceText);
        $this->assertStringNotContainsString('onclick', $content->sourceText);
        $this->assertStringNotContainsString('javascript:', $content->sourceText);
        $this->assertSame('Привет ссылка пример', $content->plainText);
        $this->assertSame($content->sourceText, $content->transportText);
    }
}
