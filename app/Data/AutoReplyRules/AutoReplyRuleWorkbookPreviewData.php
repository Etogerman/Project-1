<?php

namespace App\Data\AutoReplyRules;

readonly class AutoReplyRuleWorkbookPreviewData
{
    /**
     * @param  list<AutoReplyRuleWorkbookRowData>  $createRows
     * @param  list<AutoReplyRuleWorkbookRowData>  $updateRows
     * @param  list<AutoReplyRuleWorkbookRowErrorData>  $errors
     */
    public function __construct(
        public array $createRows,
        public array $updateRows,
        public array $errors,
    ) {}

    public function createCount(): int
    {
        return count($this->createRows);
    }

    public function updateCount(): int
    {
        return count($this->updateRows);
    }

    public function errorCount(): int
    {
        return count($this->errors);
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function hasRows(): bool
    {
        return $this->createRows !== [] || $this->updateRows !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'create_rows' => array_map(
                fn (AutoReplyRuleWorkbookRowData $row): array => $row->toArray(),
                $this->createRows,
            ),
            'update_rows' => array_map(
                fn (AutoReplyRuleWorkbookRowData $row): array => $row->toArray(),
                $this->updateRows,
            ),
            'errors' => array_map(
                fn (AutoReplyRuleWorkbookRowErrorData $error): array => $error->toArray(),
                $this->errors,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            createRows: array_values(array_map(
                fn (array $row): AutoReplyRuleWorkbookRowData => AutoReplyRuleWorkbookRowData::fromArray($row),
                $data['create_rows'] ?? [],
            )),
            updateRows: array_values(array_map(
                fn (array $row): AutoReplyRuleWorkbookRowData => AutoReplyRuleWorkbookRowData::fromArray($row),
                $data['update_rows'] ?? [],
            )),
            errors: array_values(array_map(
                fn (array $error): AutoReplyRuleWorkbookRowErrorData => AutoReplyRuleWorkbookRowErrorData::fromArray($error),
                $data['errors'] ?? [],
            )),
        );
    }
}
