<?php

namespace App\Services\Dialogs;

use App\Models\Message;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class BuildDialogMessageSnapshotPayloadAction
{
    private const PREVIEW_LIMIT = 1000;

    public function __construct(
        private readonly BuildConversationFeedViewDataAction $buildConversationFeedViewDataAction,
        private readonly MessageChronology $messageChronology,
    ) {}

    /**
     * @param  Collection<int, Message>  $messages
     * @return array<string, mixed>
     */
    public function fromMessages(Collection $messages): array
    {
        return array_filter([
            ...$this->snapshotPayload(
                $this->latestMessage($messages->filter(fn (Message $message): bool => $this->isVisibleDialogMessage($message))),
                'last_message',
            ),
            ...$this->snapshotPayload(
                $this->latestMessage($messages->filter(fn (Message $message): bool => $this->isInboundMessage($message))),
                'last_inbound_message',
            ),
            ...$this->snapshotPayload(
                $this->latestMessage($messages->filter(fn (Message $message): bool => $this->isOutboundClientMessage($message))),
                'last_outbound_message',
            ),
        ], fn (mixed $value): bool => $value !== null);
    }

    public function previewText(Message $message): string
    {
        $feed = $this->buildConversationFeedViewDataAction->handle(new Collection([$message]));
        $preview = (string) ($feed[0]['display_text'] ?? '');

        if ($preview === '') {
            $preview = 'Системное сообщение';
        }

        return Str::limit($preview, self::PREVIEW_LIMIT);
    }

    public function isVisibleDialogMessage(Message $message): bool
    {
        return $message->message_kind !== Message::KIND_OUTBOUND_DIALOG_STATUS_CHANGE;
    }

    public function isInboundMessage(Message $message): bool
    {
        return $message->direction === Message::DIRECTION_INBOUND;
    }

    public function isOutboundClientMessage(Message $message): bool
    {
        return $message->direction === Message::DIRECTION_OUTBOUND
            && $message->message_kind !== Message::KIND_OUTBOUND_DIALOG_STATUS_CHANGE;
    }

    /**
     * @param  Collection<int, Message>  $messages
     */
    private function latestMessage(Collection $messages): ?Message
    {
        $message = $messages
            ->sortByDesc(fn (Message $message): string => $this->messageChronology->timestampAndIdSortKey(
                $this->messageChronology->resolveSortAt($message),
                $message->id,
            ))
            ->first();

        return $message instanceof Message ? $message : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotPayload(?Message $message, string $prefix): array
    {
        if (! $message instanceof Message) {
            return [];
        }

        return [
            $prefix.'_id' => $message->id,
            $prefix.'_preview' => $this->previewText($message),
        ];
    }
}
