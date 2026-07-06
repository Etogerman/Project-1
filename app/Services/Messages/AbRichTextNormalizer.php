<?php

namespace App\Services\Messages;

class AbRichTextNormalizer
{
    public const VERSION = 1;

    /**
     * Lower number means outer HTML wrapper.
     *
     * @var array<string, int>
     */
    private const MARK_PRIORITY = [
        'quote' => 10,
        'pre' => 20,
        'link' => 30,
        'mention' => 33,
        'heading' => 35,
        'bold' => 40,
        'italic' => 50,
        'underline' => 60,
        'strikethrough' => 70,
        'spoiler' => 80,
        'highlight' => 85,
        'code' => 90,
        'list' => 95,
    ];

    /**
     * @return array{version: 1, plain_text: string, runs: list<array{text: string, marks: list<array<string, mixed>>}>}|null
     */
    public function normalize(mixed $richText): ?array
    {
        if (! is_array($richText)) {
            return null;
        }

        if (($richText['version'] ?? null) !== self::VERSION) {
            return null;
        }

        if (! array_key_exists('plain_text', $richText) || ! is_string($richText['plain_text'])) {
            return null;
        }

        $runs = $richText['runs'] ?? null;

        if (! is_array($runs) || ! array_is_list($runs)) {
            return null;
        }

        $normalizedRuns = [];
        $plainTextFromRuns = '';

        foreach ($runs as $run) {
            if (! is_array($run) || ! array_key_exists('text', $run) || ! is_string($run['text'])) {
                return null;
            }

            $text = $run['text'];

            if ($text === '') {
                continue;
            }

            $marks = $this->normalizeMarks($run['marks'] ?? []);

            if ($marks === null) {
                return null;
            }

            $plainTextFromRuns .= $text;
            $normalizedRuns[] = [
                'text' => $text,
                'marks' => $marks,
            ];
        }

        if ($plainTextFromRuns !== $richText['plain_text']) {
            return null;
        }

        return [
            'version' => self::VERSION,
            'plain_text' => $richText['plain_text'],
            'runs' => $this->mergeAdjacentRuns($normalizedRuns),
        ];
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function normalizeMarks(mixed $marks): ?array
    {
        if (! is_array($marks) || ! array_is_list($marks)) {
            return null;
        }

        $normalizedMarks = [];
        $seen = [];

        foreach ($marks as $mark) {
            if (! is_array($mark) || ! is_string($mark['type'] ?? null)) {
                return null;
            }

            $type = trim($mark['type']);

            if (! array_key_exists($type, self::MARK_PRIORITY)) {
                return null;
            }

            // Невалидный mention удаляется ТОЧЕЧНО (contract v1.1): текст и
            // остальные marks сохраняются, rich_text не бракуется целиком.
            if ($type === 'mention') {
                $normalizedMark = $this->normalizeMentionMark($mark);

                if ($normalizedMark === null) {
                    continue;
                }
            } else {
                $normalizedMark = match ($type) {
                    'link' => $this->normalizeLinkMark($mark),
                    'pre' => $this->normalizePreMark($mark),
                    default => ['type' => $type],
                };

                if ($normalizedMark === null) {
                    return null;
                }
            }

            $key = $this->markKey($normalizedMark);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $normalizedMarks[] = $normalizedMark;
        }

        usort($normalizedMarks, function (array $left, array $right): int {
            $leftType = (string) $left['type'];
            $rightType = (string) $right['type'];
            $byPriority = self::MARK_PRIORITY[$leftType] <=> self::MARK_PRIORITY[$rightType];

            return $byPriority !== 0
                ? $byPriority
                : $this->markKey($left) <=> $this->markKey($right);
        });

        return $normalizedMarks;
    }

    /**
     * @param  array<string, mixed>  $mark
     * @return array{type: 'mention', username?: string, user_id?: string}|null
     */
    private function normalizeMentionMark(array $mark): ?array
    {
        $username = $mark['username'] ?? null;
        $username = is_string($username) ? ltrim(trim($username), '@') : '';

        $userId = $mark['user_id'] ?? null;
        $userId = is_string($userId) || is_int($userId) ? trim((string) $userId) : '';

        if ($username === '' && $userId === '') {
            return null;
        }

        $normalized = ['type' => 'mention'];

        if ($username !== '') {
            $normalized['username'] = $username;
        }

        if ($userId !== '') {
            $normalized['user_id'] = $userId;
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $mark
     * @return array{type: 'link', href: string}|null
     */
    private function normalizeLinkMark(array $mark): ?array
    {
        if (! is_string($mark['href'] ?? null)) {
            return null;
        }

        $href = trim($mark['href']);

        return $href !== ''
            ? ['type' => 'link', 'href' => $href]
            : null;
    }

    /**
     * @param  array<string, mixed>  $mark
     * @return array{type: 'pre', language?: string}|null
     */
    private function normalizePreMark(array $mark): ?array
    {
        if (! array_key_exists('language', $mark)) {
            return ['type' => 'pre'];
        }

        if (! is_string($mark['language'])) {
            return null;
        }

        $language = trim($mark['language']);

        return $language !== ''
            ? ['type' => 'pre', 'language' => $language]
            : ['type' => 'pre'];
    }

    /**
     * @param  list<array{text: string, marks: list<array<string, mixed>>}>  $runs
     * @return list<array{text: string, marks: list<array<string, mixed>>}>
     */
    private function mergeAdjacentRuns(array $runs): array
    {
        $merged = [];

        foreach ($runs as $run) {
            $lastIndex = array_key_last($merged);

            if ($lastIndex !== null && $this->marksAreEqual($merged[$lastIndex]['marks'], $run['marks'])) {
                $merged[$lastIndex]['text'] .= $run['text'];

                continue;
            }

            $merged[] = $run;
        }

        return $merged;
    }

    /**
     * @param  list<array<string, mixed>>  $left
     * @param  list<array<string, mixed>>  $right
     */
    private function marksAreEqual(array $left, array $right): bool
    {
        return json_encode($left, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            === json_encode($right, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<string, mixed>  $mark
     */
    private function markKey(array $mark): string
    {
        return json_encode($mark, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: (string) ($mark['type'] ?? '');
    }
}
