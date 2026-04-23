<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Services\TelegramAccount\NormalizeTelegramAccountInboundMessageEventAction;
use App\Services\TelegramAccount\StoreTelegramAccountInboundEventAction;
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
}
