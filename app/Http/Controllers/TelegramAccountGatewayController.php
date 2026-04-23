<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Services\TelegramAccount\NormalizeTelegramAccountInboundMessageEventAction;
use App\Services\TelegramAccount\NormalizeTelegramAccountPeerSyncStateEventAction;
use App\Services\TelegramAccount\NormalizeTelegramAccountRuntimeStateEventAction;
use App\Services\TelegramAccount\StoreTelegramAccountInboundEventAction;
use App\Services\TelegramAccount\StoreTelegramAccountPeerSyncStateEventAction;
use App\Services\TelegramAccount\StoreTelegramAccountRuntimeStateEventAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TelegramAccountGatewayController extends Controller
{
    public function inboundMessage(
        Request $request,
        Channel $channel,
        NormalizeTelegramAccountInboundMessageEventAction $normalizeTelegramAccountInboundMessageEventAction,
        StoreTelegramAccountInboundEventAction $storeTelegramAccountInboundEventAction,
    ): JsonResponse {
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

    public function runtimeState(
        Request $request,
        Channel $channel,
        NormalizeTelegramAccountRuntimeStateEventAction $normalizeTelegramAccountRuntimeStateEventAction,
        StoreTelegramAccountRuntimeStateEventAction $storeTelegramAccountRuntimeStateEventAction,
    ): JsonResponse {
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
}
