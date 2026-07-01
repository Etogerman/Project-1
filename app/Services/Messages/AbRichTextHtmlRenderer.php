<?php

namespace App\Services\Messages;

class AbRichTextHtmlRenderer
{
    /**
     * @var list<string>
     */
    private const ALLOWED_LINK_SCHEMES = ['http', 'https', 'tg'];

    public function __construct(
        private readonly AbRichTextNormalizer $normalizer,
    ) {}

    public function render(mixed $richText): ?string
    {
        $normalized = $this->normalizer->normalize($richText);

        if ($normalized === null) {
            return null;
        }

        $html = '';

        foreach ($normalized['runs'] as $run) {
            $html .= $this->renderRun($run);
        }

        return $html !== ''
            ? $html
            : null;
    }

    /**
     * @param  array{text: string, marks: list<array<string, mixed>>}  $run
     */
    private function renderRun(array $run): string
    {
        $html = htmlspecialchars($run['text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        foreach (array_reverse($run['marks']) as $mark) {
            $html = $this->wrapHtml($html, $mark);
        }

        return $html;
    }

    /**
     * @param  array<string, mixed>  $mark
     */
    private function wrapHtml(string $html, array $mark): string
    {
        return match ($mark['type'] ?? null) {
            'bold' => '<strong>'.$html.'</strong>',
            'italic' => '<em>'.$html.'</em>',
            'underline' => '<u>'.$html.'</u>',
            'strikethrough' => '<s>'.$html.'</s>',
            'spoiler' => '<span class="ac-rich-text-spoiler">'.$html.'</span>',
            'code' => '<code>'.$html.'</code>',
            'pre' => $this->wrapPreHtml($html, $mark),
            'quote' => '<blockquote>'.$html.'</blockquote>',
            'link' => $this->wrapLinkHtml($html, $mark),
            default => $html,
        };
    }

    /**
     * @param  array<string, mixed>  $mark
     */
    private function wrapPreHtml(string $html, array $mark): string
    {
        $language = is_string($mark['language'] ?? null)
            ? trim($mark['language'])
            : '';

        if ($language === '') {
            return '<pre><code>'.$html.'</code></pre>';
        }

        $class = htmlspecialchars('language-'.$language, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<pre><code class="'.$class.'">'.$html.'</code></pre>';
    }

    /**
     * @param  array<string, mixed>  $mark
     */
    private function wrapLinkHtml(string $html, array $mark): string
    {
        $href = $this->sanitizeHref($mark['href'] ?? null);

        if ($href === null) {
            return $html;
        }

        return '<a href="'.$href.'" target="_blank" rel="noopener noreferrer">'.$html.'</a>';
    }

    private function sanitizeHref(mixed $href): ?string
    {
        if (! is_string($href)) {
            return null;
        }

        $href = trim($href);

        if ($href === '' || preg_match('/[\x00-\x1F\x7F]/u', $href) === 1) {
            return null;
        }

        $scheme = parse_url($href, PHP_URL_SCHEME);

        if (! is_string($scheme) || ! in_array(strtolower($scheme), self::ALLOWED_LINK_SCHEMES, true)) {
            return null;
        }

        return htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
