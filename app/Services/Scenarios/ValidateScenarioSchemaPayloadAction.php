<?php

namespace App\Services\Scenarios;

use App\Models\Message;
use Illuminate\Validation\ValidationException;

class ValidateScenarioSchemaPayloadAction
{
    /**
     * @param  array<string, mixed>  $schemaPayload
     * @return array{
     *     version: int,
     *     start_block_id: string,
     *     triggers: list<array{type: 'parameter', value: string}>,
     *     blocks: array<string, array<string, mixed>>,
     * }
     */
    public function handle(array $schemaPayload, string $errorKey = 'schema_payload'): array
    {
        if ($schemaPayload === [] || array_is_list($schemaPayload)) {
            $this->fail($errorKey, 'Схема сценария должна быть JSON-объектом.');
        }

        $version = $schemaPayload['version'] ?? 1;

        if ((int) $version !== 1) {
            $this->fail($errorKey, 'Для slice 1 поддерживается только schema version = 1.');
        }

        $startBlockId = $this->normalizeRequiredString(
            $schemaPayload['start_block_id'] ?? null,
            $errorKey,
            'Нужно указать start_block_id.',
        );

        $triggers = $schemaPayload['triggers'] ?? null;

        if (! is_array($triggers) || array_is_list($triggers) === false || $triggers === []) {
            $this->fail($errorKey, 'Для slice 1 нужен хотя бы один parameter trigger.');
        }

        $normalizedTriggers = [];

        foreach ($triggers as $triggerIndex => $trigger) {
            if (! is_array($trigger) || array_is_list($trigger)) {
                $this->fail($errorKey, "Trigger #{$triggerIndex} должен быть JSON-объектом.");
            }

            $triggerType = $this->normalizeRequiredString(
                $trigger['type'] ?? null,
                $errorKey,
                "Trigger #{$triggerIndex} должен содержать type.",
            );

            if ($triggerType !== 'parameter') {
                $this->fail($errorKey, 'В slice 1 поддерживаются только triggers типа parameter.');
            }

            $triggerValue = $this->normalizeRequiredString(
                $trigger['value'] ?? null,
                $errorKey,
                "Trigger #{$triggerIndex} должен содержать value.",
            );

            $normalizedTriggers[] = [
                'type' => 'parameter',
                'value' => $triggerValue,
            ];
        }

        $blocks = $schemaPayload['blocks'] ?? null;

        if (! is_array($blocks) || $blocks === [] || array_is_list($blocks)) {
            $this->fail($errorKey, 'Нужно указать blocks как JSON-объект с блоками сценария.');
        }

        $normalizedBlocks = [];

        foreach ($blocks as $blockId => $block) {
            if (! is_string($blockId) || trim($blockId) === '') {
                $this->fail($errorKey, 'Каждый блок сценария должен иметь непустой string id.');
            }

            if (! is_array($block) || array_is_list($block)) {
                $this->fail($errorKey, "Блок {$blockId} должен быть JSON-объектом.");
            }

            $normalizedBlocks[trim($blockId)] = $this->normalizeBlock(trim($blockId), $block, $errorKey);
        }

        if (! array_key_exists($startBlockId, $normalizedBlocks)) {
            $this->fail($errorKey, 'start_block_id должен ссылаться на существующий блок.');
        }

        foreach ($normalizedBlocks as $blockId => $block) {
            if (! array_key_exists('next', $block)) {
                continue;
            }

            $nextBlockId = (string) $block['next'];

            if (! array_key_exists($nextBlockId, $normalizedBlocks)) {
                $this->fail($errorKey, "Блок {$blockId} ссылается на несуществующий next-блок {$nextBlockId}.");
            }
        }

        return [
            'version' => 1,
            'start_block_id' => $startBlockId,
            'triggers' => $normalizedTriggers,
            'blocks' => $normalizedBlocks,
        ];
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    private function normalizeBlock(string $blockId, array $block, string $errorKey): array
    {
        $blockType = $this->normalizeRequiredString(
            $block['type'] ?? null,
            $errorKey,
            "Блок {$blockId} должен содержать type.",
        );

        return match ($blockType) {
            'message' => $this->normalizeMessageBlock($blockId, $block, $errorKey),
            'question' => $this->normalizeQuestionBlock($blockId, $block, $errorKey),
            'complete' => $this->normalizeCompleteBlock($blockId, $block, $errorKey),
            default => $this->fail($errorKey, "Блок {$blockId} использует неподдерживаемый type {$blockType}."),
        };
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array{type: 'message', text: string, text_format: string, next: string}
     */
    private function normalizeMessageBlock(string $blockId, array $block, string $errorKey): array
    {
        $this->guardUnsupportedKeys(
            $blockId,
            $block,
            ['buttons', 'actions', 'save_to', 'expects', 'button_label', 'branches'],
            $errorKey,
        );

        return [
            'type' => 'message',
            'text' => $this->normalizeRequiredString(
                $block['text'] ?? null,
                $errorKey,
                "Блок {$blockId} должен содержать text.",
            ),
            'text_format' => $this->normalizeTextFormat($blockId, $block['text_format'] ?? null, $errorKey),
            'next' => $this->normalizeRequiredString(
                $block['next'] ?? null,
                $errorKey,
                "Блок {$blockId} типа message должен содержать next.",
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array{type: 'question', text: string, text_format: string, expects: 'text', save_to: string, next: string}
     */
    private function normalizeQuestionBlock(string $blockId, array $block, string $errorKey): array
    {
        $this->guardUnsupportedKeys(
            $blockId,
            $block,
            ['buttons', 'actions', 'button_label', 'branches'],
            $errorKey,
        );

        $expects = $block['expects'] ?? 'text';

        if (! is_string($expects) || trim($expects) !== 'text') {
            $this->fail($errorKey, "Блок {$blockId} в slice 1 поддерживает только expects = text.");
        }

        $saveTo = $this->normalizeRequiredString(
            $block['save_to'] ?? null,
            $errorKey,
            "Блок {$blockId} должен содержать save_to.",
        );

        if (! preg_match('/^run(?:\.[A-Za-z0-9_]+)+$/', $saveTo)) {
            $this->fail($errorKey, "Блок {$blockId} должен сохранять ответ только в run.*.");
        }

        return [
            'type' => 'question',
            'text' => $this->normalizeRequiredString(
                $block['text'] ?? null,
                $errorKey,
                "Блок {$blockId} должен содержать text.",
            ),
            'text_format' => $this->normalizeTextFormat($blockId, $block['text_format'] ?? null, $errorKey),
            'expects' => 'text',
            'save_to' => $saveTo,
            'next' => $this->normalizeRequiredString(
                $block['next'] ?? null,
                $errorKey,
                "Блок {$blockId} типа question должен содержать next.",
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array{type: 'complete'}
     */
    private function normalizeCompleteBlock(string $blockId, array $block, string $errorKey): array
    {
        $this->guardUnsupportedKeys(
            $blockId,
            $block,
            ['text', 'text_format', 'next', 'save_to', 'expects', 'buttons', 'actions', 'button_label', 'branches'],
            $errorKey,
        );

        return [
            'type' => 'complete',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $unsupportedKeys
     */
    private function guardUnsupportedKeys(string $blockId, array $payload, array $unsupportedKeys, string $errorKey): void
    {
        foreach ($unsupportedKeys as $unsupportedKey) {
            if (array_key_exists($unsupportedKey, $payload)) {
                $this->fail($errorKey, "Блок {$blockId} использует {$unsupportedKey}, это не входит в slice 1.");
            }
        }
    }

    private function normalizeRequiredString(mixed $value, string $errorKey, string $message): string
    {
        if (! is_string($value) || trim($value) === '') {
            $this->fail($errorKey, $message);
        }

        return trim($value);
    }

    private function normalizeTextFormat(string $blockId, mixed $value, string $errorKey): string
    {
        if ($value === null || $value === '') {
            return Message::TEXT_FORMAT_PLAIN_TEXT;
        }

        if (! is_string($value)) {
            $this->fail($errorKey, "Блок {$blockId} должен использовать корректный text_format.");
        }

        $normalizedTextFormat = trim($value);

        if (! in_array($normalizedTextFormat, [
            Message::TEXT_FORMAT_PLAIN_TEXT,
            Message::TEXT_FORMAT_HTML,
        ], true)) {
            $this->fail($errorKey, "Блок {$blockId} использует неподдерживаемый text_format.");
        }

        return $normalizedTextFormat;
    }

    /**
     * @return never
     */
    private function fail(string $errorKey, string $message): never
    {
        throw ValidationException::withMessages([
            $errorKey => $message,
        ]);
    }
}
