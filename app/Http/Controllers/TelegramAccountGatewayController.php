<?php

namespace App\Http\Controllers;

use App\Data\TelegramAccount\NormalizedExternalOutgoingMessageEvent;
use App\Models\Channel;
use App\Models\TelegramAccountOutgoingMessage;
use App\Services\TelegramAccount\ClaimTelegramAccountOutgoingMessageAction;
use App\Services\TelegramAccount\NormalizeTelegramAccountExternalOutgoingMessageEventAction;
use App\Services\TelegramAccount\NormalizeTelegramAccountInboundMessageEventAction;
use App\Services\TelegramAccount\NormalizeTelegramAccountPeerSyncStateEventAction;
use App\Services\TelegramAccount\NormalizeTelegramAccountRuntimeStateEventAction;
use App\Services\TelegramAccount\StoreTelegramAccountExternalOutgoingMessageEventAction;
use App\Services\TelegramAccount\StoreTelegramAccountInboundEventAction;
use App\Services\TelegramAccount\StoreTelegramAccountOutgoingMessageResultAction;
use App\Services\TelegramAccount\StoreTelegramAccountPeerSyncStateEventAction;
use App\Services\TelegramAccount\StoreTelegramAccountRuntimeStateEventAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TelegramAccountGatewayController extends Controller
{
    public function config(Request $request, Channel $channel): JsonResponse
    {
        $this->authorizeGatewayRequest($request, $channel);

        return response()->json([
            'ok' => true,
            'channel_id' => $channel->id,
            'sync_external_outgoing_enabled' => (bool) $channel->sync_external_outgoing_enabled,
            'external_outgoing_backfill_days' => max(1, (int) config('bots.telegram_account.external_outgoing_backfill_days', 7)),
            'external_outgoing_backfill_known_dialogs_only' => (bool) config('bots.telegram_account.external_outgoing_backfill_known_dialogs_only', true),
            'external_outgoing_echo_deferral_seconds' => max(0, (int) config('bots.telegram_account.external_outgoing_echo_deferral_seconds', 15)),
            'external_outgoing_echo_retry_interval_seconds' => max(1, (int) config('bots.telegram_account.external_outgoing_echo_retry_interval_seconds', 1)),
            'external_outgoing_echo_near_time_window_seconds' => max(1, (int) config('bots.telegram_account.external_outgoing_echo_near_time_window_seconds', 120)),
            'config_version' => $channel->updated_at?->getTimestamp() ?? 0,
        ]);
    }

    public function inboundMessage(
        Request $request,
        Channel $channel,
        NormalizeTelegramAccountInboundMessageEventAction $normalizeTelegramAccountInboundMessageEventAction,
        StoreTelegramAccountInboundEventAction $storeTelegramAccountInboundEventAction,
    ): JsonResponse {
        $this->authorizeGatewayRequest($request, $channel);

        $event = $normalizeTelegramAccountInboundMessageEventAction->handle(
            $channel,
            $request->json()->all(),
        );
        $storedResult = $storeTelegramAccountInboundEventAction->handle($channel, $event);

        if ($storedResult === null) {
            return response()->json([
                'ok' => true,
                'stored' => false,
                'skipped' => true,
            ]);
        }

        return response()->json([
            'ok' => true,
            'stored' => true,
            'skipped' => false,
            'message_id' => $storedResult->message->id,
            'dialog_id' => $storedResult->message->dialog_id,
        ]);
    }

    public function externalOutgoingMessage(
        Request $request,
        Channel $channel,
        NormalizeTelegramAccountExternalOutgoingMessageEventAction $normalizeTelegramAccountExternalOutgoingMessageEventAction,
        StoreTelegramAccountExternalOutgoingMessageEventAction $storeTelegramAccountExternalOutgoingMessageEventAction,
    ): JsonResponse {
        $this->authorizeGatewayRequest($request, $channel);

        try {
            $event = $normalizeTelegramAccountExternalOutgoingMessageEventAction->handle(
                $channel,
                $request->json()->all(),
            );
        } catch (ValidationException) {
            return response()->json([
                'ok' => true,
                'stored' => false,
                'skipped' => true,
                'skip_reason' => NormalizedExternalOutgoingMessageEvent::SKIP_INVALID_PAYLOAD,
            ]);
        }

        $storedResult = $storeTelegramAccountExternalOutgoingMessageEventAction->handle($channel, $event);

        return response()->json([
            'ok' => true,
            'stored' => $storedResult->stored,
            'skipped' => $storedResult->skipped,
            'skip_reason' => $storedResult->skipReason,
            'message_id' => $storedResult->message?->id,
            'dialog_id' => $storedResult->message?->dialog_id,
        ]);
    }

    public function runtimeState(
        Request $request,
        Channel $channel,
        NormalizeTelegramAccountRuntimeStateEventAction $normalizeTelegramAccountRuntimeStateEventAction,
        StoreTelegramAccountRuntimeStateEventAction $storeTelegramAccountRuntimeStateEventAction,
    ): JsonResponse {
        $this->authorizeGatewayRequest($request, $channel);

        $event = $normalizeTelegramAccountRuntimeStateEventAction->handle(
            $channel,
            $request->json()->all(),
        );
        $runtimeState = $storeTelegramAccountRuntimeStateEventAction->handle($channel, $event);

        return response()->json([
            'ok' => true,
            'stored' => true,
            'runtime_state_id' => $runtimeState->id,
        ]);
    }

    public function peerSyncState(
        Request $request,
        Channel $channel,
        NormalizeTelegramAccountPeerSyncStateEventAction $normalizeTelegramAccountPeerSyncStateEventAction,
        StoreTelegramAccountPeerSyncStateEventAction $storeTelegramAccountPeerSyncStateEventAction,
    ): JsonResponse {
        $this->authorizeGatewayRequest($request, $channel);

        $event = $normalizeTelegramAccountPeerSyncStateEventAction->handle(
            $channel,
            $request->json()->all(),
        );
        $peerSyncState = $storeTelegramAccountPeerSyncStateEventAction->handle($channel, $event);

        return response()->json([
            'ok' => true,
            'stored' => true,
            'peer_sync_state_id' => $peerSyncState->id,
        ]);
    }

    public function claimOutgoingMessage(
        Request $request,
        Channel $channel,
        ClaimTelegramAccountOutgoingMessageAction $claimTelegramAccountOutgoingMessageAction,
    ): JsonResponse {
        $this->authorizeGatewayRequest($request, $channel);

        $outgoing = $claimTelegramAccountOutgoingMessageAction->handle($channel);

        if (! $outgoing instanceof TelegramAccountOutgoingMessage) {
            return response()->json([
                'ok' => true,
                'has_message' => false,
            ]);
        }

        return response()->json([
            'ok' => true,
            'has_message' => true,
            'outgoing_message' => [
                'id' => $outgoing->id,
                'channel_id' => $outgoing->channel_id,
                'dialog_id' => $outgoing->dialog_id,
                'message_id' => $outgoing->message_id,
                'external_chat_id' => $outgoing->external_chat_id,
                'text' => $outgoing->text,
                'text_format' => $outgoing->text_format,
                'dedupe_key' => $outgoing->dedupe_key,
                'attempts' => $outgoing->attempts,
            ],
        ]);
    }

    public function outgoingMessageResult(
        Request $request,
        Channel $channel,
        TelegramAccountOutgoingMessage $outgoingMessage,
        StoreTelegramAccountOutgoingMessageResultAction $storeTelegramAccountOutgoingMessageResultAction,
    ): JsonResponse {
        $this->authorizeGatewayRequest($request, $channel);
        abort_unless((int) $outgoingMessage->channel_id === (int) $channel->id, 404);

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in([
                TelegramAccountOutgoingMessage::STATUS_SENT,
                TelegramAccountOutgoingMessage::STATUS_FAILED,
            ])],
            'external_message_id' => ['required_if:status,'.TelegramAccountOutgoingMessage::STATUS_SENT, 'nullable', 'string'],
            'error_message' => ['nullable', 'string'],
            'raw_payload' => ['nullable', 'array'],
        ]);

        $stored = $storeTelegramAccountOutgoingMessageResultAction->handle(
            $channel,
            $outgoingMessage,
            $validated,
        );

        return response()->json([
            'ok' => true,
            'stored' => true,
            'outgoing_message_id' => $stored->id,
            'status' => $stored->status,
            'message_id' => $stored->message_id,
        ]);
    }

    private function authorizeGatewayRequest(Request $request, Channel $channel): void
    {
        abort_unless(
            $channel->is_active
                && $channel->platform === Channel::PLATFORM_TELEGRAM
                && $channel->connection_type === Channel::CONNECTION_TYPE_ACCOUNT,
            404,
        );

        $expectedSecret = trim((string) config('bots.telegram_account.gateway_shared_secret', ''));
        $providedSecret = trim((string) $request->bearerToken());

        abort_unless(
            $expectedSecret !== '' && $providedSecret !== '' && hash_equals($expectedSecret, $providedSecret),
            403,
        );
    }
}
