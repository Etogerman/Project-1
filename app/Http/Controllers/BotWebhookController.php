<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessDataCollectionResponseJob;
use App\Jobs\ProcessAutoReplyJob;
use App\Jobs\ProcessPhoneCaptureFollowUpJob;
use App\Models\Channel;
use App\Models\Message;
use App\Models\ContactIdentity;
use App\Services\Bots\BotIncomingMessageNormalizer;
use App\Services\Bots\ChannelActivityLogger;
use App\Services\Bots\StoreInboundMessageAction;
use App\Services\Bots\TelegramBotApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;

class BotWebhookController extends Controller
{
    public function telegram(
        Request $request,
        Channel $channel,
        BotIncomingMessageNormalizer $botIncomingMessageNormalizer,
        StoreInboundMessageAction $storeInboundMessageAction,
        ChannelActivityLogger $channelActivityLogger,
        TelegramBotApiService $telegramBotApiService,
    ): JsonResponse {
        return $this->handle(
            request: $request,
            channel: $channel,
            expectedPlatform: Channel::PLATFORM_TELEGRAM,
            botIncomingMessageNormalizer: $botIncomingMessageNormalizer,
            storeInboundMessageAction: $storeInboundMessageAction,
            channelActivityLogger: $channelActivityLogger,
            telegramBotApiService: $telegramBotApiService,
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
        ?TelegramBotApiService $telegramBotApiService = null,
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
            'update_type' => $payload['update_type'] ?? $this->telegramUpdateType($payload),
        ]);
        $channelActivityLogger->info(
            $channel,
            'webhook.received',
            'Получен входящий webhook.',
            [
                'platform' => $channel->platform,
                'update_type' => $payload['update_type'] ?? $this->telegramUpdateType($payload),
            ],
        );

        $channel->markWebhookReceived();

        if ($expectedPlatform === Channel::PLATFORM_TELEGRAM && isset($payload['callback_query'])) {
            return $this->handleTelegramCallbackQuery(
                channel: $channel,
                payload: $payload,
                telegramBotApiService: $telegramBotApiService,
                botIncomingMessageNormalizer: $botIncomingMessageNormalizer,
                storeInboundMessageAction: $storeInboundMessageAction,
                channelActivityLogger: $channelActivityLogger,
            );
        }

        $message = $botIncomingMessageNormalizer->normalize($channel, $payload);

        if ($message !== null) {
            $webhookProcessedAt = now();
            $deliveryLagSeconds = $this->resolveWebhookDeliveryLagSeconds($channel, $message->receivedAt, $webhookProcessedAt);

            if ($deliveryLagSeconds !== null) {
                $channelActivityLogger->info(
                    $channel,
                    'webhook.delayed_received',
                    'Webhook из MAX получен с заметной задержкой.',
                    [
                        'platform' => $channel->platform,
                        'provider_event_key' => $message->providerEventKey,
                        'external_message_id' => $message->externalMessageId,
                        'external_user_id' => $message->externalUserId,
                        'external_chat_id' => $message->externalChatId,
                        'message_received_at' => $message->receivedAt->toIso8601String(),
                        'webhook_processed_at' => $webhookProcessedAt->toIso8601String(),
                        'delivery_lag_seconds' => $deliveryLagSeconds,
                    ],
                );
            }

            $storedResult = $storeInboundMessageAction->handle($channel, $message);
            $storedMessage = $storedResult->message;
            $duplicateContext = [
                'platform' => $channel->platform,
                'provider_event_key' => $storedMessage->provider_event_key,
                'message_id' => $storedMessage->id,
                'external_message_id' => $storedMessage->external_message_id,
            ];

            if ($storedMessage->wasRecentlyCreated) {
                $this->logOutOfOrderInboundIfNeeded($channel, $storedMessage, $channelActivityLogger);
            }

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
                if (
                    $deliveryLagSeconds !== null
                    && $storedMessage->wasRecentlyCreated
                    && $storedResult->shouldQueuePhoneCaptureFollowUp()
                ) {
                    $channelActivityLogger->info(
                        $channel,
                        'contact.phone_capture_arrived_late',
                        'Поздний phone share из MAX успешно дошёл до обработки.',
                        [
                            'platform' => $channel->platform,
                            'contact_id' => $storedMessage->contact_id,
                            'message_id' => $storedMessage->id,
                            'provider_event_key' => $storedMessage->provider_event_key,
                            'external_message_id' => $storedMessage->external_message_id,
                            'phone_capture_status' => $storedResult->phoneCaptureStatus,
                            'delivery_lag_seconds' => $deliveryLagSeconds,
                        ],
                    );
                }

                if (! $storedResult->shouldQueuePhoneCaptureFollowUp()) {
                    return response()->json([
                        'ok' => true,
                    ]);
                }

                ProcessPhoneCaptureFollowUpJob::dispatch($storedMessage->id, $storedResult->phoneCaptureStatus)->afterCommit();

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

            $storedMessage->loadMissing('contact');

            if ($storedMessage->contact?->isInDataCollection()) {
                ProcessDataCollectionResponseJob::dispatch($storedMessage->id)->afterCommit();

                $channelActivityLogger->info(
                    $channel,
                    'contact.data_collection_response_queued',
                    'Ответ пользователя поставлен в очередь на обработку сборщиком профиля.',
                    [
                        'platform' => $channel->platform,
                        'message_id' => $storedMessage->id,
                        'contact_id' => $storedMessage->contact_id,
                        'current_field' => $storedMessage->contact?->data_collection_current_field,
                    ],
                );

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

    protected function resolveWebhookDeliveryLagSeconds(Channel $channel, Carbon $messageReceivedAt, Carbon $webhookProcessedAt): ?int
    {
        if ($channel->platform !== Channel::PLATFORM_MAX) {
            return null;
        }

        $lagSeconds = max(0, $webhookProcessedAt->getTimestamp() - $messageReceivedAt->getTimestamp());
        $thresholdSeconds = (int) config('bots.max.delayed_webhook_threshold_seconds', 60);

        return $lagSeconds > $thresholdSeconds ? $lagSeconds : null;
    }

    protected function logOutOfOrderInboundIfNeeded(
        Channel $channel,
        Message $storedMessage,
        ChannelActivityLogger $channelActivityLogger,
    ): void {
        if (
            $channel->platform !== Channel::PLATFORM_MAX
            || $storedMessage->direction !== Message::DIRECTION_INBOUND
            || $storedMessage->received_at === null
            || $storedMessage->contact_id === null
        ) {
            return;
        }

        $newerInbound = Message::query()
            ->where('channel_id', $storedMessage->channel_id)
            ->where('contact_id', $storedMessage->contact_id)
            ->where('direction', Message::DIRECTION_INBOUND)
            ->whereKeyNot($storedMessage->id)
            ->whereNotNull('received_at')
            ->where('received_at', '>', $storedMessage->received_at)
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->first();

        if (! $newerInbound instanceof Message || $newerInbound->received_at === null) {
            return;
        }

        $channelActivityLogger->info(
            $channel,
            'webhook.out_of_order_received',
            'Webhook из MAX получен не по порядку относительно уже сохранённых входящих сообщений.',
            [
                'platform' => $channel->platform,
                'contact_id' => $storedMessage->contact_id,
                'message_id' => $storedMessage->id,
                'provider_event_key' => $storedMessage->provider_event_key,
                'external_message_id' => $storedMessage->external_message_id,
                'received_at' => $storedMessage->received_at->toIso8601String(),
                'newer_inbound_message_id' => $newerInbound->id,
                'newer_inbound_received_at' => $newerInbound->received_at->toIso8601String(),
                'seconds_behind_latest_inbound' => max(
                    0,
                    $newerInbound->received_at->getTimestamp() - $storedMessage->received_at->getTimestamp(),
                ),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handleTelegramCallbackQuery(
        Channel $channel,
        array $payload,
        ?TelegramBotApiService $telegramBotApiService,
        BotIncomingMessageNormalizer $botIncomingMessageNormalizer,
        StoreInboundMessageAction $storeInboundMessageAction,
        ChannelActivityLogger $channelActivityLogger,
    ): JsonResponse {
        $callbackQueryId = trim((string) data_get($payload, 'callback_query.id', ''));

        if ($telegramBotApiService instanceof TelegramBotApiService && $callbackQueryId !== '') {
            $telegramBotApiService->answerCallbackQuery($channel, $callbackQueryId);
        }

        $callback = $this->normalizeTelegramDataCollectionCallback($payload);

        if ($callback === null || ! $this->isTelegramDataCollectionCallbackActionable($channel, $payload, $callback['field'])) {
            return response()->json([
                'ok' => true,
            ]);
        }

        $message = $botIncomingMessageNormalizer->normalize($channel, $payload);

        if ($message === null) {
            return response()->json([
                'ok' => true,
            ]);
        }

        $storedResult = $storeInboundMessageAction->handle($channel, $message);
        $storedMessage = $storedResult->message;

        if ($storedMessage->message_kind !== Message::KIND_INBOUND_USER) {
            return response()->json([
                'ok' => true,
            ]);
        }

        $storedMessage->loadMissing('contact');

        if ($storedMessage->contact?->isInDataCollection()) {
            ProcessDataCollectionResponseJob::dispatch($storedMessage->id)->afterCommit();

            $channelActivityLogger->info(
                $channel,
                'contact.data_collection_response_queued',
                'Ответ пользователя поставлен в очередь на обработку сборщиком профиля.',
                [
                    'platform' => $channel->platform,
                    'message_id' => $storedMessage->id,
                    'contact_id' => $storedMessage->contact_id,
                    'current_field' => $storedMessage->contact?->data_collection_current_field,
                ],
            );
        }

        return response()->json([
            'ok' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function normalizeTelegramDataCollectionCallback(array $payload): ?array
    {
        $data = trim((string) data_get($payload, 'callback_query.data', ''));

        foreach ([
            'age_range:' => \App\Models\Contact::DATA_COLLECTION_FIELD_AGE_RANGE,
            'russian_region_confirm:' => \App\Models\Contact::DATA_COLLECTION_FIELD_RUSSIAN_REGION_CONFIRM,
        ] as $prefix => $field) {
            if (! str_starts_with($data, $prefix)) {
                continue;
            }

            $value = trim(substr($data, strlen($prefix)));

            if ($value === '') {
                return null;
            }

            return [
                'field' => $field,
                'value' => $value,
            ];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function isTelegramDataCollectionCallbackActionable(Channel $channel, array $payload, string $field): bool
    {
        $externalUserId = trim((string) data_get($payload, 'callback_query.from.id', ''));

        if ($externalUserId === '') {
            return false;
        }

        $identity = ContactIdentity::query()
            ->with('contact')
            ->where('channel_id', $channel->id)
            ->where('external_user_id', $externalUserId)
            ->first();

        return $identity?->contact?->isInDataCollection() === true
            && $identity->contact->data_collection_current_field === $field;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function telegramUpdateType(array $payload): ?string
    {
        if (isset($payload['callback_query'])) {
            return 'callback_query';
        }

        return isset($payload['message']) ? 'message' : null;
    }
}
