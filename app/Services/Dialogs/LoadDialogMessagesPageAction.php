<?php

namespace App\Services\Dialogs;

use App\Data\Dialogs\DialogMessagesPageResult;
use App\Models\Dialog;
use App\Models\Message;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class LoadDialogMessagesPageAction
{
    public function __construct(
        protected BuildConversationFeedViewDataAction $buildConversationFeedViewDataAction,
        protected MessageChronology $messageChronology,
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
                    ->whereRaw($this->messageChronology->sqlSortAt('messages').' < ?', [$cursorSortAt->toDateTimeString()])
                    ->orWhere(function (Builder $nested) use ($cursorSortAt, $cursorMessageId): void {
                        $nested
                            ->whereRaw($this->messageChronology->sqlSortAt('messages').' = ?', [$cursorSortAt->toDateTimeString()])
                            ->where('id', '<', $cursorMessageId);
                    });
            });
        }

        $messages = $query
            ->tap(fn (Builder $builder): Builder => $this->messageChronology->applyLatestOrder($builder))
            ->limit($limit + 1)
            ->get();

        $hasMoreOlderMessages = $messages->count() > $limit;
        $loadedMessages = $messages->take($limit)
            ->sort(function (Message $left, Message $right): int {
                return $this->messageChronology->compareSortTuple(
                    $this->buildConversationFeedViewDataAction->resolveMessageSortAt($left),
                    $left->id,
                    $this->buildConversationFeedViewDataAction->resolveMessageSortAt($right),
                    $right->id,
                );
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

    /**
     * @return Collection<int, Message>
     */
    public function loadMessagesAddedAfterId(Dialog $dialog, ?int $messageId, int $limit = 50): Collection
    {
        if ($messageId === null) {
            return collect();
        }

        return Message::query()
            ->where('dialog_id', $dialog->id)
            ->with(['channel', 'dialog.channel', 'sentByUser'])
            ->where('id', '>', $messageId)
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->values();
    }
}
