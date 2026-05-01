<?php

namespace App\Services\TelegramAccount;

use App\Data\TelegramAccount\NormalizedInboundMessageEvent;
use App\Models\Channel;
use App\Models\Message;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class NormalizeTelegramAccountInboundMessageEventAction
{
    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws ValidationException
     */
    public function handle(Channel $channel, array $payload): NormalizedInboundMessageEvent
    {
        $validated = Validator::make($payload, [
            'schema_version' => ['required', 'string'],
            'gateway_event_id' => ['required', 'string'],
            'channel_id' => ['required', 'integer'],
            'platform' => ['required', 'string', 'in:'.Channel::PLATFORM_TELEGRAM],
            'connection_type' => ['required', 'string', 'in:'.Channel::CONNECTION_TYPE_ACCOUNT],
            'peer_type' => ['required', 'string'],
            'peer_key' => ['required', 'string'],
            'message_key' => ['required', 'string'],
            'external_chat_id' => ['required', 'string'],
            'external_user_id' => ['required', 'string'],
            'external_message_id' => ['required', 'string'],
            'external_username' => ['nullable', 'string'],
            'contact_name' => ['nullable', 'string'],
            'message_kind' => ['required', 'string'],
            'text' => ['nullable', 'string'],
            'media' => ['nullable', 'array'],
            'media.*' => ['array'],
            'media.*.download_status' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', [
                Message::MEDIA_DOWNLOAD_STATUS_PENDING,
                Message::MEDIA_DOWNLOAD_STATUS_DOWNLOADING,
                Message::MEDIA_DOWNLOAD_STATUS_DOWNLOADED,
                Message::MEDIA_DOWNLOAD_STATUS_FAILED,
            ])],
            'media.*.download_error_code' => ['sometimes', 'nullable', 'string'],
            'media.*.download_error_message' => ['sometimes', 'nullable', 'string'],
            'is_archived' => ['sometimes', 'boolean'],
            'raw_payload' => ['nullable', 'array'],
            'occurred_at' => ['required', 'date'],
            'history_source' => ['required', 'string', 'in:'.implode(',', [
                NormalizedInboundMessageEvent::HISTORY_SOURCE_BACKFILL,
                NormalizedInboundMessageEvent::HISTORY_SOURCE_LIVE,
            ])],
        ])->validate();

        if ((int) $validated['channel_id'] !== $channel->id) {
            throw ValidationException::withMessages([
                'channel_id' => 'Payload channel_id does not match route channel.',
            ]);
        }

        $expectedPeerKey = NormalizedInboundMessageEvent::buildTelegramAccountPeerKey(
            $channel->id,
            (string) $validated['external_chat_id'],
        );
        $expectedMessageKey = NormalizedInboundMessageEvent::buildTelegramAccountMessageKey(
            $channel->id,
            (string) $validated['external_chat_id'],
            (string) $validated['external_message_id'],
        );

        if ((string) $validated['peer_key'] !== $expectedPeerKey) {
            throw ValidationException::withMessages([
                'peer_key' => 'Payload peer_key does not match canonical telegram account peer key.',
            ]);
        }

        if ((string) $validated['message_key'] !== $expectedMessageKey) {
            throw ValidationException::withMessages([
                'message_key' => 'Payload message_key does not match canonical telegram account message key.',
            ]);
        }

        $media = is_array($payload['media'] ?? null)
            ? $this->normalizeMediaItems(array_values($payload['media']))
            : [];
        $text = isset($validated['text']) && is_string($validated['text'])
            ? trim($validated['text'])
            : null;
        $text = $text === '' ? null : $text;

        if ($text === null && $media === []) {
            throw ValidationException::withMessages([
                'text' => 'At least one of text or media must be present.',
            ]);
        }

        return new NormalizedInboundMessageEvent(
            schemaVersion: (string) $validated['schema_version'],
            gatewayEventId: (string) $validated['gateway_event_id'],
            channelId: (int) $validated['channel_id'],
            platform: (string) $validated['platform'],
            connectionType: (string) $validated['connection_type'],
            peerType: (string) $validated['peer_type'],
            peerKey: (string) $validated['peer_key'],
            messageKey: (string) $validated['message_key'],
            externalChatId: (string) $validated['external_chat_id'],
            externalUserId: (string) $validated['external_user_id'],
            externalMessageId: (string) $validated['external_message_id'],
            externalUsername: $this->normalizeNullableString($validated['external_username'] ?? null),
            contactName: $this->normalizeNullableString($validated['contact_name'] ?? null),
            messageKind: (string) $validated['message_kind'],
            text: $text,
            media: $media,
            isArchived: (bool) ($validated['is_archived'] ?? false),
            rawPayload: is_array($validated['raw_payload'] ?? null) ? $validated['raw_payload'] : [],
            occurredAt: Carbon::parse((string) $validated['occurred_at']),
            historySource: (string) $validated['history_source'],
        );
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param  list<array<string, mixed>>  $media
     * @return list<array<string, mixed>>
     */
    private function normalizeMediaItems(array $media): array
    {
        $normalized = [];

        foreach ($media as $item) {
            if (! is_array($item)) {
                continue;
            }

            $payload = $item;
            $payload['download_status'] = Message::normalizeMediaDownloadStatus(
                data_get($item, 'download_status')
            ) ?? Message::MEDIA_DOWNLOAD_STATUS_PENDING;

            $downloadErrorCode = $this->normalizeNullableString(data_get($item, 'download_error_code'));
            $downloadErrorMessage = $this->normalizeNullableString(data_get($item, 'download_error_message'));

            if ($downloadErrorCode !== null) {
                $payload['download_error_code'] = $downloadErrorCode;
            } else {
                unset($payload['download_error_code']);
            }

            if ($downloadErrorMessage !== null) {
                $payload['download_error_message'] = $downloadErrorMessage;
            } else {
                unset($payload['download_error_message']);
            }

            $normalized[] = $payload;
        }

        return $normalized;
    }
}
