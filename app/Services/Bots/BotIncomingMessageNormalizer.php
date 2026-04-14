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
        $callbackQuery = $payload['callback_query'] ?? null;

        if (is_array($callbackQuery)) {
            return $this->normalizeTelegramCallbackQuery($channel, $payload, $callbackQuery);
        }

        $chatMemberUpdate = $payload['my_chat_member'] ?? null;

        if (is_array($chatMemberUpdate)) {
            return $this->normalizeTelegramChatMemberUpdate($channel, $payload, $chatMemberUpdate);
        }

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

        $normalizedText = $this->normalizeText(data_get($message, 'text'));

        return new IncomingBotMessage(
            platform: $channel->platform,
            channelId: $channel->id,
            externalChatId: $chatId,
            externalUserId: $userId,
            providerEventKey: $this->normalizeExternalId($payload['update_id'] ?? null),
            externalMessageId: $this->normalizeExternalId(data_get($message, 'message_id')),
            externalUsername: $this->normalizeUsername(data_get($message, 'from.username')),
            contactName: $this->resolvePersonName(data_get($message, 'from')),
            text: $normalizedText,
            inboundKind: filled(data_get($message, 'contact.phone_number'))
                ? IncomingBotMessage::KIND_INBOUND_CONTACT_SHARE
                : IncomingBotMessage::KIND_INBOUND_USER,
            sharedPhoneNumber: $this->normalizeText(data_get($message, 'contact.phone_number')),
            sharedContactUserId: $this->normalizeExternalId(data_get($message, 'contact.user_id')),
            rawPayload: $payload,
            receivedAt: $this->resolveReceivedAt([
                data_get($message, 'date'),
            ]),
            messageParameter: $this->resolveTelegramStartMessageParameter($normalizedText),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $chatMemberUpdate
     */
    protected function normalizeTelegramChatMemberUpdate(Channel $channel, array $payload, array $chatMemberUpdate): ?IncomingBotMessage
    {
        if (data_get($chatMemberUpdate, 'from.is_bot') === true) {
            return null;
        }

        if (data_get($chatMemberUpdate, 'chat.type') !== 'private') {
            return null;
        }

        $systemEventCode = $this->resolveTelegramChatMemberSystemEventCode(
            $this->normalizeText(data_get($chatMemberUpdate, 'old_chat_member.status')),
            $this->normalizeText(data_get($chatMemberUpdate, 'new_chat_member.status')),
        );

        if ($systemEventCode === null) {
            return null;
        }

        $chatId = $this->normalizeExternalId(data_get($chatMemberUpdate, 'chat.id'));
        $userId = $this->normalizeExternalId(data_get($chatMemberUpdate, 'from.id'));

        if (! filled($chatId) || ! filled($userId) || $chatId !== $userId) {
            return null;
        }

        return new IncomingBotMessage(
            platform: $channel->platform,
            channelId: $channel->id,
            externalChatId: $chatId,
            externalUserId: $userId,
            providerEventKey: $this->normalizeExternalId($payload['update_id'] ?? null),
            externalMessageId: null,
            externalUsername: $this->normalizeUsername(data_get($chatMemberUpdate, 'from.username')),
            contactName: $this->resolvePersonName(data_get($chatMemberUpdate, 'from')),
            text: null,
            inboundKind: IncomingBotMessage::KIND_INBOUND_SYSTEM_EVENT,
            sharedPhoneNumber: null,
            sharedContactUserId: null,
            rawPayload: $payload,
            receivedAt: $this->resolveReceivedAt([
                data_get($chatMemberUpdate, 'date'),
            ]),
            systemEventCode: $systemEventCode,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $callbackQuery
     */
    protected function normalizeTelegramCallbackQuery(Channel $channel, array $payload, array $callbackQuery): ?IncomingBotMessage
    {
        if (data_get($callbackQuery, 'from.is_bot') === true) {
            return null;
        }

        if (data_get($callbackQuery, 'message.chat.type') !== 'private') {
            return null;
        }

        $normalizedCallbackData = $this->normalizeTelegramCallbackData(
            $this->normalizeText(data_get($callbackQuery, 'data')),
        );

        if (! filled($normalizedCallbackData)) {
            return null;
        }

        $chatId = $this->normalizeExternalId(data_get($callbackQuery, 'message.chat.id'));
        $userId = $this->normalizeExternalId(data_get($callbackQuery, 'from.id'));

        if (! filled($chatId) || ! filled($userId)) {
            return null;
        }

        return new IncomingBotMessage(
            platform: $channel->platform,
            channelId: $channel->id,
            externalChatId: $chatId,
            externalUserId: $userId,
            providerEventKey: $this->normalizeExternalId($payload['update_id'] ?? null),
            externalMessageId: $this->normalizeExternalId($callbackQuery['id'] ?? null),
            externalUsername: $this->normalizeUsername(data_get($callbackQuery, 'from.username')),
            contactName: $this->resolvePersonName(data_get($callbackQuery, 'from')),
            text: $normalizedCallbackData,
            inboundKind: IncomingBotMessage::KIND_INBOUND_USER,
            sharedPhoneNumber: null,
            sharedContactUserId: null,
            rawPayload: $payload,
            receivedAt: $this->resolveReceivedAt([
                data_get($callbackQuery, 'message.date'),
            ]),
        );
    }

    protected function normalizeTelegramCallbackData(?string $value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        if (str_starts_with($value, 'age_range:')) {
            $normalized = trim(substr($value, strlen('age_range:')));

            return $normalized !== '' ? $normalized : null;
        }

        if (str_starts_with($value, 'russian_region_confirm:')) {
            $normalized = trim(substr($value, strlen('russian_region_confirm:')));

            return $normalized !== '' ? 'russian_region_confirm:'.$normalized : null;
        }

        if (preg_match('/^scenario:warmup:(\d+):([a-z_]+)$/', $value, $matches)) {
            $action = trim((string) ($matches[2] ?? ''));

            return $action !== '' ? 'warmup:'.$action : null;
        }

        if (preg_match('/^scenario:(\d+):([A-Za-z0-9_-]{1,32})$/', $value, $matches)) {
            $key = trim((string) ($matches[2] ?? ''));

            return $key !== '' ? 'scenario:'.$key : null;
        }

        return null;
    }

    protected function resolveTelegramChatMemberSystemEventCode(
        ?string $oldStatus,
        ?string $newStatus,
    ): ?string {
        return match ([$oldStatus, $newStatus]) {
            ['member', 'kicked'] => IncomingBotMessage::SYSTEM_EVENT_BOT_BLOCKED_BY_USER,
            ['kicked', 'member'] => IncomingBotMessage::SYSTEM_EVENT_BOT_UNBLOCKED_BY_USER,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function normalizeMax(Channel $channel, array $payload): ?IncomingBotMessage
    {
        $updateType = (string) ($payload['update_type'] ?? '');

        if ($updateType === 'bot_started') {
            return $this->normalizeMaxBotStarted($channel, $payload);
        }

        if ($updateType !== 'message_created') {
            return null;
        }

        $message = $payload['message'] ?? null;

        if (! is_array($message) || data_get($message, 'sender.is_bot') === true) {
            return null;
        }

        $isDialog = filled($payload['user_locale'] ?? null)
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

        $externalMessageId = $this->resolveMaxMessageId($message);
        $sharedContact = $this->extractMaxSharedContact($message);

        return new IncomingBotMessage(
            platform: $channel->platform,
            channelId: $channel->id,
            externalChatId: $chatId,
            externalUserId: $userId,
            providerEventKey: $externalMessageId,
            externalMessageId: $externalMessageId,
            externalUsername: $this->normalizeUsername(data_get($message, 'sender.username')),
            contactName: $this->resolvePersonName(data_get($message, 'sender')),
            text: $sharedContact['is_contact_share']
                ? null
                : $this->normalizeText(data_get($message, 'body.text')),
            inboundKind: $sharedContact['is_contact_share']
                ? IncomingBotMessage::KIND_INBOUND_CONTACT_SHARE
                : IncomingBotMessage::KIND_INBOUND_USER,
            sharedPhoneNumber: $sharedContact['phone_number'],
            sharedContactUserId: $sharedContact['shared_contact_user_id'],
            rawPayload: $payload,
            receivedAt: $this->resolveReceivedAt([
                data_get($message, 'timestamp'),
                data_get($message, 'created_at'),
                data_get($payload, 'timestamp'),
                data_get($payload, 'created_at'),
            ]),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function normalizeMaxBotStarted(Channel $channel, array $payload): ?IncomingBotMessage
    {
        $chatId = $this->normalizeExternalId($payload['chat_id'] ?? null);
        $userId = $this->normalizeExternalId(data_get($payload, 'user.user_id'));
        $messageParameter = $this->normalizeText($payload['payload'] ?? null);

        if (! filled($chatId) || ! filled($userId)) {
            return null;
        }

        return new IncomingBotMessage(
            platform: $channel->platform,
            channelId: $channel->id,
            externalChatId: $chatId,
            externalUserId: $userId,
            providerEventKey: $this->resolveMaxBotStartedEventKey($payload, $chatId, $userId),
            externalMessageId: null,
            externalUsername: $this->normalizeUsername(data_get($payload, 'user.username')),
            contactName: $this->resolvePersonName(data_get($payload, 'user')),
            text: null,
            inboundKind: IncomingBotMessage::KIND_INBOUND_USER,
            sharedPhoneNumber: null,
            sharedContactUserId: null,
            rawPayload: $payload,
            receivedAt: $this->resolveReceivedAt([
                data_get($payload, 'timestamp'),
                data_get($payload, 'created_at'),
            ]),
            messageParameter: $messageParameter,
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

    protected function resolveTelegramStartMessageParameter(?string $text): ?string
    {
        if (! filled($text)) {
            return null;
        }

        if (! preg_match('/^\/start\s+(.+)$/u', $text, $matches)) {
            return null;
        }

        return $this->normalizeText($matches[1] ?? null);
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
     * @param  array<string, mixed>  $payload
     */
    protected function resolveMaxBotStartedEventKey(array $payload, string $chatId, string $userId): string
    {
        $fingerprint = [
            'chat_id' => $chatId,
            'user_id' => $userId,
            'payload' => $this->normalizeText($payload['payload'] ?? null),
            'timestamp' => $this->normalizeExternalId($payload['timestamp'] ?? null),
            'created_at' => $this->normalizeExternalId($payload['created_at'] ?? null),
        ];

        return 'max-bot-started:'.sha1((string) json_encode($fingerprint, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array{is_contact_share: bool, phone_number: ?string, shared_contact_user_id: ?string}
     */
    protected function extractMaxSharedContact(array $message): array
    {
        $candidateContainers = [];

        foreach ([
            data_get($message, 'contact'),
            data_get($message, 'body.contact'),
        ] as $container) {
            if (is_array($container)) {
                $candidateContainers[] = $container;
            }
        }

        $body = data_get($message, 'body');

        if (
            is_array($body)
            && in_array((string) data_get($body, 'type'), ['contact', 'request_contact', 'shared_contact'], true)
        ) {
            $candidateContainers[] = $body;
        }

        foreach ([
            data_get($message, 'attachments'),
            data_get($message, 'body.attachments'),
        ] as $attachments) {
            if (! is_array($attachments)) {
                continue;
            }

            foreach ($attachments as $attachment) {
                if (! is_array($attachment)) {
                    continue;
                }

                $type = (string) data_get($attachment, 'type');
                $contact = data_get($attachment, 'contact');
                $payloadContact = data_get($attachment, 'payload.contact');

                if (is_array($contact)) {
                    $candidateContainers[] = $contact;
                }

                if (is_array($payloadContact)) {
                    $candidateContainers[] = $payloadContact;
                }

                if (in_array($type, ['contact', 'request_contact', 'shared_contact'], true)) {
                    $candidateContainers[] = $attachment;
                    $candidateContainers[] = (array) data_get($attachment, 'payload', []);
                }
            }
        }

        $phoneNumber = null;
        $sharedContactUserId = null;

        foreach ($candidateContainers as $container) {
            if (! is_array($container)) {
                continue;
            }

            $phoneNumber = $phoneNumber ?? $this->extractPhoneNumberFromMaxContainer($container);

            $sharedContactUserId = $sharedContactUserId ?? $this->extractSharedContactUserIdFromMaxContainer($container);
        }

        return [
            'is_contact_share' => $candidateContainers !== [],
            'phone_number' => $phoneNumber,
            'shared_contact_user_id' => $sharedContactUserId,
        ];
    }

    /**
     * @param  array<string, mixed>  $container
     */
    protected function extractPhoneNumberFromMaxContainer(array $container): ?string
    {
        $phoneNumber = $this->normalizeText(
            data_get($container, 'phone_number')
            ?? data_get($container, 'phone')
            ?? data_get($container, 'number')
            ?? data_get($container, 'contact.phone_number')
            ?? data_get($container, 'contact.phone')
            ?? data_get($container, 'payload.contact.phone_number')
            ?? data_get($container, 'payload.contact.phone')
        );

        if (filled($phoneNumber)) {
            return $phoneNumber;
        }

        $vcfInfo = $this->normalizeText(
            data_get($container, 'vcf_info')
            ?? data_get($container, 'payload.vcf_info')
        );

        if (! filled($vcfInfo)) {
            return null;
        }

        if (! preg_match('/^TEL(?:;[^:\r\n]+)*:([^\r\n]+)/mi', $vcfInfo, $matches)) {
            return null;
        }

        return $this->normalizeText($matches[1] ?? null);
    }

    /**
     * @param  array<string, mixed>  $container
     */
    protected function extractSharedContactUserIdFromMaxContainer(array $container): ?string
    {
        return $this->normalizeExternalId(
            data_get($container, 'user_id')
            ?? data_get($container, 'contact.user_id')
            ?? data_get($container, 'payload.contact.user_id')
            ?? data_get($container, 'max_info.user_id')
            ?? data_get($container, 'payload.max_info.user_id')
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
