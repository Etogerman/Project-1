<?php

namespace App\Data\Dialogs;

use App\Models\Message;
use Illuminate\Support\Collection;

final readonly class DialogMessagesPageResult
{
    /**
     * @param  Collection<int, Message>  $messages
     * @param  array{sort_at:string,id:int}|null  $nextOlderCursor
     */
    public function __construct(
        public Collection $messages,
        public bool $hasMoreOlderMessages,
        public ?array $nextOlderCursor,
    ) {}
}
