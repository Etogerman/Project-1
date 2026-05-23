<?php

namespace App\Services\Scenarios;

use App\Models\Contact;
use App\Models\ContactPhoneNumber;
use App\Models\Dialog;
use App\Models\Message;
use App\Services\Contacts\ResolveRootContactAction;
use InvalidArgumentException;

class ScenarioEdgeExpressionCondition
{
    public const CONTACT_VARIABLES = [
        'phone',
        'first_name',
        'first_name_source',
        'last_name',
        'country',
        'city',
        'gender',
        'age_years',
        'age_range',
    ];

    public function __construct(
        private readonly ResolveRootContactAction $resolveRootContactAction,
    ) {}

    public function normalize(mixed $expression): string
    {
        return trim((string) $expression);
    }

    public function assertValid(mixed $expression): void
    {
        $expression = $this->normalize($expression);

        if ($expression === '') {
            return;
        }

        $parser = new ScenarioEdgeExpressionParser($expression);
        $parser->parse();
    }

    public function evaluate(mixed $expression, Message $message): bool
    {
        $expression = $this->normalize($expression);

        if ($expression === '') {
            return true;
        }

        $parser = new ScenarioEdgeExpressionParser($expression);

        return $this->evaluateNode($parser->parse(), $message);
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function evaluateNode(array $node, Message $message): bool
    {
        return match ($node['type'] ?? null) {
            'or' => collect($node['items'] ?? [])
                ->contains(fn (mixed $item): bool => is_array($item) && $this->evaluateNode($item, $message)),
            'and' => collect($node['items'] ?? [])
                ->every(fn (mixed $item): bool => is_array($item) && $this->evaluateNode($item, $message)),
            'comparison' => $this->evaluateComparison($node, $message),
            default => throw new InvalidArgumentException('Unknown expression node.'),
        };
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function evaluateComparison(array $node, Message $message): bool
    {
        $actualValue = $this->variableValue((string) ($node['variable'] ?? ''), $message);
        $expectedValue = (string) ($node['value'] ?? '');
        $matches = $actualValue === $expectedValue;

        return ($node['operator'] ?? '') === '!=' ? ! $matches : $matches;
    }

    private function variableValue(string $variable, Message $message): string
    {
        if (str_starts_with($variable, 'contact.')) {
            $field = substr($variable, strlen('contact.'));

            if (! in_array($field, self::CONTACT_VARIABLES, true)) {
                throw new InvalidArgumentException('Unsupported contact variable.');
            }

            return $this->normalizeValue($this->contactValue($field, $message));
        }

        if (str_starts_with($variable, 'dialog.')) {
            $field = substr($variable, strlen('dialog.'));

            if (! preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $field)) {
                throw new InvalidArgumentException('Unsupported dialog variable.');
            }

            return $this->normalizeValue($this->dialogValue($field, $message));
        }

        throw new InvalidArgumentException('Unsupported variable.');
    }

    private function contactValue(string $field, Message $message): mixed
    {
        if (! $message->contact instanceof Contact) {
            return null;
        }

        $contact = $this->resolveRootContactAction->handle($message->contact);

        if ($field === 'phone') {
            return $contact->phoneNumbers()
                ->get(['phone_normalized', 'phone_raw'])
                ->flatMap(fn (ContactPhoneNumber $phone): array => [
                    $phone->phone_normalized,
                    $phone->phone_raw,
                ])
                ->first(fn (mixed $value): bool => trim((string) $value) !== '');
        }

        return $contact->{$field} ?? null;
    }

    private function dialogValue(string $field, Message $message): mixed
    {
        $dialog = $message->dialog instanceof Dialog
            ? $message->dialog
            : ($message->dialog_id !== null ? Dialog::query()->find($message->dialog_id) : null);
        $fieldsPayload = is_array($dialog?->fields_payload) ? $dialog->fields_payload : [];

        return $fieldsPayload[$field] ?? null;
    }

    private function normalizeValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_string($value)) {
            return $value;
        }

        throw new InvalidArgumentException('Expression variable value must be scalar.');
    }
}

class ScenarioEdgeExpressionParser
{
    private int $position = 0;

    public function __construct(
        private readonly string $expression,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function parse(): array
    {
        $node = $this->parseOr();
        $this->skipWhitespace();

        if (! $this->isEnd()) {
            throw new InvalidArgumentException('Unexpected token.');
        }

        return $node;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseOr(): array
    {
        $items = [$this->parseAnd()];

        while ($this->readKeyword('or')) {
            $items[] = $this->parseAnd();
        }

        return count($items) === 1 ? $items[0] : [
            'type' => 'or',
            'items' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseAnd(): array
    {
        $items = [$this->parseComparison()];

        while ($this->readKeyword('and')) {
            $items[] = $this->parseComparison();
        }

        return count($items) === 1 ? $items[0] : [
            'type' => 'and',
            'items' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseComparison(): array
    {
        $variable = $this->readVariable();
        $operator = $this->readOperator();
        $value = $this->readString();

        return [
            'type' => 'comparison',
            'variable' => $variable,
            'operator' => $operator,
            'value' => $value,
        ];
    }

    private function readVariable(): string
    {
        $this->skipWhitespace();

        if (! str_starts_with(substr($this->expression, $this->position), '{{')) {
            throw new InvalidArgumentException('Expected variable.');
        }

        $end = strpos($this->expression, '}}', $this->position + 2);

        if ($end === false) {
            throw new InvalidArgumentException('Unclosed variable.');
        }

        $variable = trim(substr($this->expression, $this->position + 2, $end - $this->position - 2));
        $this->position = $end + 2;

        if (in_array($variable, array_map(fn (string $field): string => 'contact.'.$field, ScenarioEdgeExpressionCondition::CONTACT_VARIABLES), true)) {
            return $variable;
        }

        if (preg_match('/^dialog\.[A-Za-z][A-Za-z0-9_]*$/', $variable)) {
            return $variable;
        }

        throw new InvalidArgumentException('Unsupported variable.');
    }

    private function readOperator(): string
    {
        $this->skipWhitespace();

        foreach (['==', '!='] as $operator) {
            if (str_starts_with(substr($this->expression, $this->position), $operator)) {
                $this->position += strlen($operator);

                return $operator;
            }
        }

        throw new InvalidArgumentException('Expected comparison operator.');
    }

    private function readString(): string
    {
        $this->skipWhitespace();

        if (($this->expression[$this->position] ?? null) !== '"') {
            throw new InvalidArgumentException('Expected string literal.');
        }

        $this->position++;
        $value = '';

        while (! $this->isEnd()) {
            $char = $this->expression[$this->position];

            if ($char === '"') {
                $this->position++;

                return $value;
            }

            if ($char === '\\') {
                throw new InvalidArgumentException('Escaped strings are not supported.');
            }

            $value .= $char;
            $this->position++;
        }

        throw new InvalidArgumentException('Unclosed string literal.');
    }

    private function readKeyword(string $keyword): bool
    {
        $this->skipWhitespace();

        if (! str_starts_with(substr($this->expression, $this->position), $keyword)) {
            return false;
        }

        $next = $this->expression[$this->position + strlen($keyword)] ?? '';

        if ($next !== '' && preg_match('/[A-Za-z0-9_]/', $next)) {
            return false;
        }

        $this->position += strlen($keyword);

        return true;
    }

    private function skipWhitespace(): void
    {
        while (! $this->isEnd() && preg_match('/\s/u', $this->expression[$this->position]) === 1) {
            $this->position++;
        }
    }

    private function isEnd(): bool
    {
        return $this->position >= strlen($this->expression);
    }
}
