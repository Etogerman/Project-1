<?php

namespace App\Services\Contacts;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactStartTag;
use App\Models\Message;

class AssignContactStartTagAction
{
    public function handle(Contact $contact, Message $message, ?Channel $channel = null): ?ContactStartTag
    {
        $channel ??= $message->channel ?? $message->dialog?->channel;

        if (! $channel instanceof Channel) {
            return null;
        }

        $tag = match ($channel->platform) {
            Channel::PLATFORM_TELEGRAM => $this->resolveTelegramStartTag($message),
            Channel::PLATFORM_MAX => $this->resolveMaxStartTag($message),
            default => null,
        };

        if ($tag === null) {
            return null;
        }

        return ContactStartTag::query()->firstOrCreate(
            [
                'contact_id' => $contact->id,
                'category' => $tag['category'],
                'code' => $tag['code'],
            ],
            [
                'source' => $tag['source'],
                'source_message_id' => $message->id,
                'assigned_at' => $message->received_at ?? now(),
            ],
        );
    }

    /**
     * @return array{category:string, code:string, source:string}|null
     */
    protected function resolveTelegramStartTag(Message $message): ?array
    {
        if ($message->direction !== Message::DIRECTION_INBOUND || ! filled($message->text)) {
            return null;
        }

        $normalizedText = trim((string) $message->text);

        if (! preg_match('/^\/start\s+(.+)$/u', $normalizedText, $matches)) {
            return null;
        }

        $payload = $this->normalizePayload($matches[1] ?? null);

        if ($payload === null) {
            return null;
        }

        return [
            'category' => ContactStartTag::CATEGORY_START_PAYLOAD,
            'code' => $payload,
            'source' => ContactStartTag::SOURCE_TELEGRAM_START,
        ];
    }

    /**
     * @return array{category:string, code:string, source:string}|null
     */
    protected function resolveMaxStartTag(Message $message): ?array
    {
        if (
            $message->direction !== Message::DIRECTION_INBOUND
            || data_get($message->raw_payload, 'update_type') !== 'bot_started'
        ) {
            return null;
        }

        $payload = $this->normalizePayload(data_get($message->raw_payload, 'payload'));

        if ($payload === null) {
            return null;
        }

        return [
            'category' => ContactStartTag::CATEGORY_START_PAYLOAD,
            'code' => $payload,
            'source' => ContactStartTag::SOURCE_MAX_START,
        ];
    }

    protected function normalizePayload(mixed $payload): ?string
    {
        if (! is_scalar($payload)) {
            return null;
        }

        $payload = trim((string) $payload);

        return $payload !== '' ? $payload : null;
    }
}
