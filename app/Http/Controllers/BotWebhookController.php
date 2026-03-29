<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessAutoReplyJob;
use App\Jobs\ProcessPhoneCaptureFollowUpJob;
use App\Models\Channel;
use App\Models\Message;
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
        ChannelActivityLogger $channelActivityLogger,
    ): JsonResponse {
        return $this->handle(
            request: $request,
            channel: $channel,
            expectedPlatform: Channel::PLATFORM_TELEGRAM,
            botIncomingMessageNormalizer: $botIncomingMessageNormalizer,
            storeInboundMessageAction: $storeInboundMessageAction,
            channelActivityLogger: $channelActivityLogger,
        );
    }

    public function max(
        Request $request,
        Channel $channel,
        BotIncomingMessageNormalizer $botIncomingMessageNormalizer,
        StoreInboundMessageAction $storeInboundMessageAction,
        ChannelActivityLogger $channelActivityLogger,
    ): JsonResponse {
        return $this->handle(
            request: $request,
            channel: $channel,
            expectedPlatform: Channel::PLATFORM_MAX,
            botIncomingMessageNormalizer: $botIncomingMessageNormalizer,
            storeInboundMessageAction: $storeInboundMessageAction,
            channelActivityLogger: $channelActivityLogger,
        );
    }

    protected function handle(
        Request $request,
        Channel $channel,
        string $expectedPlatform,
        BotIncomingMessageNormalizer $botIncomingMessageNormalizer,
        StoreInboundMessageAction $storeInboundMessageAction,
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
            $storedResult = $storeInboundMessageAction->handle($channel, $message);
            $storedMessage = $storedResult->message;
            $duplicateContext = [
                'platform' => $channel->platform,
                'provider_event_key' => $storedMessage->provider_event_key,
                'message_id' => $storedMessage->id,
                'external_message_id' => $storedMessage->external_message_id,
            ];

            if ($storedMessage->hasSuccessfulAutoReply()) {
                if (! $storedMessage->wasRecentlyCreated) {
                    $channelActivityLogger->info(
                        $channel,
                        'webhook.duplicate_ignored',
                        'Повторный webhook обработан без повторной отправки ответа.',
                        $duplicateContext,
                    );
                }

                return response()->json([
                    'ok' => true,
                ]);
            }

            if ($storedMessage->message_kind === Message::KIND_INBOUND_CONTACT_SHARE) {
                if (! $storedResult->shouldQueuePhoneCaptureFollowUp()) {
                    return response()->json([
                        'ok' => true,
                    ]);
                }

                ProcessPhoneCaptureFollowUpJob::dispatch($storedMessage->id)->afterCommit();

                $channelActivityLogger->info(
                    $channel,
                    'contact.phone_capture_confirmation_queued',
                    'Подтверждение после получения номера поставлено в очередь.',
                    [
                        'platform' => $channel->platform,
                        'contact_id' => $storedMessage->contact_id,
                        'message_id' => $storedMessage->id,
                        'button_type' => 'request_phone',
                        'phone_capture_status' => $storedResult->phoneCaptureStatus,
                    ],
                );

                return response()->json([
                    'ok' => true,
                ]);
            }

            if ($storedMessage->message_kind !== Message::KIND_INBOUND_USER) {
                return response()->json([
                    'ok' => true,
                ]);
            }

            if (! $storedMessage->wasRecentlyCreated) {
                $channelActivityLogger->info(
                    $channel,
                    'webhook.duplicate_retry_reply',
                    'Повторный webhook поставил автоответ в очередь повторно.',
                    $duplicateContext,
                );
            }

            ProcessAutoReplyJob::dispatch($storedMessage->id)->afterCommit();

            $channelActivityLogger->info(
                $channel,
                'bot.reply_queued',
                'Автоответ поставлен в очередь.',
                [
                    'platform' => $channel->platform,
                    'message_id' => $storedMessage->id,
                    'provider_event_key' => $storedMessage->provider_event_key,
                    'external_message_id' => $storedMessage->external_message_id,
                    'auto_reply_mode' => $channel->auto_reply_mode ?? Channel::AUTO_REPLY_MODE_RULES_ONLY,
                ],
            );
        }

        return response()->json([
            'ok' => true,
        ]);
    }
}
