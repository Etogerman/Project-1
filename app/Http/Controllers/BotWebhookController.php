<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Services\Bots\BotAutoReplyService;
use App\Services\Bots\BotIncomingMessageNormalizer;
use App\Services\Bots\ChannelActivityLogger;
use App\Services\Bots\StoreInboundMessageAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BotWebhookController extends Controller
{
    public function telegram(
        Request $request,
        Channel $channel,
        BotIncomingMessageNormalizer $botIncomingMessageNormalizer,
        StoreInboundMessageAction $storeInboundMessageAction,
        BotAutoReplyService $botAutoReplyService,
        ChannelActivityLogger $channelActivityLogger,
    ): JsonResponse {
        return $this->handle(
            request: $request,
            channel: $channel,
            expectedPlatform: Channel::PLATFORM_TELEGRAM,
            botIncomingMessageNormalizer: $botIncomingMessageNormalizer,
            storeInboundMessageAction: $storeInboundMessageAction,
            botAutoReplyService: $botAutoReplyService,
            channelActivityLogger: $channelActivityLogger,
        );
    }

    public function max(
        Request $request,
        Channel $channel,
        BotIncomingMessageNormalizer $botIncomingMessageNormalizer,
        StoreInboundMessageAction $storeInboundMessageAction,
        BotAutoReplyService $botAutoReplyService,
        ChannelActivityLogger $channelActivityLogger,
    ): JsonResponse {
        return $this->handle(
            request: $request,
            channel: $channel,
            expectedPlatform: Channel::PLATFORM_MAX,
            botIncomingMessageNormalizer: $botIncomingMessageNormalizer,
            storeInboundMessageAction: $storeInboundMessageAction,
            botAutoReplyService: $botAutoReplyService,
            channelActivityLogger: $channelActivityLogger,
        );
    }

    protected function handle(
        Request $request,
        Channel $channel,
        string $expectedPlatform,
        BotIncomingMessageNormalizer $botIncomingMessageNormalizer,
        StoreInboundMessageAction $storeInboundMessageAction,
        BotAutoReplyService $botAutoReplyService,
        ChannelActivityLogger $channelActivityLogger,
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
        $channelActivityLogger->info(
            $channel,
            'webhook.received',
            'Получен входящий webhook.',
            [
                'platform' => $channel->platform,
                'update_type' => $payload['update_type'] ?? null,
            ],
        );

        $channel->markWebhookReceived();

        $message = $botIncomingMessageNormalizer->normalize($channel, $payload);

        if ($message !== null) {
            $storeInboundMessageAction->handle($channel, $message);

            try {
                $botAutoReplyService->handle($channel, $message);
            } catch (\Throwable $throwable) {
                $channel->markError($throwable);
                $channelActivityLogger->error(
                    $channel,
                    'bot.reply_failed',
                    'Не удалось отправить автоответ.',
                    [
                        'platform' => $channel->platform,
                        'error' => $throwable->getMessage(),
                    ],
                );

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
