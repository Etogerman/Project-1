<?php

namespace Tests\Unit;

use App\Services\Messages\AbRichTextHtmlRenderer;
use App\Services\Messages\AbRichTextNormalizer;
use Tests\TestCase;

class AbRichTextHtmlRendererTest extends TestCase
{
    public function test_renders_typed_marks_and_escapes_text(): void
    {
        $html = app(AbRichTextHtmlRenderer::class)->render([
            'version' => 1,
            'plain_text' => 'Привет <b>мир</b>',
            'runs' => [
                [
                    'text' => 'Привет ',
                    'marks' => [['type' => 'bold']],
                ],
                [
                    'text' => '<b>мир</b>',
                    'marks' => [['type' => 'italic']],
                ],
            ],
        ]);

        $this->assertSame(
            '<strong>Привет </strong><em>&lt;b&gt;мир&lt;/b&gt;</em>',
            $html,
        );
    }

    public function test_renders_overlapping_provider_entities_as_pre_split_runs(): void
    {
        $html = app(AbRichTextHtmlRenderer::class)->render([
            'version' => 1,
            'plain_text' => 'abcde',
            'runs' => [
                ['text' => 'ab', 'marks' => [['type' => 'bold']]],
                ['text' => 'cd', 'marks' => [['type' => 'bold'], ['type' => 'italic']]],
                ['text' => 'e', 'marks' => [['type' => 'italic']]],
            ],
        ]);

        $this->assertSame('<strong>ab</strong><strong><em>cd</em></strong><em>e</em>', $html);
    }

    public function test_preserves_emoji_and_cyrillic_after_provider_offset_conversion(): void
    {
        $richText = [
            'version' => 1,
            'plain_text' => '😀 Жирный текст и ссылка',
            'runs' => [
                ['text' => '😀 ', 'marks' => []],
                ['text' => 'Жирный текст', 'marks' => [['type' => 'bold']]],
                [
                    'text' => ' и ссылка',
                    'marks' => [
                        ['type' => 'link', 'href' => 'https://example.com/search?q=тест&safe=1'],
                    ],
                ],
            ],
        ];

        $normalized = app(AbRichTextNormalizer::class)->normalize($richText);
        $html = app(AbRichTextHtmlRenderer::class)->render($richText);

        $this->assertSame($richText['plain_text'], collect($normalized['runs'])->pluck('text')->implode(''));
        $this->assertSame(
            '😀 <strong>Жирный текст</strong><a href="https://example.com/search?q=тест&amp;safe=1" target="_blank" rel="noopener noreferrer"> и ссылка</a>',
            $html,
        );
    }

    public function test_drops_unsafe_link_wrapper_but_keeps_safe_text(): void
    {
        $html = app(AbRichTextHtmlRenderer::class)->render([
            'version' => 1,
            'plain_text' => 'опасная ссылка',
            'runs' => [
                [
                    'text' => 'опасная ссылка',
                    'marks' => [
                        ['type' => 'link', 'href' => 'javascript:alert(1)'],
                    ],
                ],
            ],
        ]);

        $this->assertSame('опасная ссылка', $html);
    }

    public function test_renders_quote_mark_as_blockquote(): void
    {
        $html = app(AbRichTextHtmlRenderer::class)->render([
            'version' => 1,
            'plain_text' => 'Текст цитаты',
            'runs' => [
                [
                    'text' => 'Текст цитаты',
                    'marks' => [['type' => 'quote']],
                ],
            ],
        ]);

        $this->assertSame('<blockquote>Текст цитаты</blockquote>', $html);
    }

    public function test_renders_split_quote_runs_as_single_blockquote_with_nested_marks(): void
    {
        $html = app(AbRichTextHtmlRenderer::class)->render([
            'version' => 1,
            'plain_text' => 'Цитата жирная и ссылка после',
            'runs' => [
                [
                    'text' => 'Цитата ',
                    'marks' => [['type' => 'quote']],
                ],
                [
                    'text' => 'жирная',
                    'marks' => [['type' => 'quote'], ['type' => 'bold']],
                ],
                [
                    'text' => ' и ',
                    'marks' => [['type' => 'quote']],
                ],
                [
                    'text' => 'ссылка',
                    'marks' => [
                        ['type' => 'quote'],
                        ['type' => 'link', 'href' => 'https://example.test/path?q=1&v=2'],
                    ],
                ],
                [
                    'text' => ' после',
                    'marks' => [],
                ],
            ],
        ]);

        $this->assertSame(
            '<blockquote>Цитата <strong>жирная</strong> и <a href="https://example.test/path?q=1&amp;v=2" target="_blank" rel="noopener noreferrer">ссылка</a></blockquote> после',
            $html,
        );
    }

    public function test_rejects_legacy_string_marks_and_inconsistent_plain_text(): void
    {
        $normalizer = app(AbRichTextNormalizer::class);

        $this->assertNull($normalizer->normalize([
            'version' => 1,
            'plain_text' => 'текст',
            'runs' => [
                ['text' => 'текст', 'marks' => ['bold']],
            ],
        ]));

        $this->assertNull($normalizer->normalize([
            'version' => 1,
            'plain_text' => 'одно',
            'runs' => [
                ['text' => 'другое', 'marks' => []],
            ],
        ]));
    }
}
