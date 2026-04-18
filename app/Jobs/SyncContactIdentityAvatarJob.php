<?php

namespace App\Jobs;

use App\Services\Bots\SyncContactIdentityAvatarAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncContactIdentityAvatarJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public int $contactIdentityId,
        public ?string $avatarUrl = null,
    ) {}

    public function handle(SyncContactIdentityAvatarAction $syncContactIdentityAvatarAction): void
    {
        $syncContactIdentityAvatarAction->handle($this->contactIdentityId, $this->avatarUrl);
    }
}
