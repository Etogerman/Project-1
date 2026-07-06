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
        $quoteHtml = '';

        foreach ($normalized['runs'] as $run) {
            if ($this->hasMark($run, 'quote')) {
                $quoteHtml .= $this->renderRun($this->withoutMark($run, 'quote'));

                continue;
            }

            $html .= $this->flushQuoteHtml($quoteHtml);
            $quoteHtml = '';
            $html .= $this->renderRun($run);
        }

        $html .= $this->flushQuoteHtml($quoteHtml);

        $html = $this->compactBlockBoundaryNewlines($html);

        return $html !== ''
            ? $html
            : null;
    }

    private function compactBlockBoundaryNewlines(string $html): string
    {
        $html = preg_replace('/\n(<(?:blockquote|pre)\b)/u', '$1', $html) ?? $html;

        return preg_replace('/(<\/(?:blockquote|pre)>)\n/u', '$1', $html) ?? $html;
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

    private function flushQuoteHtml(string $html): string
    {
        return $html !== ''
            ? '<blockquote>'.$html.'</blockquote>'
            : '';
    }

    /**
     * @param  array{text: string, marks: list<array<string, mixed>>}  $run
     */
    private function hasMark(array $run, string $type): bool
    {
        foreach ($run['marks'] as $mark) {
            if (($mark['type'] ?? null) === $type) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{text: string, marks: list<array<string, mixed>>}  $run
     * @return array{text: string, marks: list<array<string, mixed>>}
     */
    private function withoutMark(array $run, string $type): array
    {
        $run['marks'] = array_values(array_filter(
            $run['marks'],
            fn (array $mark): bool => ($mark['type'] ?? null) !== $type,
        ));

        return $run;
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
            'heading' => '<strong class="ac-rich-text-heading">'.$html.'</strong>',
            'highlight' => '<mark class="ac-rich-text-highlight">'.$html.'</mark>',
            'list' => '<span class="ac-rich-text-list">'.$html.'</span>',
            'link' => $this->wrapLinkHtml($html, $mark),
            'mention' => $this->wrapMentionHtml($html, $mark),
            default => $html,
        };
    }

    /**
     * @param  array<string, mixed>  $mark
     */
    private function wrapMentionHtml(string $html, array $mark): string
    {
        $attributes = '';

        foreach (['user_id' => 'data-user-id', 'username' => 'data-username'] as $key => $attribute) {
            $value = $mark[$key] ?? null;

            if (is_string($value) && $value !== '') {
                $attributes .= ' '.$attribute.'="'
                    .htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"';
            }
        }

        return '<span class="ac-rich-text-mention"'.$attributes.'>'.$html.'</span>';
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
