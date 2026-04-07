<?php

namespace App\Data\AutoReplyRules;

readonly class AutoReplyRuleWorkbookRowErrorData
{
    public function __construct(
        public int $rowNumber,
        public string $column,
        public string $message,
    ) {}

    /**
     * @return array{row_number:int,column:string,message:string}
     */
    public function toArray(): array
    {
        return [
            'row_number' => $this->rowNumber,
            'column' => $this->column,
            'message' => $this->message,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            rowNumber: (int) ($data['row_number'] ?? 0),
            column: (string) ($data['column'] ?? 'row'),
            message: (string) ($data['message'] ?? ''),
        );
    }
}
