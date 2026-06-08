<?php

namespace App\Services\Geo;

class GeoImportReport
{
    /**
     * @param  list<array{line:int|null,code:string,message:string,context?:array<string, mixed>}>  $errors
     * @param  list<array{line:int|null,code:string,message:string,context?:array<string, mixed>}>  $warnings
     */
    public function __construct(
        public readonly string $file,
        public readonly bool $dryRun,
        public readonly int $processed = 0,
        public readonly int $created = 0,
        public readonly int $updated = 0,
        public readonly int $skipped = 0,
        public readonly array $errors = [],
        public readonly array $warnings = [],
        public readonly bool $fatal = false,
    ) {}

    public function successful(): bool
    {
        return $this->exitCode() === 0;
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function hasWarnings(): bool
    {
        return $this->warnings !== [];
    }

    public function exitCode(): int
    {
        if ($this->fatal) {
            return 2;
        }

        return $this->hasErrors() || $this->hasWarnings() ? 1 : 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'file' => $this->file,
            'dry_run' => $this->dryRun,
            'processed' => $this->processed,
            'created' => $this->created,
            'updated' => $this->updated,
            'skipped' => $this->skipped,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'fatal' => $this->fatal,
            'exit_code' => $this->exitCode(),
        ];
    }
}
