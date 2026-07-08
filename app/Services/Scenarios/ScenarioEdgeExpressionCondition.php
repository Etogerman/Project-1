<?php

namespace App\Services\Scenarios;

use App\Models\Contact;
use App\Models\ContactEmail;
use App\Models\ContactPhoneNumber;
use App\Models\Dialog;
use App\Models\Message;
use App\Services\Contacts\ResolveRootContactAction;
use App\Services\Dialogs\ResolveDialogStageAction;
use DateTimeInterface;
use InvalidArgumentException;

class ScenarioEdgeExpressionCondition
{
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
        $operator = (string) ($node['operator'] ?? '');

        if (($node['value_type'] ?? 'string') === 'number') {
            $actualValue = $this->numericVariableValue((string) ($node['variable'] ?? ''), $message);
            $expectedValue = (float) ($node['value'] ?? 0);

            if ($actualValue === null) {
                return false;
            }

            return match ($operator) {
                '>' => $actualValue > $expectedValue,
                '>=' => $actualValue >= $expectedValue,
                '<' => $actualValue < $expectedValue,
                '<=' => $actualValue <= $expectedValue,
                '!=' => $actualValue != $expectedValue,
                default => $actualValue == $expectedValue,
            };
        }

        $actualValue = $this->variableValue((string) ($node['variable'] ?? ''), $message);
        $expectedValue = (string) ($node['value'] ?? '');
        $matches = $actualValue === $expectedValue;

        return $operator === '!=' ? ! $matches : $matches;
    }

    private function variableValue(string $variable, Message $message): string
    {
        return $this->normalizeValue($this->rawVariableValue($variable, $message));
    }

    private function numericVariableValue(string $variable, Message $message): ?float
    {
        $value = $this->rawVariableValue($variable, $message);

        if ($value === null || $value === '') {
            return 0.0;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric(trim($value))) {
            return (float) trim($value);
        }

        return null;
    }

    private function rawVariableValue(string $variable, Message $message): mixed
    {
        if (str_starts_with($variable, 'contact.')) {
            $field = substr($variable, strlen('contact.'));

            if (! in_array($field, EngineFieldRegistry::readableFieldKeys(EngineFieldRegistry::ENTITY_CONTACT), true)) {
                throw new InvalidArgumentException('Unsupported contact variable.');
            }

            return $this->contactValue($field, $message);
        }

        if (str_starts_with($variable, 'dialog.')) {
            $field = substr($variable, strlen('dialog.'));

            if (! $this->validDialogVariableKey($field)) {
                throw new InvalidArgumentException('Unsupported dialog variable.');
            }

            return $this->dialogValue($field, $message);
        }

        throw new InvalidArgumentException('Unsupported variable.');
    }

    private function contactValue(string $field, Message $message): mixed
    {
        if (! $message->contact instanceof Contact) {
            return null;
        }

        $contact = $this->resolveRootContactAction->handle($message->contact);

        if ($field === 'phone' || $field === 'phones') {
            return $contact->phoneNumbers()
                ->get(['phone_normalized', 'phone_raw'])
                ->flatMap(fn (ContactPhoneNumber $phone): array => [
                    $phone->phone_normalized,
                    $phone->phone_raw,
                ])
                ->first(fn (mixed $value): bool => trim((string) $value) !== '');
        }

        if ($field === 'emails') {
            return $contact->emails()
                ->get(['email_normalized', 'email_raw'])
                ->flatMap(fn (ContactEmail $email): array => [
                    $email->email_normalized,
                    $email->email_raw,
                ])
                ->first(fn (mixed $value): bool => trim((string) $value) !== '');
        }

        $field = EngineFieldRegistry::resolveReadAlias(EngineFieldRegistry::ENTITY_CONTACT, $field);

        return $contact->{$field} ?? null;
    }

    private function dialogValue(string $field, Message $message): mixed
    {
        $dialog = $message->dialog instanceof Dialog
            ? $message->dialog
            : ($message->dialog_id !== null ? Dialog::query()->find($message->dialog_id) : null);

        if (! $dialog instanceof Dialog) {
            return null;
        }

        if (in_array($field, EngineFieldRegistry::readableFieldKeys(EngineFieldRegistry::ENTITY_DIALOG), true)) {
            return $this->dialogSystemValue($field, $dialog);
        }

        $fieldsPayload = is_array($dialog?->fields_payload) ? $dialog->fields_payload : [];

        return $fieldsPayload[$field] ?? null;
    }

    private function dialogSystemValue(string $field, Dialog $dialog): mixed
    {
        return match ($field) {
            'stage' => app(ResolveDialogStageAction::class)->handle($dialog),
            'phone' => $dialog->confirmed_phone_raw ?: $dialog->confirmed_phone_normalized,
            'external_username' => $dialog->currentContactIdentity?->external_username,
            'last_inbound_message_at' => $dialog->last_inbound_at,
            'last_outbound_message_at' => $dialog->last_outbound_at,
            default => $dialog->{$field} ?? null,
        };
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

        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        throw new InvalidArgumentException('Expression variable value must be scalar.');
    }

    private function validDialogVariableKey(string $key): bool
    {
        if ($key === '' || mb_strlen($key) > 64) {
            return false;
        }

        if (in_array($key, ['__proto__', 'constructor', 'prototype'], true)) {
            return false;
        }

        return preg_match('/^(?!_)[\p{L}][\p{L}\p{N}_]{0,63}$/u', $key) === 1;
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
        $items = [$this->parseFactor()];

        while ($this->readKeyword('and')) {
            $items[] = $this->parseFactor();
        }

        return count($items) === 1 ? $items[0] : [
            'type' => 'and',
            'items' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseFactor(): array
    {
        $this->skipWhitespace();

        if (($this->expression[$this->position] ?? null) === '(') {
            $this->position++;
            $node = $this->parseOr();
            $this->skipWhitespace();

            if (($this->expression[$this->position] ?? null) !== ')') {
                throw new InvalidArgumentException('Expected closing parenthesis.');
            }

            $this->position++;

            return $node;
        }

        return $this->parseComparison();
    }

    /**
     * @return array<string, mixed>
     */
    private function parseComparison(): array
    {
        $variable = $this->readVariable();
        $operator = $this->readOperator();
        $literal = $this->readLiteral();

        if ($literal['type'] === 'string' && ! in_array($operator, ['==', '!='], true)) {
            throw new InvalidArgumentException('String literal supports only equality operators.');
        }

        return [
            'type' => 'comparison',
            'variable' => $variable,
            'operator' => $operator,
            'value' => $literal['value'],
            'value_type' => $literal['type'],
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

        if (in_array($variable, array_map(fn (string $field): string => 'contact.'.$field, EngineFieldRegistry::readableFieldKeys(EngineFieldRegistry::ENTITY_CONTACT)), true)) {
            return $variable;
        }

        if (preg_match('/^dialog\.(?!_)[\p{L}][\p{L}\p{N}_]{0,63}$/u', $variable)) {
            $field = substr($variable, strlen('dialog.'));

            if (in_array($field, ['__proto__', 'constructor', 'prototype'], true)) {
                throw new InvalidArgumentException('Unsupported variable.');
            }

            return $variable;
        }

        throw new InvalidArgumentException('Unsupported variable.');
    }

    private function readOperator(): string
    {
        $this->skipWhitespace();

        foreach (['>=', '<=', '==', '!=', '>', '<'] as $operator) {
            if (str_starts_with(substr($this->expression, $this->position), $operator)) {
                $this->position += strlen($operator);

                return $operator;
            }
        }

        throw new InvalidArgumentException('Expected comparison operator.');
    }

    /**
     * @return array{type: string, value: string|float}
     */
    private function readLiteral(): array
    {
        $this->skipWhitespace();

        if (($this->expression[$this->position] ?? null) === '"') {
            return [
                'type' => 'string',
                'value' => $this->readString(),
            ];
        }

        return [
            'type' => 'number',
            'value' => $this->readNumber(),
        ];
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

    private function readNumber(): float
    {
        $this->skipWhitespace();
        $tail = substr($this->expression, $this->position);

        if (! preg_match('/^-?\d+(?:\.\d+)?/', $tail, $matches)) {
            throw new InvalidArgumentException('Expected number literal.');
        }

        $number = $matches[0];
        $this->position += strlen($number);

        return (float) $number;
    }

    private function readKeyword(string $keyword): bool
    {
        $this->skipWhitespace();

        if (! str_starts_with(substr($this->expression, $this->position), $keyword)) {
            return false;
        }

        $next = $this->expression[$this->position + strlen($keyword)] ?? '';

        if ($next !== '' && preg_match('/[\p{L}\p{N}_]/u', $next)) {
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
