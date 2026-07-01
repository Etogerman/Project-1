<?php

namespace App\Services\Bots;

use App\Data\Bots\IncomingBotMessageEdit;
use App\Models\Channel;
use App\Models\Message;
use App\Models\MessageRevision;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class StoreInboundMessageEditAction
{
    public function __construct(
        private readonly ChannelActivityLogger $channelActivityLogger,
    ) {}

    public function handle(Channel $channel, IncomingBotMessageEdit $edit): ?Message
    {
        return DB::transaction(function () use ($channel, $edit): ?Message {
            $message = $this->findEditableMessage($channel, $edit);

            if (! $message instanceof Message) {
                $this->channelActivityLogger->warning(
                    $channel,
                    'message_edit.orphaned',
                    'Получено редактирование сообщения, но исходное сообщение не найдено.',
                    [
                        'platform' => $edit->platform,
                        'provider_event_key' => $edit->providerEventKey,
                        'external_chat_id' => $edit->externalChatId,
                        'external_message_id' => $edit->externalMessageId,
                    ],
                );

                return null;
            }

            if ($this->alreadyApplied($message, $edit)) {
                return $message->fresh(['revisions']) ?? $message;
            }

            $previousText = $message->text;
            $previousRichText = $message->rich_text;
            $previousRawPayload = $message->raw_payload;
            $newText = $edit->hasTextContent ? $edit->text : $previousText;
            $newRichText = $edit->hasTextContent
                ? ($edit->richText ?? ($edit->text === $previousText ? $previousRichText : null))
                : $previousRichText;

            try {
                MessageRevision::query()->create([
                    'message_id' => $message->id,
                    'revision_type' => MessageRevision::TYPE_EDIT,
                    'provider_event_key' => $edit->providerEventKey,
                    'provider_edited_at' => $edit->editedAt,
                    'previous_text' => $previousText,
                    'previous_rich_text' => $previousRichText,
                    'previous_raw_payload' => $previousRawPayload,
                    'new_text' => $newText,
                    'new_rich_text' => $newRichText,
                    'new_raw_payload' => $edit->rawPayload,
                ]);
            } catch (QueryException $exception) {
                if (! $this->wasUniqueConstraintViolation($exception)) {
                    throw $exception;
                }

                return $message->fresh(['revisions']) ?? $message;
            }

            $updates = [
                'raw_payload' => $edit->rawPayload,
                'edited_at' => $edit->editedAt,
                'edit_count' => ((int) $message->edit_count) + 1,
                'last_edit_provider_event_key' => $edit->providerEventKey,
            ];

            if ($edit->hasTextContent) {
                $updates['text'] = $edit->text;
                $updates['rich_text'] = $newRichText;
            }

            $message->forceFill($updates)->save();

            $this->channelActivityLogger->info(
                $channel,
                'message_edit.applied',
                'Редактирование входящего сообщения применено.',
                [
                    'platform' => $edit->platform,
                    'message_id' => $message->id,
                    'provider_event_key' => $edit->providerEventKey,
                    'external_chat_id' => $edit->externalChatId,
                    'external_message_id' => $edit->externalMessageId,
                    'edited_at' => $edit->editedAt->toIso8601String(),
                ],
            );

            return $message->fresh(['revisions']) ?? $message;
        });
    }

    private function findEditableMessage(Channel $channel, IncomingBotMessageEdit $edit): ?Message
    {
        return Message::query()
            ->where('channel_id', $channel->id)
            ->where('direction', Message::DIRECTION_INBOUND)
            ->where('external_chat_id', $edit->externalChatId)
            ->where('external_message_id', $edit->externalMessageId)
            ->lockForUpdate()
            ->first();
    }

    private function alreadyApplied(Message $message, IncomingBotMessageEdit $edit): bool
    {
        if (! filled($edit->providerEventKey)) {
            return false;
        }

        if ($message->last_edit_provider_event_key === $edit->providerEventKey) {
            return true;
        }

        return MessageRevision::query()
            ->where('message_id', $message->id)
            ->where('provider_event_key', $edit->providerEventKey)
            ->exists();
    }

    private function wasUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? null;

        return $sqlState === '23000' || $sqlState === '23505';
    }
}
