<?php

namespace App\Services\Bots;

use App\Data\Bots\IncomingBotMessage;
use App\Models\Channel;
use InvalidArgumentException;

class BotIncomingMessageNormalizer
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function normalize(Channel $channel, array $payload): ?IncomingBotMessage
    {
        return match ($channel->platform) {
            Channel::PLATFORM_TELEGRAM => $this->normalizeTelegram($channel, $payload),
            Channel::PLATFORM_MAX => $this->normalizeMax($channel, $payload),
            default => throw new InvalidArgumentException("Unsupported bot platform [{$channel->platform}]."),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function normalizeTelegram(Channel $channel, array $payload): ?IncomingBotMessage
    {
        $message = $payload['message'] ?? null;

        if (! is_array($message) || data_get($message, 'from.is_bot') === true) {
            return null;
        }

        if (data_get($message, 'chat.type') !== 'private') {
            return null;
        }

        $chatId = data_get($message, 'chat.id');
        $userId = data_get($message, 'from.id');

        if (! filled($chatId) || ! filled($userId)) {
            return null;
        }

        return new IncomingBotMessage(
            platform: $channel->platform,
            channelId: $channel->id,
            externalChatId: $chatId,
            externalUserId: $userId,
            text: data_get($message, 'text'),
            rawPayload: $payload,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function normalizeMax(Channel $channel, array $payload): ?IncomingBotMessage
    {
        if (($payload['update_type'] ?? null) !== 'message_created') {
            return null;
        }

        $message = $payload['message'] ?? null;

        if (! is_array($message) || data_get($message, 'sender.is_bot') === true) {
            return null;
        }

        $isDialog = filled($payload['user_locale'])
            || filled(data_get($message, 'recipient.user_id'))
            || ! filled(data_get($message, 'recipient.chat_id'));

        if (! $isDialog) {
            return null;
        }

        $userId = data_get($message, 'sender.user_id');
        $chatId = data_get($message, 'recipient.chat_id');

        if (! filled($userId) && ! filled($chatId)) {
            return null;
        }

        return new IncomingBotMessage(
            platform: $channel->platform,
            channelId: $channel->id,
            externalChatId: $chatId,
            externalUserId: $userId,
            text: data_get($message, 'body.text'),
            rawPayload: $payload,
        );
    }
}
