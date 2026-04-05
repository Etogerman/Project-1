<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessDataCollectionResponseJob;
use App\Models\Channel;
use App\Models\ContactIdentity;
use App\Models\Message;
use App\Models\ScenarioRun;
use App\Services\Bots\BotWebhookRateLimiter;
use App\Services\Bots\BotIncomingMessageNormalizer;
use App\Services\Bots\ChannelActivityLogger;
use App\Services\Bots\DispatchStoredInboundBotMessageAction;
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
        DispatchStoredInboundBotMessageAction $dispatchStoredInboundBotMessageAction,
        ChannelActivityLogger $channelActivityLogger,
        BotWebhookRateLimiter $botWebhookRateLimiter,
        TelegramBotApiService $telegramBotApiService,
    ): JsonResponse {
        return $this->handle(
            request: $request,
            channel: $channel,
            expectedPlatform: Channel::PLATFORM_TELEGRAM,
            botIncomingMessageNormalizer: $botIncomingMessageNormalizer,
            storeInboundMessageAction: $storeInboundMessageAction,
            dispatchStoredInboundBotMessageAction: $dispatchStoredInboundBotMessageAction,
            channelActivityLogger: $channelActivityLogger,
            botWebhookRateLimiter: $botWebhookRateLimiter,
            telegramBotApiService: $telegramBotApiService,
        );
    }

    public function max(
        Request $request,
        Channel $channel,
        BotIncomingMessageNormalizer $botIncomingMessageNormalizer,
        StoreInboundMessageAction $storeInboundMessageAction,
        DispatchStoredInboundBotMessageAction $dispatchStoredInboundBotMessageAction,
        ChannelActivityLogger $channelActivityLogger,
        BotWebhookRateLimiter $botWebhookRateLimiter,
    ): JsonResponse {
        return $this->handle(
            request: $request,
            channel: $channel,
            expectedPlatform: Channel::PLATFORM_MAX,
            botIncomingMessageNormalizer: $botIncomingMessageNormalizer,
            storeInboundMessageAction: $storeInboundMessageAction,
            dispatchStoredInboundBotMessageAction: $dispatchStoredInboundBotMessageAction,
            channelActivityLogger: $channelActivityLogger,
            botWebhookRateLimiter: $botWebhookRateLimiter,
        );
    }

    protected function handle(
        Request $request,
        Channel $channel,
        string $expectedPlatform,
        BotIncomingMessageNormalizer $botIncomingMessageNormalizer,
        StoreInboundMessageAction $storeInboundMessageAction,
        DispatchStoredInboundBotMessageAction $dispatchStoredInboundBotMessageAction,
        ChannelActivityLogger $channelActivityLogger,
        BotWebhookRateLimiter $botWebhookRateLimiter,
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

        $retryAfterSeconds = $botWebhookRateLimiter->check($request, $channel);

        if ($retryAfterSeconds !== null) {
            $channelActivityLogger->warning(
                $channel,
                'webhook.rate_limited',
                'Входящий webhook временно ограничен по частоте запросов.',
                [
                    'platform' => $channel->platform,
                    'channel_id' => $channel->id,
                    'request_ip' => $request->ip(),
                    'route' => $request->route()?->getName() ?? $request->path(),
                    'retry_after_seconds' => $retryAfterSeconds,
                    'max_per_minute' => $botWebhookRateLimiter->resolveMaxPerMinute($channel),
                ],
            );

            return response()
                ->json([
                    'ok' => false,
                    'error' => 'rate_limited',
                ], 429)
                ->header('Retry-After', (string) $retryAfterSeconds);
        }

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
                dispatchStoredInboundBotMessageAction: $dispatchStoredInboundBotMessageAction,
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
            $dispatchStoredInboundBotMessageAction->handle($channel, $storedResult, $deliveryLagSeconds);
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

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handleTelegramCallbackQuery(
        Channel $channel,
        array $payload,
        ?TelegramBotApiService $telegramBotApiService,
        BotIncomingMessageNormalizer $botIncomingMessageNormalizer,
        StoreInboundMessageAction $storeInboundMessageAction,
        DispatchStoredInboundBotMessageAction $dispatchStoredInboundBotMessageAction,
        ChannelActivityLogger $channelActivityLogger,
    ): JsonResponse {
        $callbackQueryId = trim((string) data_get($payload, 'callback_query.id', ''));

        if ($telegramBotApiService instanceof TelegramBotApiService && $callbackQueryId !== '') {
            $telegramBotApiService->answerCallbackQuery($channel, $callbackQueryId);
        }

        $callback = $this->normalizeTelegramDataCollectionCallback($payload);

        if ($callback !== null) {
            if (! $this->isTelegramDataCollectionCallbackActionable($channel, $payload, $callback['field'])) {
                return response()->json([
                    'ok' => true,
                ]);
            }
        } elseif (! $this->isTelegramWarmupScenarioCallbackActionable($channel, $payload)) {
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
            ProcessDataCollectionResponseJob::dispatch(
                $storedMessage->id,
                $storedMessage->contact_id,
                $storedMessage->contact?->data_collection_current_field,
            )->afterCommit();

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

        $dispatchStoredInboundBotMessageAction->handle($channel, $storedResult);

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
    protected function isTelegramWarmupScenarioCallbackActionable(Channel $channel, array $payload): bool
    {
        $callbackData = trim((string) data_get($payload, 'callback_query.data', ''));

        if (! preg_match('/^scenario:warmup:(\d+):([a-z_]+)$/', $callbackData, $matches)) {
            return false;
        }

        $runId = (int) ($matches[1] ?? 0);
        $externalUserId = trim((string) data_get($payload, 'callback_query.from.id', ''));
        $externalChatId = trim((string) data_get($payload, 'callback_query.message.chat.id', ''));

        if ($runId <= 0 || $externalUserId === '' || $externalChatId === '') {
            return false;
        }

        $run = ScenarioRun::query()
            ->with('dialog.currentContactIdentity')
            ->active()
            ->whereKey($runId)
            ->where('scenario_code', 'warmup')
            ->first();

        if (! $run instanceof ScenarioRun || ! $run->dialog) {
            return false;
        }

        return (int) $run->dialog->channel_id === (int) $channel->id
            && (string) ($run->dialog->external_chat_id ?? '') === $externalChatId
            && (string) ($run->dialog->currentContactIdentity?->external_user_id ?? '') === $externalUserId;
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
