<?php

namespace App\Services\Dialogs;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\Dialog;
use App\Services\Contacts\ResolveRootContactAction;
use Illuminate\Database\QueryException;

class ResolveOrCreateDialogAction
{
    public function __construct(
        private readonly ResolveRootContactAction $resolveRootContactAction,
        private readonly SyncDialogStageAction $syncDialogStageAction,
    ) {}

    public function handle(Contact|int $contact, Channel|int $channel): Dialog
    {
        $rootContact = $this->resolveRootContactAction->handle($contact);
        $channelId = $channel instanceof Channel ? $channel->id : $channel;

        $dialog = Dialog::query()
            ->where('contact_id', $rootContact->id)
            ->where('channel_id', $channelId)
            ->first();

        if ($dialog instanceof Dialog) {
            $this->syncDialogStageAction->handle($dialog, false);

            return $dialog->fresh();
        }

        try {
            $dialog = Dialog::query()->create([
                'contact_id' => $rootContact->id,
                'channel_id' => $channelId,
            ]);

            $this->syncDialogStageAction->handle($dialog, false);

            return $dialog->fresh();
        } catch (QueryException $exception) {
            if (! $this->wasUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            $dialog = Dialog::query()
                ->where('contact_id', $rootContact->id)
                ->where('channel_id', $channelId)
                ->firstOrFail();

            $this->syncDialogStageAction->handle($dialog, false);

            return $dialog->fresh();
        }
    }

    private function wasUniqueConstraintViolation(QueryException $exception): bool
    {
        return ($exception->errorInfo[0] ?? null) === '23505';
    }
}
