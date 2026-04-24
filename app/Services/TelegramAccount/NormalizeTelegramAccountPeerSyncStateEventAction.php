<?php

namespace App\Services\TelegramAccount;

use App\Data\TelegramAccount\NormalizedPeerSyncStateEvent;
use App\Models\Channel;
use App\Models\ChannelPeerSyncState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class NormalizeTelegramAccountPeerSyncStateEventAction
{
    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws ValidationException
     */
    public function handle(Channel $channel, array $payload): NormalizedPeerSyncStateEvent
    {
        $validated = Validator::make($payload, [
            'schema_version' => ['required', 'string'],
            'channel_id' => ['required', 'integer'],
            'platform' => ['required', 'string', 'in:'.Channel::PLATFORM_TELEGRAM],
            'connection_type' => ['required', 'string', 'in:'.Channel::CONNECTION_TYPE_ACCOUNT],
            'peer_key' => ['required', 'string'],
            'external_chat_id' => ['required', 'string'],
            'backfill_status' => ['required', 'string', Rule::in([
                ChannelPeerSyncState::BACKFILL_STATUS_NOT_STARTED,
                ChannelPeerSyncState::BACKFILL_STATUS_IN_PROGRESS,
                ChannelPeerSyncState::BACKFILL_STATUS_COMPLETE,
                ChannelPeerSyncState::BACKFILL_STATUS_FAILED,
            ])],
            'oldest_imported_message_id' => ['sometimes', 'nullable', 'string'],
            'latest_observed_message_id' => ['sometimes', 'nullable', 'string'],
            'history_complete_at' => ['sometimes', 'nullable', 'date'],
            'last_sync_error' => ['sometimes', 'nullable', 'string'],
        ])->after(function ($validator) use ($channel, $payload): void {
            $externalChatId = $payload['external_chat_id'] ?? null;
            $peerKey = $payload['peer_key'] ?? null;
            $backfillStatus = $payload['backfill_status'] ?? null;
            $historyCompleteAt = $payload['history_complete_at'] ?? null;

            if ((int) ($payload['channel_id'] ?? 0) !== $channel->id) {
                $validator->errors()->add('channel_id', 'Payload channel_id does not match route channel.');
            }

            if (
                is_string($externalChatId)
                && is_string($peerKey)
                && $peerKey !== ChannelPeerSyncState::buildTelegramAccountPeerKey($channel->id, $externalChatId)
            ) {
                $validator->errors()->add('peer_key', 'Payload peer_key does not match canonical telegram account peer key.');
            }

            if (
                $backfillStatus === ChannelPeerSyncState::BACKFILL_STATUS_COMPLETE
                && ($historyCompleteAt === null || $historyCompleteAt === '')
            ) {
                $validator->errors()->add('history_complete_at', 'history_complete_at is required when backfill_status is complete.');
            }
        })->validate();

        return new NormalizedPeerSyncStateEvent(
            schemaVersion: (string) $validated['schema_version'],
            channelId: (int) $validated['channel_id'],
            platform: (string) $validated['platform'],
            connectionType: (string) $validated['connection_type'],
            peerKey: (string) $validated['peer_key'],
            externalChatId: (string) $validated['external_chat_id'],
            backfillStatus: (string) $validated['backfill_status'],
            oldestImportedMessageId: $this->normalizeNullableString($validated['oldest_imported_message_id'] ?? null),
            latestObservedMessageId: $this->normalizeNullableString($validated['latest_observed_message_id'] ?? null),
            historyCompleteAt: $this->parseNullableCarbon($validated['history_complete_at'] ?? null),
            lastSyncError: $this->normalizeNullableString($validated['last_sync_error'] ?? null),
            hasOldestImportedMessageId: array_key_exists('oldest_imported_message_id', $validated),
            hasLatestObservedMessageId: array_key_exists('latest_observed_message_id', $validated),
            hasHistoryCompleteAt: array_key_exists('history_complete_at', $validated),
            hasLastSyncError: array_key_exists('last_sync_error', $validated),
        );
    }

    private function parseNullableCarbon(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse((string) $value);
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
