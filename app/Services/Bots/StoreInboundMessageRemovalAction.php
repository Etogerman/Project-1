<?php

namespace App\Services\Bots;

use App\Data\Bots\IncomingBotMessageRemoval;
use App\Models\Channel;
use App\Models\Message;
use Illuminate\Support\Facades\DB;

class StoreInboundMessageRemovalAction
{
    public function __construct(
        private readonly ChannelActivityLogger $channelActivityLogger,
    ) {}

    public function handle(Channel $channel, IncomingBotMessageRemoval $removal): ?Message
    {
        return DB::transaction(function () use ($channel, $removal): ?Message {
            $message = $this->findRemovableMessage($channel, $removal);

            if (! $message instanceof Message) {
                $this->channelActivityLogger->warning(
                    $channel,
                    'message_remove.orphaned',
                    'Получено удаление сообщения, но исходное сообщение не найдено.',
                    [
                        'platform' => $removal->platform,
                        'provider_event_key' => $removal->providerEventKey,
                        'external_chat_id' => $removal->externalChatId,
                        'external_user_id' => $removal->externalUserId,
                        'external_message_id' => $removal->externalMessageId,
                    ],
                );

                return null;
            }

            if ($message->last_remove_provider_event_key === $removal->providerEventKey) {
                return $message->fresh() ?? $message;
            }

            $message->forceFill([
                'removed_at' => $removal->removedAt,
                'remove_count' => ((int) $message->remove_count) + 1,
                'last_remove_provider_event_key' => $removal->providerEventKey,
                'raw_payload' => $this->mergeRemovalPayload($message, $removal),
            ])->save();

            $this->channelActivityLogger->info(
                $channel,
                'message_remove.applied',
                'Входящее сообщение помечено как удалённое у провайдера.',
                [
                    'platform' => $removal->platform,
                    'message_id' => $message->id,
                    'provider_event_key' => $removal->providerEventKey,
                    'external_chat_id' => $removal->externalChatId,
                    'external_user_id' => $removal->externalUserId,
                    'external_message_id' => $removal->externalMessageId,
                    'removed_at' => $removal->removedAt->toIso8601String(),
                ],
            );

            return $message->fresh() ?? $message;
        });
    }

    private function findRemovableMessage(Channel $channel, IncomingBotMessageRemoval $removal): ?Message
    {
        if (! filled($removal->externalChatId)) {
            return null;
        }

        return Message::query()
            ->where('channel_id', $channel->id)
            ->where('direction', Message::DIRECTION_INBOUND)
            ->where('external_chat_id', $removal->externalChatId)
            ->where('external_message_id', $removal->externalMessageId)
            ->lockForUpdate()
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function mergeRemovalPayload(Message $message, IncomingBotMessageRemoval $removal): array
    {
        $rawPayload = is_array($message->raw_payload) ? $message->raw_payload : [];

        $rawPayload['provider_remove_event'] = [
            'provider_event_key' => $removal->providerEventKey,
            'removed_at' => $removal->removedAt->toIso8601String(),
            'payload' => $removal->rawPayload,
        ];

        return $rawPayload;
    }
}
