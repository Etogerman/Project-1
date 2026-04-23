<?php

namespace App\Services\TelegramAccount;

use App\Data\TelegramAccount\NormalizedRuntimeStateEvent;
use App\Models\Channel;
use App\Models\ChannelRuntimeState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class NormalizeTelegramAccountRuntimeStateEventAction
{
    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws ValidationException
     */
    public function handle(Channel $channel, array $payload): NormalizedRuntimeStateEvent
    {
        $validated = Validator::make($payload, [
            'schema_version' => ['required', 'string'],
            'channel_id' => ['required', 'integer'],
            'platform' => ['required', 'string', 'in:'.Channel::PLATFORM_TELEGRAM],
            'connection_type' => ['required', 'string', 'in:'.Channel::CONNECTION_TYPE_ACCOUNT],
            'auth_status' => ['required', 'string', Rule::in(array_keys(ChannelRuntimeState::authStatusLabels()))],
            'authorization_state' => ['required', 'string', Rule::in(array_keys(ChannelRuntimeState::authorizationStateLabels()))],
            'sync_status' => ['required', 'string', Rule::in(array_keys(ChannelRuntimeState::syncStatusLabels()))],
            'last_gateway_heartbeat_at' => ['sometimes', 'nullable', 'date'],
            'last_sync_started_at' => ['sometimes', 'nullable', 'date'],
            'last_sync_completed_at' => ['sometimes', 'nullable', 'date'],
            'last_error_at' => ['sometimes', 'nullable', 'date'],
            'last_error_message' => ['sometimes', 'nullable', 'string'],
            'runtime_payload' => ['sometimes', 'nullable', 'array'],
        ])->validate();

        if ((int) $validated['channel_id'] !== $channel->id) {
            throw ValidationException::withMessages([
                'channel_id' => 'Payload channel_id does not match route channel.',
            ]);
        }

        return new NormalizedRuntimeStateEvent(
            schemaVersion: (string) $validated['schema_version'],
            channelId: (int) $validated['channel_id'],
            platform: (string) $validated['platform'],
            connectionType: (string) $validated['connection_type'],
            authStatus: (string) $validated['auth_status'],
            authorizationState: (string) $validated['authorization_state'],
            syncStatus: (string) $validated['sync_status'],
            lastGatewayHeartbeatAt: $this->parseNullableCarbon($validated['last_gateway_heartbeat_at'] ?? null),
            lastSyncStartedAt: $this->parseNullableCarbon($validated['last_sync_started_at'] ?? null),
            lastSyncCompletedAt: $this->parseNullableCarbon($validated['last_sync_completed_at'] ?? null),
            lastErrorAt: $this->parseNullableCarbon($validated['last_error_at'] ?? null),
            lastErrorMessage: $this->normalizeNullableString($validated['last_error_message'] ?? null),
            runtimePayload: is_array($validated['runtime_payload'] ?? null) ? $validated['runtime_payload'] : [],
            hasLastGatewayHeartbeatAt: array_key_exists('last_gateway_heartbeat_at', $validated),
            hasLastSyncStartedAt: array_key_exists('last_sync_started_at', $validated),
            hasLastSyncCompletedAt: array_key_exists('last_sync_completed_at', $validated),
            hasLastErrorAt: array_key_exists('last_error_at', $validated),
            hasLastErrorMessage: array_key_exists('last_error_message', $validated),
            hasRuntimePayload: array_key_exists('runtime_payload', $validated),
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
