<?php

namespace App\Console\Commands;

use App\Services\Messages\BuildInboundMediaObservabilitySnapshotAction;
use Illuminate\Console\Command;
use JsonException;

class MediaObservabilitySnapshotCommand extends Command
{
    protected $signature = 'media:observability-snapshot
        {--window=60 : Observability window in positive whole minutes}
        {--json : Print compact JSON}';

    protected $description = 'Build a strictly read-only inbound media observability snapshot.';

    public function __construct(
        private readonly BuildInboundMediaObservabilitySnapshotAction $snapshot,
    ) {
        parent::__construct();
    }

    /**
     * @throws JsonException
     */
    public function handle(): int
    {
        $windowMinutes = filter_var(
            $this->option('window'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );

        if (! is_int($windowMinutes)) {
            $this->error('Параметр --window должен быть положительным целым числом минут.');

            return self::INVALID;
        }

        $snapshot = $this->snapshot->handle($windowMinutes);
        $flags = JSON_THROW_ON_ERROR
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_PRESERVE_ZERO_FRACTION;

        if (! (bool) $this->option('json')) {
            $flags |= JSON_PRETTY_PRINT;
        }

        $this->line(json_encode($snapshot, $flags));

        return ! $snapshot['complete'] || $snapshot['blocking_anomalies'] !== []
            ? self::FAILURE
            : self::SUCCESS;
    }
}
