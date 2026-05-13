<?php

namespace App\Jobs;

use App\Services\Bots\ProcessBotConstructorScheduledArrowAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessBotConstructorScheduledArrowJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly int $arrowRunId,
    ) {}

    public function handle(ProcessBotConstructorScheduledArrowAction $action): void
    {
        $action->handle($this->arrowRunId);
    }
}
