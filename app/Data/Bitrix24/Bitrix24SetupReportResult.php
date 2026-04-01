<?php

namespace App\Data\Bitrix24;

final readonly class Bitrix24SetupReportResult
{
    public const STATUS_OK = 'ok';

    public const STATUS_MISSING = 'missing';

    public const STATUS_WARNING = 'warning';

    /**
     * @param  list<array{key: string, label: string, value: string, status: string, required: bool, notes: string}>  $checks
     * @param  list<array{group: string, label: string, value: string}>  $frozenValues
     */
    public function __construct(
        public array $checks,
        public array $frozenValues,
    ) {}

    public function hasBlockingIssues(): bool
    {
        foreach ($this->checks as $check) {
            if ($check['required'] && $check['status'] === self::STATUS_MISSING) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{key: string, label: string, value: string, status: string, required: bool, notes: string}>
     */
    public function blockingChecks(): array
    {
        return array_values(array_filter(
            $this->checks,
            fn (array $check): bool => $check['required'] && $check['status'] === self::STATUS_MISSING,
        ));
    }

    /**
     * @return list<array{key: string, label: string, value: string, status: string, required: bool, notes: string}>
     */
    public function warningChecks(): array
    {
        return array_values(array_filter(
            $this->checks,
            fn (array $check): bool => $check['status'] === self::STATUS_WARNING,
        ));
    }

    /**
     * @return list<array{Item: string, Required: string, Status: string, Value: string, Notes: string}>
     */
    public function checkTableRows(): array
    {
        return array_map(
            fn (array $check): array => [
                'Item' => $check['label'],
                'Required' => $check['required'] ? 'yes' : 'no',
                'Status' => $check['status'],
                'Value' => $this->displayValue($check['key'], $check['value']),
                'Notes' => $check['notes'],
            ],
            $this->checks,
        );
    }

    /**
     * @return list<array{Group: string, Item: string, Value: string}>
     */
    public function frozenValueRows(): array
    {
        return array_map(
            fn (array $value): array => [
                'Group' => $value['group'],
                'Item' => $value['label'],
                'Value' => $this->displayValue($value['group'].'.'.$value['label'], $value['value']),
            ],
            $this->frozenValues,
        );
    }

    private function displayValue(string $key, string $value): string
    {
        if ($value === '') {
            return $value;
        }

        if (str_contains($key, 'secret')) {
            return '*** redacted ***';
        }

        return $value;
    }
}
