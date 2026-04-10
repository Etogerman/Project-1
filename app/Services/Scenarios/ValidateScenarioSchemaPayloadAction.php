<?php

namespace App\Services\Scenarios;

use App\Models\Tag;
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
            $this->fail($errorKey, 'Для Slice 2 lite поддерживается только schema version = 1.');
        }

        $startBlockId = $this->normalizeRequiredString(
            $schemaPayload['start_block_id'] ?? null,
            $errorKey,
            'Нужно указать start_block_id.',
        );

        $triggers = $schemaPayload['triggers'] ?? null;

        if (! is_array($triggers) || array_is_list($triggers) === false || $triggers === []) {
            $this->fail($errorKey, 'Для Slice 2 lite нужен хотя бы один parameter trigger.');
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
                $this->fail($errorKey, 'В Slice 2 lite поддерживаются только triggers типа parameter.');
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
                if (! array_key_exists('branches', $block)) {
                    continue;
                }

                foreach ($block['branches'] as $branchIndex => $branch) {
                    $targetBlockId = (string) ($branch['then'] ?? $branch['default'] ?? '');

                    if (! array_key_exists($targetBlockId, $normalizedBlocks)) {
                        $this->fail($errorKey, "Блок {$blockId} ссылается на несуществующую ветку {$targetBlockId}.");
                    }
                }

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
            'condition' => $this->normalizeConditionBlock($blockId, $block, $errorKey),
            'complete' => $this->normalizeCompleteBlock($blockId, $block, $errorKey),
            default => $this->fail($errorKey, "Блок {$blockId} использует неподдерживаемый type {$blockType}."),
        };
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array{type: 'message', text: string, text_format: string, next: string, actions?: list<array{type: 'set_tag'|'remove_tag', value: string}>}
     */
    private function normalizeMessageBlock(string $blockId, array $block, string $errorKey): array
    {
        $this->guardUnsupportedKeys(
            $blockId,
            $block,
            ['buttons', 'save_to', 'expects', 'button_label', 'branches'],
            $errorKey,
        );

        $normalizedBlock = [
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

        $normalizedActions = $this->normalizeBlockActions($blockId, $block['actions'] ?? null, $errorKey);

        if ($normalizedActions !== []) {
            $normalizedBlock['actions'] = $normalizedActions;
        }

        return $normalizedBlock;
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
            $this->fail($errorKey, "Блок {$blockId} в Slice 2 lite поддерживает только expects = text.");
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
     * @return array{
     *     type: 'condition',
     *     branches: list<array{if: array<string, mixed>, then: string}|array{default: string}>
     * }
     */
    private function normalizeConditionBlock(string $blockId, array $block, string $errorKey): array
    {
        $this->guardUnsupportedKeys(
            $blockId,
            $block,
            ['text', 'text_format', 'next', 'save_to', 'expects', 'buttons', 'actions', 'button_label'],
            $errorKey,
        );

        $branches = $block['branches'] ?? null;

        if (! is_array($branches) || ! array_is_list($branches) || $branches === []) {
            $this->fail($errorKey, "Блок {$blockId} типа condition должен содержать непустой список branches.");
        }

        $normalizedBranches = [];
        $defaultBranchCount = 0;
        $conditionalBranchCount = 0;

        foreach ($branches as $branchIndex => $branch) {
            if (! is_array($branch) || array_is_list($branch)) {
                $this->fail($errorKey, "Ветка #{$branchIndex} блока {$blockId} должна быть JSON-объектом.");
            }

            if (array_key_exists('default', $branch)) {
                $defaultBranchCount++;

                $normalizedBranches[] = [
                    'default' => $this->normalizeRequiredString(
                        $branch['default'],
                        $errorKey,
                        "Default-ветка #{$branchIndex} блока {$blockId} должна ссылаться на block id.",
                    ),
                ];

                continue;
            }

            $conditionalBranchCount++;

            $normalizedBranches[] = [
                'if' => $this->normalizeCondition(
                    $blockId,
                    $branch['if'] ?? null,
                    $errorKey,
                    "Блок {$blockId}, ветка #{$branchIndex}",
                ),
                'then' => $this->normalizeRequiredString(
                    $branch['then'] ?? null,
                    $errorKey,
                    "Ветка #{$branchIndex} блока {$blockId} должна содержать then.",
                ),
            ];
        }

        if ($conditionalBranchCount < 1) {
            $this->fail($errorKey, "Блок {$blockId} должен содержать хотя бы одну условную ветку.");
        }

        if ($defaultBranchCount !== 1) {
            $this->fail($errorKey, "Блок {$blockId} должен содержать ровно одну default-ветку.");
        }

        return [
            'type' => 'condition',
            'branches' => $normalizedBranches,
        ];
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array{type: 'complete', actions?: list<array{type: 'set_tag'|'remove_tag', value: string}>}
     */
    private function normalizeCompleteBlock(string $blockId, array $block, string $errorKey): array
    {
        $this->guardUnsupportedKeys(
            $blockId,
            $block,
            ['text', 'text_format', 'next', 'save_to', 'expects', 'buttons', 'button_label', 'branches'],
            $errorKey,
        );

        $normalizedBlock = [
            'type' => 'complete',
        ];

        $normalizedActions = $this->normalizeBlockActions($blockId, $block['actions'] ?? null, $errorKey);

        if ($normalizedActions !== []) {
            $normalizedBlock['actions'] = $normalizedActions;
        }

        return $normalizedBlock;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $unsupportedKeys
     */
    private function guardUnsupportedKeys(string $blockId, array $payload, array $unsupportedKeys, string $errorKey): void
    {
        foreach ($unsupportedKeys as $unsupportedKey) {
            if (array_key_exists($unsupportedKey, $payload)) {
                $this->fail($errorKey, "Блок {$blockId} использует {$unsupportedKey}, это не входит в Slice 2 lite.");
            }
        }
    }

    /**
     * @return list<array{type: 'set_tag'|'remove_tag', value: string}>
     */
    private function normalizeBlockActions(string $blockId, mixed $actions, string $errorKey): array
    {
        if ($actions === null) {
            return [];
        }

        if (! is_array($actions) || ! array_is_list($actions) || $actions === []) {
            $this->fail($errorKey, "Блок {$blockId} должен содержать actions как непустой список.");
        }

        $normalizedActions = [];

        foreach ($actions as $actionIndex => $action) {
            if (! is_array($action) || array_is_list($action)) {
                $this->fail($errorKey, "Action #{$actionIndex} блока {$blockId} должен быть JSON-объектом.");
            }

            $actionType = $this->normalizeRequiredString(
                $action['type'] ?? null,
                $errorKey,
                "Action #{$actionIndex} блока {$blockId} должен содержать type.",
            );

            if (! in_array($actionType, ['set_tag', 'remove_tag'], true)) {
                $this->fail($errorKey, "Action #{$actionIndex} блока {$blockId} использует неподдерживаемый type {$actionType}.");
            }

            $tagSlug = $this->normalizeRequiredString(
                $action['value'] ?? null,
                $errorKey,
                "Action #{$actionIndex} блока {$blockId} должен содержать value.",
            );

            if (! Tag::query()->active()->where('slug', $tagSlug)->exists()) {
                $this->fail($errorKey, "Action #{$actionIndex} блока {$blockId} ссылается на несуществующий или неактивный тег {$tagSlug}.");
            }

            $normalizedActions[] = [
                'type' => $actionType,
                'value' => $tagSlug,
            ];
        }

        return $normalizedActions;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeCondition(string $blockId, mixed $condition, string $errorKey, string $context): array
    {
        if (! is_array($condition) || array_is_list($condition)) {
            $this->fail($errorKey, "{$context} должна содержать JSON-объект условия.");
        }

        if (array_key_exists('all', $condition)) {
            return [
                'all' => $this->normalizeNestedConditionList($blockId, $condition['all'], $errorKey, "{$context}.all"),
            ];
        }

        if (array_key_exists('any', $condition)) {
            return [
                'any' => $this->normalizeNestedConditionList($blockId, $condition['any'], $errorKey, "{$context}.any"),
            ];
        }

        if (array_key_exists('not', $condition)) {
            return [
                'not' => $this->normalizeCondition(
                    $blockId,
                    $condition['not'],
                    $errorKey,
                    "{$context}.not",
                ),
            ];
        }

        $variablePath = $this->normalizeRequiredString(
            $condition['var'] ?? null,
            $errorKey,
            "{$context} должна содержать var.",
        );

        if (! preg_match('/^run(?:\.[A-Za-z0-9_]+)+$/', $variablePath)) {
            $this->fail($errorKey, "{$context} может читать только run.*.");
        }

        $leafOperators = array_values(array_filter(
            ['equals', 'not_equals', 'in', 'not_in'],
            fn (string $operator): bool => array_key_exists($operator, $condition),
        ));

        if (count($leafOperators) !== 1) {
            $this->fail($errorKey, "{$context} должна содержать ровно один оператор сравнения.");
        }

        $operator = $leafOperators[0];

        return match ($operator) {
            'equals', 'not_equals' => [
                'var' => $variablePath,
                $operator => $this->normalizeRequiredString(
                    $condition[$operator],
                    $errorKey,
                    "{$context} должна содержать непустое string-значение для {$operator}.",
                ),
            ],
            'in', 'not_in' => [
                'var' => $variablePath,
                $operator => $this->normalizeStringList(
                    $condition[$operator],
                    $errorKey,
                    "{$context} должна содержать непустой список string-значений для {$operator}.",
                ),
            ],
            default => $this->fail($errorKey, "{$context} использует неподдерживаемый оператор."),
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeNestedConditionList(string $blockId, mixed $conditions, string $errorKey, string $context): array
    {
        if (! is_array($conditions) || ! array_is_list($conditions) || $conditions === []) {
            $this->fail($errorKey, "{$context} должна содержать непустой список условий.");
        }

        $normalizedConditions = [];

        foreach ($conditions as $conditionIndex => $condition) {
            $normalizedConditions[] = $this->normalizeCondition(
                $blockId,
                $condition,
                $errorKey,
                "{$context}[{$conditionIndex}]",
            );
        }

        return $normalizedConditions;
    }

    /**
     * @return list<string>
     */
    private function normalizeStringList(mixed $value, string $errorKey, string $message): array
    {
        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            $this->fail($errorKey, $message);
        }

        return array_values(array_map(
            fn (mixed $item): string => $this->normalizeRequiredString($item, $errorKey, $message),
            $value,
        ));
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
