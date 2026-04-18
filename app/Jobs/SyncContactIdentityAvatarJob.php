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

    public int $contactIdentityId;

    public ?string $avatarUrl = null;

    public ?string $externalChatId = null;

    public function __construct(
        int $contactIdentityId,
        ?string $avatarUrl = null,
        ?string $externalChatId = null,
    ) {
        $this->contactIdentityId = $contactIdentityId;
        $this->avatarUrl = $avatarUrl;
        $this->externalChatId = $externalChatId;
    }

    public function handle(SyncContactIdentityAvatarAction $syncContactIdentityAvatarAction): void
    {
        $syncContactIdentityAvatarAction->handle(
            $this->contactIdentityId,
            $this->avatarUrl,
            $this->externalChatId,
        );
    }
}
