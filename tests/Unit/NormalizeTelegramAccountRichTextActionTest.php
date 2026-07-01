<?php

namespace Tests\Unit;

use App\Services\TelegramAccount\NormalizeTelegramAccountRichTextAction;
use Tests\TestCase;

class NormalizeTelegramAccountRichTextActionTest extends TestCase
{
    public function test_converts_tdlib_utf16_entities_to_ab_rich_text_runs(): void
    {
        $text = '😀 Жирный текст';

        $richText = app(NormalizeTelegramAccountRichTextAction::class)->handle($text, [
            'text' => $text,
            'entities' => [
                [
                    'offset' => 3,
                    'length' => 12,
                    'type' => ['_' => 'textEntityTypeBold'],
                ],
                [
                    'offset' => 9,
                    'length' => 6,
                    'type' => ['_' => 'textEntityTypeItalic'],
                ],
            ],
        ]);

        $this->assertSame([
            'version' => 1,
            'plain_text' => $text,
            'runs' => [
                [
                    'text' => '😀 ',
                    'marks' => [],
                ],
                [
                    'text' => 'Жирный',
                    'marks' => [['type' => 'bold']],
                ],
                [
                    'text' => ' текст',
                    'marks' => [
                        ['type' => 'bold'],
                        ['type' => 'italic'],
                    ],
                ],
            ],
        ], $richText);
    }

    public function test_returns_null_without_effective_entities(): void
    {
        $normalizer = app(NormalizeTelegramAccountRichTextAction::class);

        $this->assertNull($normalizer->handle('plain', null));
        $this->assertNull($normalizer->handle('plain', [
            'text' => 'plain',
            'entities' => [],
        ]));
        $this->assertNull($normalizer->handle('plain', [
            'text' => 'different',
            'entities' => [
                [
                    'offset' => 0,
                    'length' => 5,
                    'type' => ['_' => 'textEntityTypeBold'],
                ],
            ],
        ]));
    }
}
