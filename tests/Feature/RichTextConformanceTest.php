<?php

namespace Tests\Feature;

use App\Data\Bots\IncomingBotMessage;
use App\Models\Channel;
use App\Services\Bots\BotIncomingMessageNormalizer;
use App\Services\Messages\AbRichTextHtmlRenderer;
use App\Services\TelegramAccount\NormalizeTelegramAccountRichTextAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Conformance-сьют форматирования: фиксирует матрицу «официальная спека × код»
 * (см. ab-connector-local-drafts/formatting-conformance-matrix-20260703.md).
 *
 * Контракты:
 * 1) Каждый поддерживаемый тип каждого канала мапится в ожидаемый mark.
 * 2) Каждый неподдерживаемый тип деградирует ДО plain БЕЗ потери текста.
 * 3) Каждый mark рендерится в ожидаемый HTML.
 *
 * Если тест из этого файла упал после рефакторинга маппера/рендерера — клетка
 * матрицы изменилась; обновляйте матрицу осознанно, а не молча.
 */
class RichTextConformanceTest extends TestCase
{
    use RefreshDatabase;

    // ── Telegram (Bot API + TDLib идут через один нормализатор) ──────────────

    /**
     * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
     */
    public static function telegramMappedEntities(): array
    {
        return [
            'bold (Bot API)' => [['type' => 'bold'], ['type' => 'bold']],
            'bold (TDLib)' => [['type' => ['_' => 'textEntityTypeBold']], ['type' => 'bold']],
            'italic (Bot API)' => [['type' => 'italic'], ['type' => 'italic']],
            'italic (TDLib)' => [['type' => ['_' => 'textEntityTypeItalic']], ['type' => 'italic']],
            'underline (Bot API)' => [['type' => 'underline'], ['type' => 'underline']],
            'underline (TDLib)' => [['type' => ['_' => 'textEntityTypeUnderline']], ['type' => 'underline']],
            'strikethrough (Bot API)' => [['type' => 'strikethrough'], ['type' => 'strikethrough']],
            'strikethrough (TDLib)' => [['type' => ['_' => 'textEntityTypeStrikethrough']], ['type' => 'strikethrough']],
            'spoiler (Bot API)' => [['type' => 'spoiler'], ['type' => 'spoiler']],
            'spoiler (TDLib)' => [['type' => ['_' => 'textEntityTypeSpoiler']], ['type' => 'spoiler']],
            'code (Bot API)' => [['type' => 'code'], ['type' => 'code']],
            'code (TDLib)' => [['type' => ['_' => 'textEntityTypeCode']], ['type' => 'code']],
            'pre (TDLib, без языка)' => [['type' => ['_' => 'textEntityTypePre']], ['type' => 'pre']],
            'pre+language (Bot API)' => [['type' => 'pre', 'language' => 'php'], ['type' => 'pre', 'language' => 'php']],
            'pre_code+language (TDLib)' => [['type' => ['_' => 'textEntityTypePreCode', 'language' => 'php']], ['type' => 'pre', 'language' => 'php']],
            'blockquote (Bot API)' => [['type' => 'blockquote'], ['type' => 'quote']],
            'blockquote (TDLib)' => [['type' => ['_' => 'textEntityTypeBlockQuote']], ['type' => 'quote']],
            'expandable_blockquote (Bot API)' => [['type' => 'expandable_blockquote'], ['type' => 'quote']],
            'expandable_blockquote (TDLib)' => [['type' => ['_' => 'textEntityTypeExpandableBlockQuote']], ['type' => 'quote']],
            'text_link (Bot API)' => [['type' => 'text_link', 'url' => 'https://example.test/a'], ['type' => 'link', 'href' => 'https://example.test/a']],
            'text_link (TDLib TextUrl)' => [['type' => ['_' => 'textEntityTypeTextUrl', 'url' => 'https://example.test/b']], ['type' => 'link', 'href' => 'https://example.test/b']],
        ];
    }

    /**
     * @param  array<string, mixed>  $entityExtra
     * @param  array<string, mixed>  $expectedMark
     */
    #[DataProvider('telegramMappedEntities')]
    public function test_telegram_entity_maps_to_expected_mark(array $entityExtra, array $expectedMark): void
    {
        $text = 'prefix target suffix';
        $entity = array_merge(['offset' => 7, 'length' => 6], $entityExtra);

        $richText = app(NormalizeTelegramAccountRichTextAction::class)->handle($text, [
            'text' => $text,
            'entities' => [$entity],
        ]);

        $this->assertNotNull($richText, 'Тип должен приниматься (клетка full в матрице)');
        $this->assertSame($text, $richText['plain_text']);

        $targetMarks = collect($richText['runs'])
            ->firstWhere('text', 'target')['marks'] ?? [];
        $this->assertContainsEquals($expectedMark, $targetMarks);
    }

    public function test_telegram_auto_url_maps_to_link_with_text_href(): void
    {
        $text = 'см https://example.test/x тут';
        $offset = 3;
        $length = mb_strlen('https://example.test/x');

        foreach (['url', ['_' => 'textEntityTypeUrl']] as $type) {
            $richText = app(NormalizeTelegramAccountRichTextAction::class)->handle($text, [
                'text' => $text,
                'entities' => [['type' => $type, 'offset' => $offset, 'length' => $length]],
            ]);

            $this->assertNotNull($richText);
            $marks = collect($richText['runs'])->firstWhere('text', 'https://example.test/x')['marks'] ?? [];
            $this->assertContainsEquals(['type' => 'link', 'href' => 'https://example.test/x'], $marks);
        }
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function telegramDroppedEntities(): array
    {
        return [
            'mention (Bot API)' => ['mention'],
            'mention (TDLib)' => [['_' => 'textEntityTypeMention']],
            'text_mention (Bot API)' => ['text_mention'],
            'mention_name (TDLib)' => [['_' => 'textEntityTypeMentionName', 'user_id' => 123]],
            'custom_emoji (Bot API)' => ['custom_emoji'],
            'custom_emoji (TDLib)' => [['_' => 'textEntityTypeCustomEmoji']],
            'hashtag' => ['hashtag'],
            'cashtag' => ['cashtag'],
            'bot_command' => ['bot_command'],
            'email (Bot API)' => ['email'],
            'email (TDLib)' => [['_' => 'textEntityTypeEmailAddress']],
            'phone_number' => ['phone_number'],
            'bank_card (TDLib)' => [['_' => 'textEntityTypeBankCardNumber']],
            'media_timestamp (TDLib)' => [['_' => 'textEntityTypeMediaTimestamp', 'media_timestamp' => 83]],
            'date_time (Bot API 9.x)' => ['date_time'],
            'неизвестный будущий тип' => ['whatever_new_type'],
        ];
    }

    #[DataProvider('telegramDroppedEntities')]
    public function test_telegram_unsupported_entity_degrades_without_text_loss(mixed $type): void
    {
        $text = 'prefix target suffix';

        $richText = app(NormalizeTelegramAccountRichTextAction::class)->handle($text, [
            'text' => $text,
            'entities' => [['type' => $type, 'offset' => 7, 'length' => 6]],
        ]);

        // Контракт деградации: тип отброшен ЦЕЛИКОМ (rich_text = null),
        // plain-текст живёт отдельно в messages.text и не затрагивается.
        $this->assertNull($richText, 'Неподдерживаемый тип должен деградировать до plain (клетка degrades в матрице)');
    }

    // ── MAX (markup → пре-конверсия в entities → общий конвейер) ─────────────

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function maxMappedMarkup(): array
    {
        return [
            'strong → bold' => ['strong', ['type' => 'bold']],
            'emphasized → italic' => ['emphasized', ['type' => 'italic']],
            'underline' => ['underline', ['type' => 'underline']],
            'strikethrough' => ['strikethrough', ['type' => 'strikethrough']],
            'monospaced (инлайн) → code' => ['monospaced', ['type' => 'code']],
            'highlighted → highlight' => ['highlighted', ['type' => 'highlight']],
            'heading' => ['heading', ['type' => 'heading']],
            'list' => ['list', ['type' => 'list']],
        ];
    }

    /**
     * @param  array<string, mixed>  $expectedMark
     */
    #[DataProvider('maxMappedMarkup')]
    public function test_max_markup_maps_to_expected_mark(string $markupType, array $expectedMark): void
    {
        $message = $this->normalizeMaxWithMarkup([
            'type' => $markupType,
            'from' => 7,
            'length' => 6,
        ]);

        $this->assertNotNull($message->richText, 'MAX-тип должен приниматься (клетка full)');
        $targetMarks = collect($message->richText['runs'])
            ->firstWhere('text', 'target')['marks'] ?? [];
        $this->assertContainsEquals($expectedMark, $targetMarks);
    }

    public function test_max_quote_markup_maps_to_quote_mark(): void
    {
        $message = $this->normalizeMaxWithMarkup([
            'type' => 'quote',
            'from' => 7,
            'length' => 6,
        ]);

        $this->assertNotNull($message->richText);
        $targetMarks = collect($message->richText['runs'])->firstWhere('text', 'target')['marks'] ?? [];
        $this->assertContainsEquals(['type' => 'quote'], $targetMarks);
    }

    public function test_max_link_markup_maps_to_link_with_href(): void
    {
        $message = $this->normalizeMaxWithMarkup([
            'type' => 'link',
            'from' => 7,
            'length' => 6,
            'url' => 'https://example.test/max',
        ]);

        $this->assertNotNull($message->richText);
        $targetMarks = collect($message->richText['runs'])->firstWhere('text', 'target')['marks'] ?? [];
        $this->assertContainsEquals(['type' => 'link', 'href' => 'https://example.test/max'], $targetMarks);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function maxDroppedMarkup(): array
    {
        return [
            'user_mention' => [['type' => 'user_mention', 'from' => 7, 'length' => 6, 'user_id' => 123]],
            'неизвестный будущий тип' => [['type' => 'future_markup', 'from' => 7, 'length' => 6]],
        ];
    }

    /**
     * @param  array<string, mixed>  $markup
     */
    #[DataProvider('maxDroppedMarkup')]
    public function test_max_unsupported_markup_degrades_without_text_loss(array $markup): void
    {
        $message = $this->normalizeMaxWithMarkup($markup);

        $this->assertNull($message->richText, 'Неподдерживаемый MAX-тип должен деградировать до plain');
        $this->assertSame('prefix target suffix', $message->text);
    }

    // ── Рендерер: mark → HTML ─────────────────────────────────────────────────

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function rendererMarks(): array
    {
        return [
            'bold → <strong>' => [['type' => 'bold'], '<strong>x</strong>'],
            'italic → <em>' => [['type' => 'italic'], '<em>x</em>'],
            'underline → <u>' => [['type' => 'underline'], '<u>x</u>'],
            'strikethrough → <s>' => [['type' => 'strikethrough'], '<s>x</s>'],
            'code → <code>' => [['type' => 'code'], '<code'],
            'pre → <pre>' => [['type' => 'pre'], '<pre'],
            'pre+language → class' => [['type' => 'pre', 'language' => 'php'], 'language-php'],
            'quote → <blockquote>' => [['type' => 'quote'], '<blockquote'],
            'spoiler → класс' => [['type' => 'spoiler'], 'ac-rich-text-spoiler'],
            'heading → класс' => [['type' => 'heading'], 'ac-rich-text-heading'],
            'highlight → <mark>' => [['type' => 'highlight'], 'ac-rich-text-highlight'],
            'list → класс' => [['type' => 'list'], 'ac-rich-text-list'],
        ];
    }

    /**
     * @param  array<string, mixed>  $mark
     */
    #[DataProvider('rendererMarks')]
    public function test_renderer_produces_expected_html_for_mark(array $mark, string $expectedFragment): void
    {
        $html = app(AbRichTextHtmlRenderer::class)->render([
            'version' => 1,
            'plain_text' => 'x',
            'runs' => [['text' => 'x', 'marks' => [$mark]]],
        ]);

        $this->assertNotNull($html);
        $this->assertStringContainsString($expectedFragment, $html);
    }

    public function test_renderer_link_has_safe_attributes(): void
    {
        $html = app(AbRichTextHtmlRenderer::class)->render([
            'version' => 1,
            'plain_text' => 'x',
            'runs' => [['text' => 'x', 'marks' => [['type' => 'link', 'href' => 'https://example.test/a']]]],
        ]);

        $this->assertNotNull($html);
        $this->assertStringContainsString('href="https://example.test/a"', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
    }

    // ── Хелперы ───────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $markup
     */
    private function normalizeMaxWithMarkup(array $markup): IncomingBotMessage
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
        ]);

        $message = app(BotIncomingMessageNormalizer::class)->normalize($channel, [
            'update_type' => 'message_created',
            'user_locale' => 'ru',
            'message' => [
                'sender' => ['user_id' => 505, 'username' => 'max_user', 'is_bot' => false],
                'recipient' => ['chat_id' => 705],
                'body' => [
                    'mid' => 'max-conformance-'.md5(json_encode($markup)),
                    'text' => 'prefix target suffix',
                    'markup' => [$markup],
                ],
            ],
        ]);

        $this->assertInstanceOf(IncomingBotMessage::class, $message);

        return $message;
    }
}
