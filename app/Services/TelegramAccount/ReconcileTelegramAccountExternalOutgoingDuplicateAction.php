<?php

namespace App\Services\TelegramAccount;

use App\Models\Dialog;
use App\Models\Message;
use App\Models\TelegramAccountOutgoingMessage;
use App\Services\Dialogs\BuildDialogMessageSnapshotPayloadAction;
use App\Services\Dialogs\MessageChronology;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReconcileTelegramAccountExternalOutgoingDuplicateAction
{
    /**
     * @var list<array{table: string, column: string}>
     */
    private const DEPENDENCY_CHECKS = [
        ['table' => 'bitrix24_message_exports', 'column' => 'message_id'],
        ['table' => 'scenario_v3_outbound_messages', 'column' => 'inbound_message_id'],
        ['table' => 'scenario_v3_outbound_messages', 'column' => 'outbound_message_id'],
        ['table' => 'bot_constructor_block_runs', 'column' => 'inbound_message_id'],
        ['table' => 'bot_constructor_block_runs', 'column' => 'outbound_message_id'],
        ['table' => 'bot_constructor_executions', 'column' => 'root_inbound_message_id'],
        ['table' => 'bot_constructor_arrow_runs', 'column' => 'inbound_message_id'],
        ['table' => 'contact_duplicate_reviews', 'column' => 'trigger_message_id'],
        ['table' => 'contact_first_name_resolution_events', 'column' => 'message_id'],
        ['table' => 'geo_resolution_events', 'column' => 'message_id'],
    ];

    public function __construct(
        private readonly BuildDialogMessageSnapshotPayloadAction $buildDialogMessageSnapshotPayloadAction,
        private readonly MessageChronology $messageChronology,
    ) {}

    public function handle(TelegramAccountOutgoingMessage $outgoing): void
    {
        $canonicalMessage = $outgoing->message;
        $sentExternalMessageId = trim((string) $outgoing->sent_external_message_id);

        if (! $canonicalMessage instanceof Message || $sentExternalMessageId === '') {
            return;
        }

        $duplicate = Message::query()
            ->where('channel_id', $outgoing->channel_id)
            ->where('direction', Message::DIRECTION_OUTBOUND)
            ->where('message_kind', Message::KIND_OUTBOUND_EXTERNAL_ACCOUNT_MESSAGE)
            ->where('external_chat_id', $outgoing->external_chat_id)
            ->where('external_message_id', $sentExternalMessageId)
            ->whereKeyNot($canonicalMessage->id)
            ->lockForUpdate()
            ->first();

        if (! $duplicate instanceof Message) {
            return;
        }

        if ($this->hasDependentBusinessRecords($duplicate)) {
            return;
        }

        $dialogIds = collect([$duplicate->dialog_id, $canonicalMessage->dialog_id])
            ->filter(fn (mixed $dialogId): bool => filled($dialogId))
            ->map(fn (mixed $dialogId): int => (int) $dialogId)
            ->unique()
            ->values();

        $duplicate->delete();

        foreach ($dialogIds as $dialogId) {
            $this->recalculateDialogMetadata($dialogId);
        }
    }

    private function hasDependentBusinessRecords(Message $message): bool
    {
        foreach (self::DEPENDENCY_CHECKS as $check) {
            if (
                DB::table($check['table'])
                    ->where($check['column'], $message->id)
                    ->exists()
            ) {
                return true;
            }
        }

        return false;
    }

    private function recalculateDialogMetadata(int $dialogId): void
    {
        $dialog = Dialog::query()
            ->lockForUpdate()
            ->find($dialogId);

        if (! $dialog instanceof Dialog) {
            return;
        }

        /** @var Collection<int, Message> $messages */
        $messages = $dialog->messages()->get();
        $lastVisible = $this->latestMessage($messages->filter(
            fn (Message $message): bool => $this->buildDialogMessageSnapshotPayloadAction->isVisibleDialogMessage($message),
        ));
        $lastInbound = $this->latestMessage($messages->filter(
            fn (Message $message): bool => $this->buildDialogMessageSnapshotPayloadAction->isInboundMessage($message),
        ));
        $lastOutbound = $this->latestMessage($messages->filter(
            fn (Message $message): bool => $this->buildDialogMessageSnapshotPayloadAction->isOutboundClientMessage($message),
        ));

        $dialog->forceFill([
            'last_message_at' => $lastVisible instanceof Message ? $this->messageChronology->resolveSortAt($lastVisible) : null,
            'last_inbound_at' => $lastInbound instanceof Message ? $this->messageChronology->resolveSortAt($lastInbound) : null,
            'last_outbound_at' => $lastOutbound instanceof Message ? $this->messageChronology->resolveSortAt($lastOutbound) : null,
            ...$this->buildDialogMessageSnapshotPayloadAction->fromMessages($messages),
        ])->save();
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
}
