<?php

namespace App\Services\TelegramAccount;

use App\Models\Channel;
use App\Models\Dialog;
use App\Models\Message;
use App\Models\TelegramAccountOutgoingMessage;
use App\Services\Bots\ChannelActivityLogger;
use App\Services\Dialogs\BuildDialogMessageSnapshotPayloadAction;
use App\Services\Dialogs\MessageChronology;
use Closure;
use Illuminate\Database\Eloquent\Builder;
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
        private readonly ChannelActivityLogger $channelActivityLogger,
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

        $dependentBusinessRecord = $this->findDependentBusinessRecord($duplicate);

        if ($dependentBusinessRecord !== null) {
            $this->logSkippedDueToDependentBusinessRecord(
                $outgoing,
                $canonicalMessage,
                $duplicate,
                $dependentBusinessRecord,
            );

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

    /**
     * @return ?array{table: string, column: string}
     */
    private function findDependentBusinessRecord(Message $message): ?array
    {
        foreach (self::DEPENDENCY_CHECKS as $check) {
            if (
                DB::table($check['table'])
                    ->where($check['column'], $message->id)
                    ->exists()
            ) {
                return $check;
            }
        }

        return null;
    }

    /**
     * @param  array{table: string, column: string}  $dependentBusinessRecord
     */
    private function logSkippedDueToDependentBusinessRecord(
        TelegramAccountOutgoingMessage $outgoing,
        Message $canonicalMessage,
        Message $duplicate,
        array $dependentBusinessRecord,
    ): void {
        $channel = $outgoing->channel;

        if (! $channel instanceof Channel) {
            return;
        }

        $this->channelActivityLogger->warning(
            $channel,
            'telegram_account_gateway.external_outgoing_reconciliation_skipped',
            'External outgoing Telegram account duplicate reconciliation пропущена: найдены зависимые бизнес-записи.',
            [
                'outgoing_message_id' => $outgoing->id,
                'canonical_message_id' => $canonicalMessage->id,
                'duplicate_message_id' => $duplicate->id,
                'external_chat_id' => $outgoing->external_chat_id,
                'sent_external_message_id' => $outgoing->sent_external_message_id,
                'dependency_table' => $dependentBusinessRecord['table'],
                'dependency_column' => $dependentBusinessRecord['column'],
            ],
        );
    }

    private function recalculateDialogMetadata(int $dialogId): void
    {
        $dialog = Dialog::query()
            ->lockForUpdate()
            ->find($dialogId);

        if (! $dialog instanceof Dialog) {
            return;
        }

        $lastVisible = $this->latestDialogMessage(
            $dialog,
            fn (Builder $query): Builder => $this->applyVisibleDialogMessageScope($query),
        );
        $lastInbound = $this->latestDialogMessage(
            $dialog,
            fn (Builder $query): Builder => $query->where('direction', Message::DIRECTION_INBOUND),
        );
        $lastOutbound = $this->latestDialogMessage(
            $dialog,
            fn (Builder $query): Builder => $this->applyOutboundClientMessageScope($query),
        );

        $dialog->forceFill([
            'last_message_at' => $this->resolveMessageSortAt($lastVisible),
            'last_inbound_at' => $this->resolveMessageSortAt($lastInbound),
            'last_outbound_at' => $this->resolveMessageSortAt($lastOutbound),
            ...$this->messageSnapshotPayload($lastVisible, 'last_message'),
            ...$this->messageSnapshotPayload($lastInbound, 'last_inbound_message'),
            ...$this->messageSnapshotPayload($lastOutbound, 'last_outbound_message'),
        ])->save();
    }

    private function latestDialogMessage(Dialog $dialog, Closure $scope): ?Message
    {
        $query = Message::query()
            ->where('dialog_id', $dialog->id);

        $scope($query);

        $message = $this->messageChronology
            ->applyLatestOrder($query)
            ->first();

        return $message instanceof Message ? $message : null;
    }

    private function applyVisibleDialogMessageScope(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query
                ->whereNull('message_kind')
                ->orWhere('message_kind', '!=', Message::KIND_OUTBOUND_DIALOG_STATUS_CHANGE);
        });
    }

    private function applyOutboundClientMessageScope(Builder $query): Builder
    {
        return $this->applyVisibleDialogMessageScope(
            $query
                ->where('direction', Message::DIRECTION_OUTBOUND)
                ->whereNotNull('external_message_id')
                ->where('external_message_id', '!=', ''),
        );
    }

    private function resolveMessageSortAt(?Message $message): mixed
    {
        return $message instanceof Message
            ? $this->messageChronology->resolveSortAt($message)
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function messageSnapshotPayload(?Message $message, string $prefix): array
    {
        if (! $message instanceof Message) {
            return [
                $prefix.'_id' => null,
                $prefix.'_preview' => null,
            ];
        }

        return [
            $prefix.'_id' => $message->id,
            $prefix.'_preview' => $this->buildDialogMessageSnapshotPayloadAction->previewText($message),
        ];
    }
}
