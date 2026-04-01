<?php

namespace App\Data\Bitrix24;

use App\Models\Message;

final readonly class Bitrix24HistoryExportChunkData
{
    /**
     * @param  list<Message>  $messages
     */
    public function __construct(
        public int $sequence,
        public int $total,
        public array $messages,
    ) {}

    /**
     * @return list<int>
     */
    public function messageIds(): array
    {
        return array_map(
            static fn (Message $message): int => $message->id,
            $this->messages,
        );
    }
}
