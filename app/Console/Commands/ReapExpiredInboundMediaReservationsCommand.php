<?php

namespace App\Console\Commands;

use App\Services\Messages\ReapExpiredInboundMediaReservationsAction;
use Illuminate\Console\Command;

class ReapExpiredInboundMediaReservationsCommand extends Command
{
    protected $signature = 'media:reap-expired-reservations {--limit=100 : Maximum expired reservations to inspect}';

    protected $description = 'Release expired inbound media quota reservations after partial cleanup.';

    public function __construct(
        private readonly ReapExpiredInboundMediaReservationsAction $reaper,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $stats = $this->reaper->handle((int) $this->option('limit'));

        $this->table(
            ['Result', 'Count'],
            collect($stats)
                ->map(fn (int $count, string $result): array => [$result, (string) $count])
                ->values()
                ->all(),
        );

        return $stats['cleanup_failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
