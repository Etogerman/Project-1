<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Services\Bots\BotAutoReplyService;
use App\Services\Bots\BotIncomingMessageNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BotWebhookController extends Controller
{
    public function telegram(
        Request $request,
        Channel $channel,
        BotIncomingMessageNormalizer $botIncomingMessageNormalizer,
        BotAutoReplyService $botAutoReplyService,
    ): JsonResponse {
        return $this->handle(
            request: $request,
            channel: $channel,
            expectedPlatform: Channel::PLATFORM_TELEGRAM,
            botIncomingMessageNormalizer: $botIncomingMessageNormalizer,
            botAutoReplyService: $botAutoReplyService,
        );
    }

    public function max(
        Request $request,
        Channel $channel,
        BotIncomingMessageNormalizer $botIncomingMessageNormalizer,
        BotAutoReplyService $botAutoReplyService,
    ): JsonResponse {
        return $this->handle(
            request: $request,
            channel: $channel,
            expectedPlatform: Channel::PLATFORM_MAX,
            botIncomingMessageNormalizer: $botIncomingMessageNormalizer,
            botAutoReplyService: $botAutoReplyService,
        );
    }

    protected function handle(
        Request $request,
        Channel $channel,
        string $expectedPlatform,
        BotIncomingMessageNormalizer $botIncomingMessageNormalizer,
        BotAutoReplyService $botAutoReplyService,
    ): JsonResponse {
        abort_unless(
            $channel->is_active
                && $channel->connection_type === Channel::CONNECTION_TYPE_BOT
                && $channel->platform === $expectedPlatform,
            404,
        );

        $secretHeaderName = (string) config("bots.{$expectedPlatform}.webhook_secret_header");
        $expectedSecret = $channel->getWebhookSecret();
        $providedSecret = (string) $request->header($secretHeaderName, '');

        abort_unless(
            filled($expectedSecret) && filled($providedSecret) && hash_equals($expectedSecret, $providedSecret),
            403,
        );

        $payload = $request->json()->all();

        Log::info('bot webhook received', [
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'update_type' => $payload['update_type'] ?? null,
        ]);

        $channel->markWebhookReceived();

        $message = $botIncomingMessageNormalizer->normalize($channel, $payload);

        if ($message !== null) {
            try {
                $botAutoReplyService->handle($channel, $message);
            } catch (\Throwable $throwable) {
                $channel->markError($throwable);

                Log::error('bot auto reply failed', [
                    'channel_id' => $channel->id,
                    'platform' => $channel->platform,
                    'error' => $throwable->getMessage(),
                ]);

                throw $throwable;
            }
        }

        return response()->json([
            'ok' => true,
        ]);
    }
}
