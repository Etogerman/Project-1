<?php

namespace App\Data\Maintenance;

final readonly class RuntimeDataPurgeResult
{
    /**
     * @param  array<string, int>  $beforeCounts
     * @param  array<string, int>  $afterCounts
     * @param  list<string>  $purgedTables
     * @param  list<string>  $preservedTables
     */
    public function __construct(
        public bool $dryRun,
        public bool $includedSessions,
        public array $beforeCounts,
        public array $afterCounts,
        public array $purgedTables,
        public array $preservedTables,
    ) {}

    /**
     * @return array<string, int|string|bool>
     */
    public function toLogContext(): array
    {
        $context = [
            'dry_run' => $this->dryRun,
            'included_sessions' => $this->includedSessions,
        ];

        foreach ($this->beforeCounts as $table => $count) {
            $context[$table.'_count'] = $count;
        }

        return $context;
    }
}
