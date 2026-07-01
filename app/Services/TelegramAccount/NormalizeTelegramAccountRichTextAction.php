<?php

namespace App\Services\TelegramAccount;

use App\Services\Messages\AbRichTextNormalizer;

class NormalizeTelegramAccountRichTextAction
{
    public function __construct(
        private readonly AbRichTextNormalizer $abRichTextNormalizer,
    ) {}

    /**
     * @return array{version: 1, plain_text: string, runs: list<array{text: string, marks: list<array<string, mixed>>}>}|null
     */
    public function handle(?string $plainText, mixed $formattedText): ?array
    {
        if (! is_string($plainText) || $plainText === '' || ! is_array($formattedText)) {
            return null;
        }

        $sourceText = $formattedText['text'] ?? null;

        if (! is_string($sourceText) || $sourceText !== $plainText) {
            return null;
        }

        $entities = $formattedText['entities'] ?? null;

        if (! is_array($entities) || ! array_is_list($entities) || $entities === []) {
            return null;
        }

        $offsetMap = $this->buildUtf16OffsetMap($plainText);
        $textUtf16Length = array_key_last($offsetMap) ?? 0;
        $normalizedEntities = [];
        $boundaries = [0, $textUtf16Length];

        foreach ($entities as $entity) {
            $normalizedEntity = $this->normalizeEntity($entity, $plainText, $offsetMap, $textUtf16Length);

            if ($normalizedEntity === null) {
                continue;
            }

            $normalizedEntities[] = $normalizedEntity;
            $boundaries[] = $normalizedEntity['start'];
            $boundaries[] = $normalizedEntity['end'];
        }

        if ($normalizedEntities === []) {
            return null;
        }

        $boundaries = array_values(array_unique($boundaries));
        sort($boundaries, SORT_NUMERIC);

        $runs = [];
        $hasMarkedRun = false;

        for ($index = 0; $index < count($boundaries) - 1; $index++) {
            $start = $boundaries[$index];
            $end = $boundaries[$index + 1];

            if ($end <= $start) {
                continue;
            }

            if (! isset($offsetMap[$start], $offsetMap[$end])) {
                return null;
            }

            $text = substr($plainText, $offsetMap[$start], $offsetMap[$end] - $offsetMap[$start]);

            if ($text === '') {
                continue;
            }

            $marks = [];

            foreach ($normalizedEntities as $entity) {
                if ($entity['start'] <= $start && $entity['end'] >= $end) {
                    $marks[] = $entity['mark'];
                }
            }

            if ($marks !== []) {
                $hasMarkedRun = true;
            }

            $runs[] = [
                'text' => $text,
                'marks' => $marks,
            ];
        }

        if (! $hasMarkedRun) {
            return null;
        }

        return $this->abRichTextNormalizer->normalize([
            'version' => AbRichTextNormalizer::VERSION,
            'plain_text' => $plainText,
            'runs' => $runs,
        ]);
    }

    /**
     * @return array<int, int>
     */
    private function buildUtf16OffsetMap(string $text): array
    {
        $map = [0 => 0];
        $utf16Offset = 0;
        $byteOffset = 0;

        preg_match_all('/./us', $text, $matches);

        foreach ($matches[0] ?? [] as $character) {
            $byteOffset += strlen($character);
            $utf16Offset += intdiv(strlen(mb_convert_encoding($character, 'UTF-16LE', 'UTF-8')), 2);
            $map[$utf16Offset] = $byteOffset;
        }

        return $map;
    }

    /**
     * @param  array<int, int>  $offsetMap
     * @return array{start: int, end: int, mark: array<string, mixed>}|null
     */
    private function normalizeEntity(mixed $entity, string $plainText, array $offsetMap, int $textUtf16Length): ?array
    {
        if (! is_array($entity)) {
            return null;
        }

        $offset = $this->normalizeNonNegativeInt($entity['offset'] ?? null);
        $length = $this->normalizePositiveInt($entity['length'] ?? null);

        if ($offset === null || $length === null) {
            return null;
        }

        $end = $offset + $length;

        if ($offset >= $textUtf16Length || $end > $textUtf16Length || ! isset($offsetMap[$offset], $offsetMap[$end])) {
            return null;
        }

        $entityText = substr($plainText, $offsetMap[$offset], $offsetMap[$end] - $offsetMap[$offset]);
        $type = $this->resolveEntityType($entity);
        $mark = $this->resolveEntityMark($type, $entity, $entityText);

        if ($mark === null) {
            return null;
        }

        return [
            'start' => $offset,
            'end' => $end,
            'mark' => $mark,
        ];
    }

    private function normalizeNonNegativeInt(mixed $value): ?int
    {
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            return null;
        }

        $normalized = (int) $value;

        return $normalized >= 0 ? $normalized : null;
    }

    private function normalizePositiveInt(mixed $value): ?int
    {
        $normalized = $this->normalizeNonNegativeInt($value);

        return $normalized !== null && $normalized > 0 ? $normalized : null;
    }

    /**
     * @param  array<string, mixed>  $entity
     */
    private function resolveEntityType(array $entity): ?string
    {
        $type = data_get($entity, 'type._')
            ?? data_get($entity, 'type.@type')
            ?? data_get($entity, 'type');

        return is_string($type) ? $type : null;
    }

    /**
     * @param  array<string, mixed>  $entity
     * @return array<string, mixed>|null
     */
    private function resolveEntityMark(?string $type, array $entity, string $entityText): ?array
    {
        return match ($type) {
            'textEntityTypeBold' => ['type' => 'bold'],
            'bold' => ['type' => 'bold'],
            'textEntityTypeItalic' => ['type' => 'italic'],
            'italic' => ['type' => 'italic'],
            'textEntityTypeUnderline' => ['type' => 'underline'],
            'underline' => ['type' => 'underline'],
            'textEntityTypeStrikethrough' => ['type' => 'strikethrough'],
            'strikethrough' => ['type' => 'strikethrough'],
            'textEntityTypeSpoiler' => ['type' => 'spoiler'],
            'spoiler' => ['type' => 'spoiler'],
            'textEntityTypeCode' => ['type' => 'code'],
            'code' => ['type' => 'code'],
            'textEntityTypePre' => ['type' => 'pre'],
            'pre' => $this->resolvePreCodeMark($entity),
            'textEntityTypePreCode' => $this->resolvePreCodeMark($entity),
            'textEntityTypeBlockQuote' => ['type' => 'quote'],
            'textEntityTypeExpandableBlockQuote' => ['type' => 'quote'],
            'blockquote' => ['type' => 'quote'],
            'expandable_blockquote' => ['type' => 'quote'],
            'textEntityTypeTextUrl' => $this->resolveLinkMark(data_get($entity, 'type.url')),
            'text_link' => $this->resolveLinkMark(data_get($entity, 'url')),
            'textEntityTypeUrl' => $this->resolveLinkMark($entityText),
            'url' => $this->resolveLinkMark($entityText),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $entity
     * @return array{type: 'pre', language?: string}
     */
    private function resolvePreCodeMark(array $entity): array
    {
        $language = data_get($entity, 'type.language')
            ?? data_get($entity, 'language');

        if (! is_string($language) || trim($language) === '') {
            return ['type' => 'pre'];
        }

        return [
            'type' => 'pre',
            'language' => trim($language),
        ];
    }

    /**
     * @return array{type: 'link', href: string}|null
     */
    private function resolveLinkMark(mixed $href): ?array
    {
        if (! is_string($href) || trim($href) === '') {
            return null;
        }

        return [
            'type' => 'link',
            'href' => trim($href),
        ];
    }
}
