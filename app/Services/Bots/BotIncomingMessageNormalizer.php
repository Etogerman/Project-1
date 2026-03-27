<?php

namespace App\Services\Bots;

use App\Data\Bots\IncomingBotMessage;
use App\Models\Channel;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Throwable;

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

        $chatId = $this->normalizeExternalId(data_get($message, 'chat.id'));
        $userId = $this->normalizeExternalId(data_get($message, 'from.id'));

        if (! filled($chatId) || ! filled($userId)) {
            return null;
        }

        return new IncomingBotMessage(
            platform: $channel->platform,
            channelId: $channel->id,
            externalChatId: $chatId,
            externalUserId: $userId,
            externalMessageId: $this->normalizeExternalId(data_get($message, 'message_id')),
            externalUsername: $this->normalizeUsername(data_get($message, 'from.username')),
            contactName: $this->resolvePersonName(data_get($message, 'from')),
            text: $this->normalizeText(data_get($message, 'text')),
            rawPayload: $payload,
            receivedAt: $this->resolveReceivedAt([
                data_get($message, 'date'),
            ]),
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

        $userId = $this->normalizeExternalId(data_get($message, 'sender.user_id'));
        $chatId = $this->normalizeExternalId(data_get($message, 'recipient.chat_id'));

        if (! filled($userId) || ! filled($chatId)) {
            return null;
        }

        return new IncomingBotMessage(
            platform: $channel->platform,
            channelId: $channel->id,
            externalChatId: $chatId,
            externalUserId: $userId,
            externalMessageId: $this->resolveMaxMessageId($message),
            externalUsername: $this->normalizeUsername(data_get($message, 'sender.username')),
            contactName: $this->resolvePersonName(data_get($message, 'sender')),
            text: $this->normalizeText(data_get($message, 'body.text')),
            rawPayload: $payload,
            receivedAt: $this->resolveReceivedAt([
                data_get($message, 'timestamp'),
                data_get($message, 'created_at'),
                data_get($payload, 'timestamp'),
                data_get($payload, 'created_at'),
            ]),
        );
    }

    protected function normalizeExternalId(mixed $value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        return trim((string) $value);
    }

    protected function normalizeUsername(mixed $value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        $username = ltrim(trim((string) $value), '@');

        return $username !== '' ? $username : null;
    }

    protected function normalizeText(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }

    /**
     * @param  array<string, mixed>|mixed  $person
     */
    protected function resolvePersonName(mixed $person): ?string
    {
        if (! is_array($person)) {
            return null;
        }

        $name = trim(implode(' ', array_filter([
            $this->normalizeText(data_get($person, 'first_name')),
            $this->normalizeText(data_get($person, 'last_name')),
        ], fn (?string $value): bool => filled($value))));

        if ($name !== '') {
            return $name;
        }

        return $this->normalizeText(data_get($person, 'name'));
    }

    /**
     * @param  array<string, mixed>  $message
     */
    protected function resolveMaxMessageId(array $message): ?string
    {
        return $this->normalizeExternalId(
            data_get($message, 'body.mid')
                ?? data_get($message, 'message_id')
                ?? data_get($message, 'id')
        );
    }

    /**
     * @param  array<int, mixed>  $candidates
     */
    protected function resolveReceivedAt(array $candidates): Carbon
    {
        foreach ($candidates as $candidate) {
            if (! filled($candidate)) {
                continue;
            }

            if (is_numeric($candidate)) {
                return $this->createDateTimeFromNumericTimestamp($candidate);
            }

            try {
                return Carbon::parse((string) $candidate);
            } catch (Throwable) {
                continue;
            }
        }

        return now();
    }

    protected function createDateTimeFromNumericTimestamp(int|float|string $value): Carbon
    {
        $timestamp = (string) $value;
        $timestamp = ltrim(trim($timestamp), '+');
        $absoluteTimestamp = ltrim($timestamp, '-');

        return match (strlen($absoluteTimestamp)) {
            16 => Carbon::createFromTimestampUTC((float) $timestamp / 1_000_000),
            13 => Carbon::createFromTimestampMsUTC((int) $timestamp),
            default => Carbon::createFromTimestampUTC((int) $timestamp),
        };
    }
}
