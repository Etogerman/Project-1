<?php

namespace App\Services\Messages;

use App\Data\Messages\PreparedMessageContentData;
use App\Models\Message;
use InvalidArgumentException;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerAction;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

class PrepareMessageContentAction
{
    private readonly HtmlSanitizer $htmlSanitizer;

    public function __construct()
    {
        $config = (new HtmlSanitizerConfig())
            ->defaultAction(HtmlSanitizerAction::Block)
            ->allowLinkSchemes(['http', 'https'])
            ->allowElement('a', ['href'])
            ->allowElement('b')
            ->allowElement('strong')
            ->allowElement('i')
            ->allowElement('em')
            ->allowElement('u')
            ->allowElement('ins')
            ->allowElement('s')
            ->allowElement('del')
            ->allowElement('code')
            ->allowElement('pre')
            ->dropElement('script')
            ->dropElement('style')
            ->dropElement('iframe')
            ->dropElement('object')
            ->dropElement('embed')
            ->dropElement('svg')
            ->dropElement('math')
            ->dropElement('form')
            ->dropElement('input')
            ->dropElement('button')
            ->dropElement('textarea')
            ->dropElement('select')
            ->dropElement('option')
            ->dropElement('template')
            ->dropElement('noscript')
            ->dropElement('meta')
            ->dropElement('link')
            ->dropElement('base');

        $this->htmlSanitizer = new HtmlSanitizer($config);
    }

    public function handle(string $sourceText, string $textFormat): PreparedMessageContentData
    {
        $normalizedTextFormat = Message::normalizeTextFormat($textFormat);
        $normalizedSourceText = $this->normalizeInput($sourceText);

        if ($normalizedSourceText === '') {
            throw new InvalidArgumentException('Введите текст ответа.');
        }

        if ($normalizedTextFormat === Message::TEXT_FORMAT_HTML) {
            return $this->prepareHtmlContent($normalizedSourceText);
        }

        return new PreparedMessageContentData(
            textFormat: Message::TEXT_FORMAT_PLAIN_TEXT,
            plainText: $normalizedSourceText,
            sourceText: null,
            transportText: $normalizedSourceText,
        );
    }

    public function sanitizeHtml(string $sourceText): string
    {
        $normalizedSourceText = $this->normalizeInput($sourceText);

        if ($normalizedSourceText === '') {
            return '';
        }

        $sanitizedHtml = trim($this->htmlSanitizer->sanitize($normalizedSourceText));

        if ($sanitizedHtml === '') {
            return '';
        }

        return $this->unwrapAnchorsWithoutHref($sanitizedHtml);
    }

    private function prepareHtmlContent(string $sourceText): PreparedMessageContentData
    {
        $sanitizedHtml = $this->sanitizeHtml($sourceText);

        if ($sanitizedHtml === '') {
            throw new InvalidArgumentException('HTML-сообщение не содержит поддерживаемого текста.');
        }

        $plainText = $this->buildPlainTextFallback($sanitizedHtml);

        if ($plainText === '') {
            throw new InvalidArgumentException('HTML-сообщение не содержит поддерживаемого текста.');
        }

        return new PreparedMessageContentData(
            textFormat: Message::TEXT_FORMAT_HTML,
            plainText: $plainText,
            sourceText: $sanitizedHtml,
            transportText: $sanitizedHtml,
        );
    }

    private function normalizeInput(string $value): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $value);

        return trim($normalized);
    }

    private function buildPlainTextFallback(string $sanitizedHtml): string
    {
        $plainText = preg_replace('/<\/pre>/iu', "</pre>\n", $sanitizedHtml) ?? $sanitizedHtml;
        $plainText = strip_tags($plainText);
        $plainText = html_entity_decode($plainText, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plainText = str_replace("\xc2\xa0", ' ', $plainText);
        $plainText = preg_replace("/[ \t]+\n/u", "\n", $plainText) ?? $plainText;
        $plainText = preg_replace("/\n{3,}/u", "\n\n", $plainText) ?? $plainText;

        return trim($plainText);
    }

    private function unwrapAnchorsWithoutHref(string $sanitizedHtml): string
    {
        return preg_replace('/<a>(.*?)<\/a>/isu', '$1', $sanitizedHtml) ?? $sanitizedHtml;
    }
}
