<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24HistoryExportChunkData;
use App\Models\Message;
use Illuminate\Support\Collection;

class BuildBitrix24HistoryExportChunksAction
{
    private const MAX_MESSAGES_PER_CHUNK = 20;

    private const MAX_COMMENT_CHARACTERS = 8000;

    public function __construct(
        private readonly BuildBitrix24TimelineCommentAction $buildTimelineCommentAction,
    ) {}

    /**
     * @param  Collection<int, Message>|list<Message>  $messages
     * @return list<Bitrix24HistoryExportChunkData>
     */
    public function handle(Collection|array $messages): array
    {
        $messages = $messages instanceof Collection ? $messages->all() : array_values($messages);

        if ($messages === []) {
            return [];
        }

        $chunks = [];
        $currentMessages = [];
        $currentBodyLength = 0;
        $headerLength = $this->buildTimelineCommentAction->maxHeaderLength();

        foreach ($messages as $message) {
            $messageBlock = $this->buildTimelineCommentAction->buildMessageBlock($message);
            $messageBlockLength = mb_strlen($messageBlock);
            $separatorLength = $currentMessages === [] ? 0 : 2;
            $projectedLength = $headerLength + $currentBodyLength + $separatorLength + $messageBlockLength;

            if (
                $currentMessages !== []
                && (
                    count($currentMessages) >= self::MAX_MESSAGES_PER_CHUNK
                    || $projectedLength > self::MAX_COMMENT_CHARACTERS
                )
            ) {
                $chunks[] = $currentMessages;
                $currentMessages = [];
                $currentBodyLength = 0;
                $separatorLength = 0;
            }

            $currentMessages[] = $message;
            $currentBodyLength += ($currentMessages === [$message] ? 0 : $separatorLength) + $messageBlockLength;
        }

        if ($currentMessages !== []) {
            $chunks[] = $currentMessages;
        }

        $total = count($chunks);

        return array_map(
            static fn (array $chunkMessages, int $index): Bitrix24HistoryExportChunkData => new Bitrix24HistoryExportChunkData(
                sequence: $index + 1,
                total: $total,
                messages: $chunkMessages,
            ),
            $chunks,
            array_keys($chunks),
        );
    }
}
