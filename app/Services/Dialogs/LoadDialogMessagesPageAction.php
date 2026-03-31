<?php

namespace App\Services\Dialogs;

use App\Data\Dialogs\DialogMessagesPageResult;
use App\Models\Dialog;
use App\Models\Message;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class LoadDialogMessagesPageAction
{
    public function __construct(
        protected BuildConversationFeedViewDataAction $buildConversationFeedViewDataAction,
    ) {}

    /**
     * @param  array{sort_at:string,id:int}|null  $cursor
     */
    public function handle(Dialog $dialog, ?array $cursor = null, int $limit = 50): DialogMessagesPageResult
    {
        $query = Message::query()
            ->where('dialog_id', $dialog->id)
            ->with(['channel', 'dialog.channel', 'sentByUser']);

        $cursorSortAt = null;
        $cursorMessageId = null;

        if (is_array($cursor) && filled($cursor['sort_at'] ?? null) && filled($cursor['id'] ?? null)) {
            $cursorSortAt = Carbon::parse((string) $cursor['sort_at']);
            $cursorMessageId = (int) $cursor['id'];

            $query->where(function (Builder $builder) use ($cursorSortAt, $cursorMessageId): void {
                $builder
                    ->whereRaw('coalesce(received_at, created_at) < ?', [$cursorSortAt->toDateTimeString()])
                    ->orWhere(function (Builder $nested) use ($cursorSortAt, $cursorMessageId): void {
                        $nested
                            ->whereRaw('coalesce(received_at, created_at) = ?', [$cursorSortAt->toDateTimeString()])
                            ->where('id', '<', $cursorMessageId);
                    });
            });
        }

        $messages = $query
            ->orderByRaw('coalesce(received_at, created_at) desc')
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get();

        $hasMoreOlderMessages = $messages->count() > $limit;
        $loadedMessages = $messages->take($limit)
            ->sort(function (Message $left, Message $right): int {
                $leftAt = $this->buildConversationFeedViewDataAction->resolveMessageSortAt($left);
                $rightAt = $this->buildConversationFeedViewDataAction->resolveMessageSortAt($right);

                $comparison = ($leftAt?->getTimestamp() ?? 0) <=> ($rightAt?->getTimestamp() ?? 0);

                if ($comparison !== 0) {
                    return $comparison;
                }

                return $left->id <=> $right->id;
            })
            ->values();

        $oldestLoadedMessage = $loadedMessages->first();

        return new DialogMessagesPageResult(
            messages: $loadedMessages,
            hasMoreOlderMessages: $hasMoreOlderMessages,
            nextOlderCursor: $hasMoreOlderMessages && $oldestLoadedMessage instanceof Message
                ? [
                    'sort_at' => ($this->buildConversationFeedViewDataAction->resolveMessageSortAt($oldestLoadedMessage) ?? now())->toIso8601String(),
                    'id' => $oldestLoadedMessage->id,
                ]
                : null,
        );
    }
}
